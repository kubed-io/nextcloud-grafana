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

- Admin **connection** panel: point the app at your Grafana (base URL), store a service-account token (encrypted), and a **Test connection** button that authenticates against Grafana to confirm the token is valid.
- Headless config via `occ grafana_sync:set-token` (encrypted, occ/helm-injectable) and `occ grafana_sync:test-connection` (same authenticated check as the button).
- Admin **folder mapping** panel: bind a Grafana folder (picked from the folders your token can see) to a Nextcloud folder, with a **mode** (sync / link) and a serialization **format** (json / yaml — the classic dashboard JSON or the newer k8s-style YAML schema). A Grafana folder maps to exactly one location; mappings are stored as config, so the same list is editable over the CLI.
- Headless mapping config via `occ grafana_sync:add-mapping '<json>'`, `occ grafana_sync:list-mappings`, and `occ grafana_sync:remove-mapping <id>` (occ/helm-injectable).

### Fixed

- CI: dropped the inherited Psalm issue-handler suppressions the connection-only POC never triggers (they caused an UnusedIssueHandlerSuppression failure and a broken SARIF upload); they return with the sync code that needs them.
