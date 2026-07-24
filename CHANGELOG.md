# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

<!--
  These ARE the release notes. One line per entry, written for a user — never a
  paragraph. Length tracks impact: functional changes get the most words (still
  one line); refactors/types/tests stay short; CI/devops are shortest. Only
  **BREAKING:** may stretch. Deeper detail lives in the saga or the PR, not here.

  ONLY EVER EDIT THE [Unreleased] SECTION. Every section below it carries a
  version number and is IMMUTABLE — those notes shipped with a release and must
  never be reworded, reordered, or removed. Add new work under [Unreleased].
  See CONTRIBUTING.md / AGENTS.md.
-->

## [Unreleased]

### Added

- **Create a dashboard by making a file.** Drop a `.grafana.json` into a mapped **sync** folder — via the Files app, the text editor, a WebDAV upload, or by moving one in — and it becomes a real Grafana dashboard, placed in the mapped folder and stamped with its new uid. Author dashboards without opening Grafana; no button needed. (A file that already carries a uid re-adopts that dashboard.)
- **Copying a dashboard file makes a new dashboard.** A copy never inherits the original's identity — it's registered as its own fresh dashboard (new uid), so the source is never overwritten. A copy outside any mapped folder stays a plain, untracked file.
- **Moving a dashboard file mirrors to Grafana's own folders.** Move a `.grafana.json` into a different mapped folder and the dashboard **re-parents** in Grafana with its uid kept — same dashboard, new home. Move it out of every mapping and the dashboard is **deleted** in Grafana (its full JSON is safe in Nextcloud), leaving a plain file; moving it back into a mapping makes a fresh dashboard. A within-mapping move (rename/subfolder) changes nothing. A failed Grafana delete leaves the file's identity intact rather than orphaning it. (Applies to moves between regular folders; moving into/out of a Team Folder crosses a storage boundary and will ride the delete/create lifecycle — a fast-follow. Recycle-bin-preserving move-out is also a fast-follow.)
- **A link file can't be overwritten over WebDAV.** A link is only a pointer to a Grafana-hosted dashboard, so a raw WebDAV `PUT` / desktop-client sync that would corrupt the pointer is refused with a clean `403` and a notification explaining how to switch the folder to sync mode. The guard fails open on any doubt.
- **Sync to Grafana** (the writeback) now works: edit a synced `.grafana.json` in the Files app (or over WebDAV / the desktop client), save, and the dashboard updates in Grafana on its stable uid — same dashboard, same folder, never a duplicate. Runs automatically on save (background by default, honouring the push-timing setting), on the **Sync to Grafana** button, or via `occ grafana_sync:sync push [--mapping=<id>]`. A push that Grafana rejects raises a notification with Grafana's own message and leaves the file to retry on the next save.
- **Sync from Grafana** (the pull) now works: the app provisions each mapped folder (Team Folder or admin-owned) and fills it with that Grafana folder's dashboards as native `.grafana.json` files — matched by the stable dashboard uid, so re-pulls update in place instead of duplicating, and a file whose dashboard left the folder is pruned. Sync files hold the full dashboard spec; link files are a small deep-link pointer. Run it from the **Sync from Grafana** button or `occ grafana_sync:sync pull [--mapping=<id>]`.
- `.grafana.json` files now render with a Grafana file icon in the Files app — the `application/grafana+json` mimetype + icon register on install/upgrade and revert cleanly on uninstall.
- Managed dashboard files carry a `grafana:sync` / `grafana:link` tag (a coloured pill in the Files app) matching the file's mode.
- Admin **Sync Settings** card: choose the Nextcloud → Grafana push timing (async/sync) and enable a scheduled Grafana → Nextcloud pull with an interval — a number plus a unit (`s`/`m`/`h`/`d`; a bare number is seconds), minimum `1m`, e.g. `15m`, `1h`, `1d`. Config only for now — read by the sync engine when it lands; injectable over `occ config:app:set grafana_sync <key>`.
- Admin **Sync Actions** panel now shows the full action set — **Sync to Grafana** / **Sync from Grafana** / **Purge Nextcloud files** — beside **Test connection**, matching the n8n app's layout. Sync from Grafana, Sync to Grafana, and Test connection work today; Purge is disabled until a later release.
- Admin **connection** panel: point the app at your Grafana (base URL), store a service-account token (encrypted), and a **Test connection** button that authenticates against Grafana to confirm the token is valid.
- Headless config via `occ grafana_sync:set-token` (encrypted, occ/helm-injectable) and `occ grafana_sync:test-connection` (same authenticated check as the button).
- Admin **folder mapping** panel: bind a Grafana folder (picked from the folders your token can see) to a Nextcloud folder, with a **mode** (sync / link) and a serialization **format** (json / yaml — the classic dashboard JSON or the newer k8s-style YAML schema). A Grafana folder maps to exactly one location; mappings are stored as config, so the same list is editable over the CLI.
- Headless mapping config via `occ grafana_sync:add-mapping '<json>'`, `occ grafana_sync:list-mappings`, and `occ grafana_sync:remove-mapping <id>` (occ/helm-injectable).

