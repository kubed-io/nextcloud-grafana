# Notes, decisions and history for this feature: ../AGENTS.md#dashboardscopy

Feature: Copying a dashboard file always makes a new instance
  As a Nextcloud user
  I want a copy to be a fresh dashboard, never a hijack of the original
  So that duplicating a file is safe and predictable

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ══ COPIED IN NEXTCLOUD ════════════════════════════════════════════════════════

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Copy within a mapped sync folder becomes a new dashboard in Grafana
    Given a managed "sync" dashboard file in the "alpha" folder
    When I copy the file within the "alpha" folder
    Then the copy carries no inherited "grafana_uid"
    And the copy is registered as a NEW dashboard in Grafana with its own uid
    And the original file and dashboard are unchanged
    And there are now two distinct dashboards in Grafana

  @user @in-nextcloud @gesture @ui
  Scenario: Copy to outside any mapping is a plain untracked file
    Given a managed "sync" dashboard file in the "alpha" folder
    When I copy the file to a folder that is not mapped
    Then the copy has no Grafana metadata
    And no dashboard is created in Grafana for the copy
    And the copy is treated as a plain document

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Copy of an unmapped file strips its metadata wherever it lands
    Given an unmapped dashboard file that still carries its "grafana_uid"
    When I copy the file to a folder that is not mapped
    Then the copy has no Grafana metadata
    And the original unmapped file keeps its "grafana_uid"

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Copy of an unmapped file into a mapping becomes a new dashboard
    Given an unmapped dashboard file that still carries its "grafana_uid"
    When I copy the file into the "alpha" folder
    Then the copy carries no inherited "grafana_uid"
    And the copy is registered as a NEW dashboard in Grafana with its own uid
    And the original unmapped file's dashboard is not restored or duplicated

  # ── a link copies like anything else: the copy is not a link ─────────────────────
  # notes: ../AGENTS.md#copying-a-link-never-creates-a-second-dashboard

  @user @in-nextcloud @gesture @ui
  Scenario: Copying a link never creates a second dashboard
    Given a folder mapped as "link" to the Grafana folder "links"
    And a managed "link" dashboard file in that folder
    When I copy the file within the "links" folder
    Then no dashboard is created in Grafana for the copy
    And the copy carries no inherited "grafana_uid"

  # ══ COPIED IN GRAFANA ══════════════════════════════════════════════════════════
  # notes: ../AGENTS.md#a-dashboard-duplicated-in-grafana-arrives-as-a-new-file

  @grafana @in-grafana @occ @ui @todo
  Scenario: A dashboard duplicated in Grafana arrives as a new file
    Given a managed "sync" dashboard file in the "alpha" folder
    When the dashboard is duplicated in Grafana
    And the "alpha" mapping is pulled
    Then a second file appears in the mapped folder
    And the two files carry different uids
    And the original file is unchanged

  # notes: ../AGENTS.md#a-duplicate-made-in-grafana-takes-the-mappings-mode-not-the-originals
  @grafana @in-grafana @occ @ui @todo
  Scenario: A duplicate made in Grafana takes the mapping's mode, not the original's
    Given a folder mapped as "link" to the Grafana folder "links"
    And a dashboard in the "links" Grafana folder
    When the dashboard is duplicated in Grafana
    And the "links" mapping is pulled
    Then the new file is a "link" pointer

  # notes: ../AGENTS.md#the-pulls-own-writes-are-never-treated-as-a-copy

  # ── failure ──────────────────────────────────────────────────────────────────────

  # notes: ../AGENTS.md#a-copy-whose-dashboard-cannot-be-created-stays-a-plain-file-and-says-so
  @user @in-nextcloud @gesture @ui @todo
  Scenario: A copy whose dashboard cannot be created stays a plain file and says so
    Given a managed "sync" dashboard file in the "alpha" folder
    And Grafana will reject the creation
    When I copy the file within the "alpha" folder
    Then the copy carries no "grafana_uid"
    And the failure is reported to the user
    And the original file and its dashboard are unchanged
