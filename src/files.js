/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Files-app integration for grafana_sync.
 *
 *  - Registers an "Open in Grafana" file action, promoted to DEFAULT for
 *    `application/grafana+json` so a row click opens the dashboard in Grafana
 *    instead of the Text editor. Plain JSON files are unaffected (gated by mime).
 *  - The deep-link uid is the `grafana_uid` Files-Metadata, exposed over WebDAV.
 *  - "Open with text editor" edits the raw JSON for every mode that holds it.
 *  - A "Grafana dashboard" entry in the + New menu.
 *
 * Getting the uid to the click handler — two tiers, no custom endpoint:
 *   1. PRIMARY (zero extra calls): registerDavProperty() adds `metadata-grafana_uid`
 *      to the Files app's directory PROPFIND, so it rides the listing and lands
 *      on `node.attributes`. Works for every navigation.
 *   2. FALLBACK (one call, rare): on the very first folder after a full page
 *      load, our script registers a beat after core's first PROPFIND, so that
 *      listing misses the prop. When that happens we do a targeted single-node
 *      PROPFIND via the built-in @nextcloud/files WebDAV client requesting just
 *      our prop. No bespoke controller/route — same authenticated DAV core uses.
 */
import { registerFileAction, addNewFileMenuEntry, getUniqueName, DefaultType, NewMenuEntryCategory } from '@nextcloud/files'
import { registerDavProperty, getDefaultPropfind, getClient, getRootPath, resultToNode } from '@nextcloud/files/dav'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { emit } from '@nextcloud/event-bus'
import { getGrafanaUid, buildUrl, isDashboardFile, getGrafanaMode, canOpenInGrafana, canEditAsText } from './files-helpers.js'
// Glyphs live as real SVG files under img/icons/ (the single source of truth for
// the app's MENU marks); Vite inlines them at build time via ?raw, so nothing is
// hand-pasted here. The Grafana-branded entries ("Open in Grafana", "New →
// Grafana dashboard") use the app's Grafana mark; "Open with text editor" a pencil.
//
// Every glyph here is `fill="currentColor"`, because Nextcloud themes menu icons
// to the surface they land on. That is why the Grafana mark is imported from
// img/icons/ and NOT from img/grafana.svg: the latter is the FILETYPE icon, which
// hardcodes its fills because NC renders mimetype icons out of
// core/img/filetypes/ without recolouring them. Two treatments of one mark, two
// files — same arrangement as nextcloud-n8n.
import grafanaMarkIcon from '../img/icons/grafana.svg?raw'
import textIcon from '../img/icons/text.svg?raw'

const APP_ID = 'grafana_sync'

// Register our metadata keys as DAV properties so they ride the directory PROPFIND
// (writes to the shared _nc_files_scope.v4_0 store core's PROPFIND reads). `nc` is
// a default namespace, so the bare prefixed name is enough.
registerDavProperty('nc:metadata-grafana_uid')
// Also ride the mode on the listing so the openers know sync vs link (it decides
// which action — "Open in Grafana" vs "Open with text editor" — is the default click).
registerDavProperty('nc:metadata-grafana_mode')

// Base URL of the Grafana instance (server-rendered initial state). Empty until the
// admin sets it — we hide the action in that case.
const grafanaUrl = (() => {
  try {
    return String(loadState(APP_ID, 'grafana_url') || '').replace(/\/+$/, '')
  } catch {
    return ''
  }
})()

/**
 * Fallback for the first-load race: ask the built-in WebDAV endpoint for this
 * node's metadata. getDefaultPropfind() includes our registered props, so a
 * single-node stat returns both `metadata-grafana_uid` and `metadata-grafana_mode`.
 *
 * Returns the raw props bag rather than one value, because both callers below
 * need a different key out of the same request.
 */
async function propfindProps(node) {
  if (!node?.path) return {}
  try {
    const res = await getClient().stat(getRootPath() + node.path, {
      details: true,
      data: getDefaultPropfind(),
    })
    return res?.data?.props ?? {}
  } catch (e) {
    console.warn('[grafana_sync] metadata PROPFIND failed', e)
    return {}
  }
}

/** Node → Grafana deep link: node attributes first (free), else a one-shot PROPFIND. */
async function resolveUrl(node) {
  return buildUrl(grafanaUrl, getGrafanaUid(node))
    || buildUrl(grafanaUrl, getGrafanaUid({ attributes: await propfindProps(node) }))
}

