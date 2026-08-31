/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure, dependency-free helpers for the Files integration (src/files.js).
 *
 * These are split out from files.js precisely because files.js imports
 * `@nextcloud/*` ESM at the top level, which makes it awkward to unit test. This
 * module imports nothing, so Vitest can exercise the branchy logic directly —
 * the JS analog of PHP's FilenameCodec. Keep it free of NC imports and DOM/network.
 */

/** The custom mimetype the pull reconciler stamps onto dashboard files. */
export const GRAFANA_MIME = 'application/grafana+json'

/**
 * Read the Grafana dashboard uid from a node's DAV attributes (the listing fast
 * path). Tolerates the three shapes the value can arrive as, depending on which
 * PROPFIND produced the node. Returns '' when absent or not a string.
 *
 * @param {{attributes?: Record<string, unknown>}} [node]
 * @return {string}
 */
export function getGrafanaUid(node) {
  const a = node?.attributes ?? {}
  const uid = a['metadata-grafana_uid'] || a['grafana_uid'] || a['{http://nextcloud.org/ns}metadata-grafana_uid']
  return typeof uid === 'string' ? uid : ''
}

/**
 * Build the Grafana deep link for a dashboard uid. Returns '' if either the base
 * URL or the uid is empty (the caller hides the action in that case). The base
 * url is passed in (not closed over) so this stays pure and testable. Grafana
 * resolves `/d/<uid>` to the full slug URL, so no slug is needed.
 *
 * @param {string} grafanaUrl  Trailing-slash-trimmed Grafana base URL.
 * @param {string} uid         Dashboard uid.
 * @return {string}
 */
export function buildUrl(grafanaUrl, uid) {
  return grafanaUrl && uid ? `${grafanaUrl}/d/${encodeURIComponent(uid)}` : ''
}

/**
 * Is this file-action context a single Grafana dashboard file? True for the
 * custom mime OR a `.grafana` basename, and only when exactly one node is
 * selected. Plain JSON is never matched.
 *
 * @param {{nodes?: Array<{mime?: string, basename?: string}>}} [context]
 * @return {boolean}
 */
export function isDashboardFile(context) {
  const node = context?.nodes?.[0]
  if (!node || context.nodes.length !== 1) return false
  return node.mime === GRAFANA_MIME
    || (typeof node.basename === 'string' && node.basename.endsWith('.grafana'))
}

/**
 * Read the file's `grafana_mode` from a node's DAV attributes. Tolerates the same
 * three attribute shapes as {@see getGrafanaUid}, and translates the WIRE value
 * back: a `link` is stored as `reference` over DAV (the literal `link` makes
 * `is_callable()` true and crashes core PROPFIND — saga §14.1), so we normalise
 * `reference` → `link` here. Returns '' when absent (the first-load PROPFIND race,
 * or an untracked file).
 *
 * @param {{attributes?: Record<string, unknown>}} [node]
 * @return {string}  '' | 'sync' | 'link' | 'unmapped'
 */
export function getGrafanaMode(node) {
  const a = node?.attributes ?? {}
  const raw = a['metadata-grafana_mode'] || a['grafana_mode'] || a['{http://nextcloud.org/ns}metadata-grafana_mode']
  const mode = typeof raw === 'string' ? raw : ''
  return mode === 'reference' ? 'link' : mode
}

/**
 * Should "Open in Grafana" be offered for a file in this mode? It is meaningful
 * only when a live dashboard exists to open: `sync`/`link` have one, `unmapped`
 * does not (its dashboard was deleted, or was never created — nothing to jump
 * to). An absent mode (the first-load race, or an untracked file) stays permissive
 * → shown, matching the pre-mode behaviour; the action no-ops harmlessly if there
 * is no uid to resolve.
 *
 * @param {string} mode
 * @return {boolean}
 */
export function canOpenInGrafana(mode) {
  return mode !== 'unmapped'
}

/**
 * Which opener a plain row-click uses, by mode. `sync`/`link` (and the permissive
 * absent case) → the live dashboard in Grafana; `unmapped` → the text editor on
 * the local JSON. Mirrors {@see canOpenInGrafana} so the default click
 * and the action visibility never disagree.
 *
 * @param {string} mode
 * @return {'grafana'|'text'}
 */
export function defaultOpener(mode) {
  return canOpenInGrafana(mode) ? 'grafana' : 'text'
}

/**
 * Should "Open with text editor" be offered for a file in this mode? Every mode
 * holds the full dashboard JSON on disk EXCEPT `link`, which is only a small
 * pointer (uid/title/url) — there is nothing meaningful to edit, and any change
 * would just break the pointer. So `sync` and `unmapped` (and the permissive
 * absent case, matching {@see canOpenInGrafana}) → shown; `link` → hidden. This
 * is what makes "open as text" the user-visible difference between a
 * `sync` file (editable JSON) and a `link` (open in Grafana only).
 *
 * @param {string} mode
 * @return {boolean}
 */
export function canEditAsText(mode) {
  return mode !== 'link'
}
