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

### Changed

- Spec: `reconcile.feature` is gone, and must never come back. The reconciler is a mechanism, not something anyone does — its scenarios were four behaviours wearing one coat, and each moved to the thing a person actually performs: the first sync (`sync-now.feature`), editing a dashboard file (`edit-dashboard.feature`), and deleting a dashboard in Grafana (`delete-dashboard.feature`).
- Spec: `file-type.feature` is gone. A mimetype is not something anyone does — it is what enabling the app left behind, so it is asserted on install; the rest of the file became `view-dashboard.feature`, about looking at a mirror. The DAV property table now lists only the five keys a mirror actually arrives with (`grafana_folderUid` and `grafana_apiVersion` are registered but written by nothing yet).
- **A new mapping defaults to an admin-owned folder, not a Team Folder.** Leaving the storage backend unset used to mean "Team Folder", which needs the optional `groupfolders` app — so on a stock Nextcloud the default mapping was the one that could not be provisioned, and filling in only the required fields got you a refusal. A Team Folder is now opted into. Existing mappings are unaffected: every mapping this app has saved records its backend explicitly.
- **Re-share a mapped folder from anywhere and this app reflects it.** The groups a mapped folder is shared with are now read from the folder itself rather than stored alongside the mapping, so a change made in Files, with `occ`, or by another app sharing the same folder shows up here — and a sync never puts back a group you removed. Setting the groups to nothing now actually clears them, which it silently did not before.
- **BREAKING:** a mapping is now immutable except for its groups. The Grafana folder, the Nextcloud folder, the storage backend, subfolder-sync, the mode and the format are all fixed once created — mode and format were previously editable, which silently invalidated how every already-mirrored file had been written. Remove the mapping and add it again to change one.
- **`occ grafana_sync:set-groups`** changes the groups a mapped folder is shared with, the one field a mapping lets you edit — previously reachable only from the admin panel.
- **A mapped folder now appears the moment you save the mapping**, instead of only when the first sync runs. A mapping whose folder cannot be provisioned is no longer saved at all, rather than being stored and failing on every sync afterwards.
- A `link` mapping's folder is no longer read-only for its groups. That bit stopped nothing being written to Grafana — the listeners do that — and only stopped you organising your own files.

### Fixed