/**
 * The file's mode, for real — the listing value when it rode along, else one
 * PROPFIND.
 *
 * WHY AN ASYNC MODE EXISTS AT ALL. `enabled()` is synchronous, so it can only
 * ever see what the listing carried, and on the first folder after a page load
 * that is nothing (the race documented at the top of this file). The editor's
 * unknown-mode default is deliberately PERMISSIVE, so that `unmapped` files —
 * whose only opener is the text editor — are never left with no way to
 * open at all. The cost of that choice is that a `link` can slip into the menu
 * for exactly one folder per session, and editing a link is meaningless: the
 * server refuses to push it (NodeWrittenListener) and the next pull overwrites
 * whatever was typed.
 *
 * So the menu stays permissive and the ACTION is what resolves the truth. By
 * exec() time we can afford the round trip, and a link ends up where a link
 * should: opened in Grafana.
 */
async function resolveMode(node) {
  return getGrafanaMode(node) || getGrafanaMode({ attributes: await propfindProps(node) })
}

// ── "Edit as text" — a plain-text source editor in a modal ─────────────────
// We deliberately do NOT use Text's createEditor(): that's a Markdown rich-text
// editor and it reflows JSON (drops indentation, can corrupt it). For dashboard
// JSON we want a verbatim source view, so we load/save through the built-in
// WebDAV client into a monospace textarea. Saving fires NodeWrittenEvent → the
// normal push path.
let stylesInjected = false
function injectStyles() {
  if (stylesInjected) return
  stylesInjected = true
  const el = document.createElement('style')
  el.textContent = `
.grafana-sync-text-overlay{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.4)}
.grafana-sync-text-dialog{display:flex;flex-direction:column;width:min(92vw,1000px);height:min(92vh,820px);background:var(--color-main-background);border-radius:var(--border-radius-large,12px);box-shadow:0 0 30px rgba(0,0,0,.4);overflow:hidden}
.grafana-sync-text-bar{display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid var(--color-border)}
.grafana-sync-text-title{flex:1;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.grafana-sync-text-status{color:var(--color-text-maxcontrast);font-size:.9em}
.grafana-sync-text-area{flex:1;width:100%;box-sizing:border-box;border:none;resize:none;padding:12px 14px;font-family:var(--font-face-monospace,monospace);font-size:13px;line-height:1.5;tab-size:2;white-space:pre;overflow:auto;background:var(--color-main-background);color:var(--color-main-text)}
.grafana-sync-text-area:focus{outline:none}`
  document.head.appendChild(el)
}

async function openInText(node) {
  injectStyles()
  const path = getRootPath() + node.path
  const client = getClient()

  // Static markup only — all human-readable text (title, translated button labels) is set
  // via textContent below, never interpolated into innerHTML, so a translation string can
  // never inject markup.
  const overlay = document.createElement('div')
  overlay.className = 'grafana-sync-text-overlay'
  overlay.innerHTML =
    '<div class="grafana-sync-text-dialog">'
    + '<div class="grafana-sync-text-bar">'
    +   '<span class="grafana-sync-text-title"></span>'
    +   '<span class="grafana-sync-text-status"></span>'
    +   '<button type="button" class="button primary js-save"></button>'
    +   '<button type="button" class="button js-close"></button>'
    + '</div>'
    + '<textarea class="grafana-sync-text-area" spellcheck="false" wrap="off"></textarea>'
    + '</div>'
  document.body.appendChild(overlay)

  const sel = (s) => overlay.querySelector(s)
  sel('.grafana-sync-text-title').textContent = node.basename || 'dashboard.grafana'
  sel('.js-save').textContent = t(APP_ID, 'Save')
  sel('.js-close').textContent = t(APP_ID, 'Close')
  const ta = sel('.grafana-sync-text-area')
  const setStatus = (m) => { sel('.grafana-sync-text-status').textContent = m }

  const close = () => { document.removeEventListener('keydown', onKey); overlay.remove() }
  const save = async () => {
    setStatus(t(APP_ID, 'Saving…'))
    try {
      await client.putFileContents(path, ta.value, { overwrite: true })
      setStatus(t(APP_ID, 'Saved'))
    } catch (e) {
      console.error('[grafana_sync] save failed', e)
      setStatus(t(APP_ID, 'Save failed'))
    }
  }
  const onKey = (e) => {
    if (e.key === 'Escape') { close() } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save() }
  }
  sel('.js-close').addEventListener('click', close)
  sel('.js-save').addEventListener('click', save)
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close() })
  document.addEventListener('keydown', onKey)

  setStatus(t(APP_ID, 'Loading…'))
  try {
    ta.value = await client.getFileContents(path, { format: 'text' })
    setStatus('')
    ta.focus()
  } catch (e) {
    console.error('[grafana_sync] could not load file', e)
    setStatus(t(APP_ID, 'Could not load file'))
  }
  return true
}

