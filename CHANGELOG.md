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
- Folder mappings hardened: the Nextcloud folder name is optional (defaults to the Grafana folder's name), and Grafana folder / Nextcloud folder / Team Folder / subfolder-sync are immutable after create — re-create to change them; mode, format, and groups stay editable.
- Folder mappings now persist **Groups** and **Team Folder** (previously rendered but dropped on save).
- The admin connection is a single **Instance** card (Grafana has one API and one credential).
- Folder mappings admin UI now matches the n8n card layout.
- **Test connection** moved into the **Sync Actions** section below the folder mappings.
- Connection card shows whether a token is **currently stored** (the field always looks empty since it's encrypted).

### Fixed

- Creating a dashboard from a non-object JSON body now errors clearly instead of making an empty dashboard.
- The **scheduled-sync toggle** now saves (its checkbox default was a string where a real boolean is required).
- **Test connection** now tells a **missing** token from a **rejected** one (HTTP 401).
- CI: dropped unused Psalm suppressions that caused a failure and a broken SARIF upload.