- **A sync no longer marks every dashboard file as modified.** Each sync rewrote every mirrored file whether or not anything had changed in Grafana, so the whole folder read "Modified a few seconds ago" after every run and a file you had actually touched was impossible to spot — a pull now writes only the files whose dashboard really changed, and reports the rest as "unchanged".
- **BREAKING:** requires Nextcloud 31+ (was 30). The Sync Settings form now handles its own storage, which needs an interface added in 31; Nextcloud 30 is end-of-life.
- **The Sync Settings checkboxes could never be saved** — "scheduled sync" and "preserve dashboards in a recycle-bin folder" sprang back on reload. The recycle-bin one is the serious half: the toggle silently reverting meant every trashed dashboard was permanently deleted in Grafana instead of parked, and Grafana has no undo.
- **Emptying the Nextcloud trash never deleted parked dashboards.** With the recycle bin on, Grafana kept every "deleted" dashboard forever; the app was not being loaded on WebDAV requests, so the purge hook never ran.
- **Test connection** is no longer reachable without a CSRF token, so another site can no longer make your server probe the configured Grafana URL.
- **Pulled dashboards no longer have their empty JSON objects turned into empty arrays** (`timepicker`, a panel's `options`, `fieldConfig.defaults`), which corrupted the mirrored file and was then pushed back to Grafana.

### Added

- **The scheduled sync now actually runs.** "Grafana → Nextcloud: scheduled sync" and its interval have been in Sync Settings all along and nothing read either of them, so turning the schedule on did nothing at all, forever. There is now a background job behind it.
- **A dashboard file's dates are now the dashboard's own dates.** "Modified" shows when the dashboard last changed in Grafana and "Created" when it was created there, instead of both showing when a sync happened to run — so sorting a mapped folder by date sorts by the dashboards, and one nobody has touched in a year finally looks like it.
- **Three-way rename**: for a sync file the filename stem, the JSON `title`, and the Grafana dashboard title stay in agreement — rename the file or edit the title, and the other two follow. Stable on the uid, so no rename breaks the link.
- **Open a dashboard from the Files app**: a `.grafana.json` row offers **Open in Grafana** (default for sync/link — jumps to the live dashboard) and **Open with text editor** (raw JSON, hidden for link pointers), plus a **Grafana dashboard** entry in the **+ New** menu.
- **Create a dashboard by making a file** in a mapped sync folder — it becomes a real Grafana dashboard, no button needed (a file already carrying a uid re-adopts it).
- **Copying a dashboard file makes a new dashboard** (new uid); the original is never overwritten.
- **Moving a dashboard file** re-parents its dashboard in Grafana (uid kept) when it lands in another mapped folder, or deletes it when moved out of every mapping (content stays safe in the file, so moving back re-creates it).
- **A link file can't be overwritten over WebDAV** — a write to the pointer is refused with a `403` and a notification.
- **Deleting a dashboard file** deletes its dashboard in Grafana and a restore re-creates it (new id); or, with the optional **Grafana recycle-bin folder** setting on, trashing parks the dashboard in that folder (id kept), a restore moves it back, and only emptying the trash deletes it. A link's dashboard and untracked files are never deleted; a delete Grafana can't confirm aborts the trash.
- **Removing a folder mapping** trashes its connected files (their dashboards follow the recycle-bin setting) and leaves standalone files alone.
- **Sync to Grafana** (writeback): saving a synced file pushes it back on its stable uid — on save, the button, or `occ grafana_sync:sync push`.
- **Sync from Grafana** (pull): provisions each mapped folder and fills it with that folder's dashboards, matched by uid so re-pulls never duplicate — the button or `occ grafana_sync:sync pull`.
- `.grafana.json` files show a Grafana icon via the `application/grafana+json` mimetype (registered on install, reverted on uninstall).
- Managed files carry a `grafana:sync` / `grafana:link` pill matching their mode.
- Admin **Sync Settings** card: push timing (async/sync), a scheduled-pull interval, and the Grafana recycle-bin folder.
- Admin **Sync Actions** panel: Sync to/from Grafana, Purge, and Test connection (Purge disabled for now).
- Admin **connection** panel: base URL + encrypted service-account token + a **Test connection** button.
- Headless config via `occ grafana_sync:set-token` and `occ grafana_sync:test-connection`.
- Admin **folder mapping** panel: bind a Grafana folder to a Nextcloud folder with a mode (sync/link) and format (json/yaml).
- Headless mappings via `occ grafana_sync:add-mapping` / `list-mappings` / `remove-mapping`.

### Changed

- Dev: added the `@nextcloud/files` / `event-bus` / `initial-state` / `l10n` / `router` frontend deps (matching n8n) for the upcoming openers PR.
- CI: the integration suite is split into three Behat suites (`admin`, `dashboard`, `core`) run as parallel matrix legs, with one aggregated result comment instead of one per leg.
- CI: a new check keeps every feature file in exactly one suite, and the step-definition check now also catches a step no definition resolves.
- **A mapping's mode now defaults to `link` when you don't set one**, instead of the whole mapping being refused — so `occ grafana_sync:add-mapping` needs only a Grafana folder. `link` downloads nothing, so a mapping made without thinking about mode cannot cost you anything.
- Spec: the mapping specification now describes a mapping as one fact with a table of its values, so a scenario can state an existing mapping and perform the action that would have created it in the same words. The essays move to `features/AGENTS.md`, leaving each feature file a link.
- Folder mappings hardened: the Nextcloud folder name is optional (defaults to the Grafana folder's name), and Grafana folder / Nextcloud folder / Team Folder / subfolder-sync are immutable after create — re-create to change them; mode, format, and groups stay editable.
- Folder mappings now persist **Groups** and **Team Folder** (previously rendered but dropped on save).
- The admin connection is a single **Instance** card (Grafana has one API and one credential).
- Folder mappings admin UI now matches the n8n card layout.
- **Test connection** moved into the **Sync Actions** section below the folder mappings.
- Connection card shows whether a token is **currently stored** (the field always looks empty since it's encrypted).

### Fixed

- The **Grafana glyph in the Files context menu and the + New menu** now themes to the menu colour instead of rendering as a solid yellow tile.
- Creating a dashboard from a non-object JSON body now errors clearly instead of making an empty dashboard.
- The **scheduled-sync toggle** now saves (its checkbox default was a string where a real boolean is required).
- **Test connection** now tells a **missing** token from a **rejected** one (HTTP 401).
- CI: dropped unused Psalm suppressions that caused a failure and a broken SARIF upload.
