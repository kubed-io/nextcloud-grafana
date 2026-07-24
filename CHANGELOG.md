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

- Admin **Sync Settings** card: choose the Nextcloud → Grafana push timing (async/sync) and enable a scheduled Grafana → Nextcloud pull with an interval (any duration, e.g. `15m`, `1h`, `1d`). Config only for now — read by the sync engine when it lands; injectable over `occ config:app:set grafana_sync <key>`.
- Admin **Sync Actions** panel now shows the full action set — **Sync to Grafana** / **Sync from Grafana** / **Purge Nextcloud files** — beside **Test connection**, matching the n8n app's layout. The bulk buttons are disabled until dashboard sync lands (a later release); Test connection works today.
- Admin **connection** panel: point the app at your Grafana (base URL), store a service-account token (encrypted), and a **Test connection** button that authenticates against Grafana to confirm the token is valid.
- Headless config via `occ grafana_sync:set-token` (encrypted, occ/helm-injectable) and `occ grafana_sync:test-connection` (same authenticated check as the button).
- Admin **folder mapping** panel: bind a Grafana folder (picked from the folders your token can see) to a Nextcloud folder, with a **mode** (sync / link) and a serialization **format** (json / yaml — the classic dashboard JSON or the newer k8s-style YAML schema). A Grafana folder maps to exactly one location; mappings are stored as config, so the same list is editable over the CLI.
- Headless mapping config via `occ grafana_sync:add-mapping '<json>'`, `occ grafana_sync:list-mappings`, and `occ grafana_sync:remove-mapping <id>` (occ/helm-injectable).

### Changed

- Folder mappings now persist **Groups** and **Team Folder** with each mapping — previously the checkboxes/pickers were rendered for parity but the values were dropped on save; now every field round-trips through both the admin panel and `occ grafana_sync:add-mapping`. (The folder is still provisioned from those values only once the sync engine lands.)
- The admin connection settings are now a single **Instance** card (base URL + service-account token together) instead of separate Instance and Connection cards — Grafana has one API and one credential, so there's nothing to split. The name stays "Instance" to line up with the n8n app's first section.
- Folder mappings admin UI now matches the n8n app's card layout: **Grafana folder + Nextcloud folder** (col 1) · **Mode + Format + Team Folder** (col 2) · **Groups** (col 3) · **Save / Sync / Delete** (row 4). The per-folder Sync button is shown for interface parity — it's wired to the sync engine in a later release.
- Admin settings: the **Test connection** button moved into a new **Sync Actions** section rendered below the folder mappings — one home for every action button (matching the n8n app's layout), instead of a button panel wedged between the data cards.
- Connection card now shows whether a service-account token is **currently stored** (the field itself always looks empty because the token is sensitive/encrypted), so you can tell "not set yet" from "already saved" at a glance.

### Fixed

- Test connection now tells a **missing** token apart from a **rejected** one — an unset token says so, an invalid/expired one reports "Grafana rejected the token (HTTP 401)". Previously a rejected token surfaced Grafana's raw error and looked the same as other failures. Same wording on the button and `occ grafana_sync:test-connection`.
- CI: dropped the inherited Psalm issue-handler suppressions the connection-only POC never triggers (they caused an UnusedIssueHandlerSuppression failure and a broken SARIF upload); they return with the sync code that needs them.
