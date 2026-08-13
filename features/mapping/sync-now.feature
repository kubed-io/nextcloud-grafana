# Notes, decisions and history for this feature: ../AGENTS.md#mappingsync-now

Feature: Syncing one mapping from its card
  As an admin who has just mapped a folder
  I want to sync that one folder without touching the others
  So that a new mapping fills immediately and a busy instance is not re-walked

  Background:
    Given the app is connected to Grafana

  @admin @occ @ui
  Scenario: Syncing one mapping fills its folder
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "one-mapping"
    And the Grafana folder "nc-alpha" already contains:
      | dashboard  | uid           |
      | Alpha Demo | nc-alpha-demo |
    When the admin syncs one mapping
    Then the mapped folder "one-mapping" holds:
      | file                    |
      | Alpha Demo.grafana.json |
    And "one-mapping/Alpha Demo.grafana.json" holds:
      | grafana_uid        | the dashboard's uid |
      | grafana_mapping    | set                 |
      | grafana_mode       | "sync"              |
      | grafana_version    | set                 |
      | grafana_syncedHash | set                 |
    And the file "one-mapping/Alpha Demo.grafana.json" carries its Grafana dates
    # notes: ../AGENTS.md#syncing-one-mapping-fills-its-folder

  # ── the whole-instance mirror, which is still one mapping ──────────────────

  # notes: ../AGENTS.md#a-root-mapping-mirrors-the-whole-instance
  @admin @occ @ui @unbuilt
  Scenario: A root mapping mirrors the whole instance
    Given a folder mapped as "sync" to the Grafana root "/"
    And Grafana has dashboards at the root and inside nested folders
    When the admin syncs one mapping
    Then every Grafana folder that holds dashboards appears as a nested Nextcloud subfolder
    And every dashboard appears as a ".grafana.json" file in the matching subfolder
    And the Nextcloud tree is a one-to-one mirror of the Grafana folder structure