### Changed

- Dev: added the `@nextcloud/files`, `event-bus`, `initial-state`, `l10n`, and `router` frontend deps (matching the n8n master) so the upcoming in-Files openers PR can focus on behaviour, not tooling.
- Folder mappings are now hardened: the **Nextcloud folder name is optional** (when left blank it's stored as the Grafana folder's own name at create, so the list shows both), and a mapping's **Grafana folder, Nextcloud folder, Team Folder, and subfolder-sync are immutable** once created — to re-point any of them, delete the mapping and add a new one. This avoids a fiddly live migration of already-synced files. Mode, format, and groups stay editable.

- Folder mappings now persist **Groups** and **Team Folder** with each mapping — previously the checkboxes/pickers were rendered for parity but the values were dropped on save; now every field round-trips through both the admin panel and `occ grafana_sync:add-mapping`. (The folder is still provisioned from those values only once the sync engine lands.)
- The admin connection settings are now a single **Instance** card (base URL + service-account token together) instead of separate Instance and Connection cards — Grafana has one API and one credential, so there's nothing to split. The name stays "Instance" to line up with the n8n app's first section.
- Folder mappings admin UI now matches the n8n app's card layout: **Grafana folder + Nextcloud folder** (col 1) · **Mode + Format + Team Folder** (col 2) · **Groups** (col 3) · **Save / Sync / Delete** (row 4). The per-folder Sync button is shown for interface parity — it's wired to the sync engine in a later release.
- Admin settings: the **Test connection** button moved into a new **Sync Actions** section rendered below the folder mappings — one home for every action button (matching the n8n app's layout), instead of a button panel wedged between the data cards.
- Connection card now shows whether a service-account token is **currently stored** (the field itself always looks empty because the token is sensitive/encrypted), so you can tell "not set yet" from "already saved" at a glance.

### Fixed

- Creating a dashboard from a file now rejects a non-object JSON body (a JSON array or scalar) with a clear error instead of silently creating an empty dashboard — consistent with how a push validates the file.
- The **“Grafana → Nextcloud: scheduled sync”** toggle now saves. Its checkbox default was a string (`'0'`) where Nextcloud's declarative settings require a real boolean, so the toggle silently reverted to off and never persisted — it now stores correctly (and will drive the scheduled pull once that lands). Same fix the n8n sibling shipped.
- Test connection now tells a **missing** token apart from a **rejected** one — an unset token says so, an invalid/expired one reports "Grafana rejected the token (HTTP 401)". Previously a rejected token surfaced Grafana's raw error and looked the same as other failures. Same wording on the button and `occ grafana_sync:test-connection`.
- CI: dropped the inherited Psalm issue-handler suppressions the connection-only POC never triggers (they caused an UnusedIssueHandlerSuppression failure and a broken SARIF upload); they return with the sync code that needs them.
