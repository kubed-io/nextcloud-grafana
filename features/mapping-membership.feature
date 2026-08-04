# Notes, decisions and history for this feature: AGENTS.md#mapping-membership

Feature: Mapping membership is resolved by folder
  As a Nextcloud admin
  I want mappings to be per-folder metadata
  So that membership is predictable and folders can nest

  # Same precondition as every behavioural feature: the scenarios add a mapping
  # (needs the app enabled) and land a file in it, which fires the create-on-land
  # listener that registers the dashboard in Grafana and stamps the grafana_mapping
  # we assert on — so we need the full connection, not just enablement.
  Background:
    Given the app is connected to Grafana

  @user @in-nextcloud @occ @ui @todo
  Scenario: A file's mapping is the folder it lives in
    Given a folder mapped to the Grafana folder "demo"
    When a managed dashboard file lives in that folder
    Then the file belongs to the "demo" mapping

  @user @in-nextcloud @occ @ui @todo
  Scenario: A file outside every mapped folder belongs to no mapping
    Given a folder that is not mapped
    When a dashboard file lives in that folder
    Then the file belongs to no mapping
    And it is "untracked" if it has no Grafana uid, or "unmapped" if it carries one

  @admin @occ @ui @todo
  Scenario: Folder mappings are metadata, so a mapped folder can nest in another
    Given a folder mapped to the Grafana folder "outer"
    And a subfolder of it mapped to the Grafana folder "inner"
    When a dashboard file lives in the subfolder
    Then it belongs to the "inner" mapping, not "outer"
    And the nearest enclosing mapping wins

  # Cascade (saga Ch2, revised) is the *automatic* alternative to an explicit nested
  # mapping: with the parent's "Sync subfolders" on, a subfolder needs no mapping of its
  # own. The file stays under the PARENT mapping; its grafana_folderUid records which
  # (auto-created, presence-driven) Grafana subfolder it sits in.
  @admin @occ @ui @todo
  Scenario: A cascaded subfolder needs no mapping of its own
    Given a folder mapped to the Grafana folder "outer" with "Sync subfolders" on
    And a subfolder of it with no mapping of its own
    When a dashboard file lives in that subfolder
    Then it belongs to the "outer" mapping
    And its "grafana_folderUid" records the subfolder, not the parent folder