// @nextcloud/files v4: registerFileAction takes a plain IFileAction object.
// enabled()/exec() receive a single context `{ nodes, view, folder, contents }`.
registerFileAction({
  id: 'grafana_sync.open',
  displayName: () => t(APP_ID, 'Open in Grafana'),
  iconSvgInline: () => grafanaMarkIcon,

  // Offered for sync/link (a live dashboard to open); HIDDEN for unmapped
  // (deleted / never created — nothing live to jump to) and when no Grafana base URL is
  // configured (there's nowhere to jump — so we hide it rather than show a no-op click).
  // The opener set follows the file's MODE, not its type (open-with.feature / saga §14.1).
  // enabled() also keeps it off plain JSON via isDashboardFile.
  enabled: (context) => !!grafanaUrl && isDashboardFile(context) && canOpenInGrafana(getGrafanaMode(context?.nodes?.[0])),

  async exec(context) {
    const url = await resolveUrl(context?.nodes?.[0])
    if (!url) return null
    window.open(url, '_blank', 'noopener,noreferrer')
    return true
  },

  // Default click for sync/link; for unmapped this action is disabled, so
  // the lower-priority "Open with text editor" default wins instead (see below).
  default: DefaultType.DEFAULT,
  order: -50, // above other JSON claimers (Text ~0) and above the text opener
})

// "Open with text editor" — edit the raw JSON. Offered for every mode that holds
// the full dashboard on disk (sync / unmapped), and the DEFAULT click for
// unmapped (no live dashboard to open). HIDDEN for `link`: a link is only a
// pointer, so there is nothing to edit and any change would break it. To edit a
// link's dashboard you change its folder mapping to sync — the mapping's mode is the
// single source of truth, there is no per-file toggle (saga §15.3).
// It is also marked DEFAULT, but at a *lower* priority (order -49) than "Open in
// Grafana" (-50): for sync both are enabled and Grafana wins; for unmapped
// "Open in Grafana" is disabled, so this becomes the default click; for link this
// action is disabled and Grafana is the only opener. (open-with.feature)
registerFileAction({
  id: 'grafana_sync.edit',
  displayName: () => t(APP_ID, 'Open with text editor'),
  iconSvgInline: () => textIcon,
  // Offered for any dashboard file that holds editable JSON (sync/unmapped,
  // and the permissive loading case); hidden for `link` (a pointer — nothing to edit).
  enabled: (context) => isDashboardFile(context) && canEditAsText(getGrafanaMode(context?.nodes?.[0])),
  async exec(context) {
    const node = context.nodes[0]
    // The listing may not have carried the mode (see resolveMode). Settle it
    // before opening an editor on something that can never be saved: a link
    // opens in Grafana, which is the only thing a pointer can meaningfully do.
    if (!canEditAsText(await resolveMode(node))) {
      const url = await resolveUrl(node)
      if (!url) return null
      window.open(url, '_blank', 'noopener,noreferrer')
      return true
    }
    // null = silent (the modal is the feedback); false = error toast on failure.
    return (await openInText(node)) ? null : false
  },
  default: DefaultType.DEFAULT,
  order: -49, // below "Open in Grafana"; the fallback default for unmapped
})

// ── "New → Grafana dashboard" ──────────────────────────────────────────────
// Always offered, in any folder (we deliberately don't gate on a mapping). A new
// file outside a mapped folder is just a `.grafana` with our icon and empty
// metadata — not synced. Drop it into a mapped sync folder to make it real in
// Grafana (see the create-on-land path). `.grafana` is the file's last extension,
// so the server detects our mimetype on write and the icon is correct immediately.
const STARTER_DASHBOARD = JSON.stringify({
  title: 'New dashboard',
  tags: [],
  timezone: 'browser',
  panels: [],
  schemaVersion: 39,
}, null, 2) + '\n'

addNewFileMenuEntry({
  id: 'grafana_sync.new-dashboard',
  displayName: t(APP_ID, 'Grafana dashboard'),
  category: NewMenuEntryCategory.CreateNew,
  order: 20,
  iconSvgInline: grafanaMarkIcon,
  async handler(context, content) {
    const names = (content || []).map((n) => n.basename)
    const name = getUniqueName(t(APP_ID, 'New dashboard') + '.grafana', names)
    const dir = context.path === '/' ? '' : context.path
    const davPath = `${getRootPath()}${dir}/${name}`
    try {
      const client = getClient()
      await client.putFileContents(davPath, STARTER_DASHBOARD, {
        contentType: 'application/json',
        overwrite: false,
      })
      // Stat back the freshly-written file and announce it so the Files view
      // picks it up.
      const res = await client.stat(davPath, { details: true, data: getDefaultPropfind() })
      emit('files:node:created', resultToNode(res.data))
    } catch (e) {
      console.error('[grafana_sync] could not create dashboard', e)
      window.OC?.Notification?.showTemporary?.(t(APP_ID, 'Could not create the dashboard file'))
    }
  },
})

console.info('[grafana_sync] files integration loaded — actions: open in Grafana (sync/link) + open with text editor; New: Grafana dashboard')
