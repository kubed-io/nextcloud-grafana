# Notes, decisions and history for this feature: ../AGENTS.md#connectionsync-now

Feature: Syncing a mapped Grafana folder into Nextcloud
  As an admin who has just mapped a folder
  I want the dashboards already in Grafana to appear in Nextcloud
  So that the mirror starts out true, however the sync was started

  Background:
    Given the app is connected to Grafana

  # ── one behaviour, three ways to start it ──────────────────────────────────
  # notes: ../AGENTS.md#sync-now-scope

  @admin @in-grafana @occ @ui
  Scenario Outline: A sync fills the mapped folder, however it was started
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "<folder>"
    When <actor> syncs <scope>
    Then a file named "Alpha Demo.grafana.json" appears in "<folder>"
    And the file "<folder>/Alpha Demo.grafana.json" is a "sync" dashboard for uid "nc-alpha-demo"
    And "<folder>" holds exactly 1 dashboard file
    And the file "<folder>/Alpha Demo.grafana.json" carries its Grafana dates

    Examples: every way a sync starts
      | actor        | scope         | folder       |
      | the admin    | one mapping   | one-mapping  |
      | the admin    | every mapping | all-mappings |
      | the schedule | every mapping | on-schedule  |

    # notes: ../AGENTS.md#carries-its-grafana-dates

  # ── what a first sync does with a folder that already holds mirrors ────────

  @admin @in-grafana @occ @ui
  Scenario: A folder that already holds a mirror is filled in place, not doubled
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "alpha-again"
    And the admin pulls from Grafana
    When the admin pulls from Grafana
    Then "alpha-again" holds exactly 1 dashboard file
    # notes: ../AGENTS.md#a-folder-that-already-holds-a-mirror-is-filled-in-place-not-doubled

  # ── the whole-instance mirror ──────────────────────────────────────────────

  # notes: ../AGENTS.md#a-root-mapping-with-subfolder-sync-mirrors-the-whole-instance
  @admin @in-grafana @occ @ui @unbuilt
  Scenario: A root mapping with subfolder sync mirrors the whole instance
    Given a folder mapped as "sync" to the Grafana root "/" with "Sync subfolders" on
    And Grafana has dashboards at the root and inside nested folders
    When the admin syncs one mapping
    Then every Grafana folder that holds dashboards appears as a nested Nextcloud subfolder
    And every dashboard appears as a ".grafana.json" file in the matching subfolder
    And the Nextcloud tree is a one-to-one mirror of the Grafana folder structure
