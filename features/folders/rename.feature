# Notes, decisions and history for this feature: ../AGENTS.md#foldersrename

Feature: Renaming a folder
  As a Nextcloud user or admin
  I want renaming a folder to either follow through or refuse
  So that a rename never silently disconnects dashboards from their mapping

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ── the mapped folder itself ─────────────────────────────────────────────────────

  # notes: ../AGENTS.md#renaming-a-mapped-folder-silently-orphans-its-mapping
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Renaming a mapped folder silently orphans its mapping
    Given a managed "sync" dashboard file in the "alpha" folder
    When I rename the mapped Nextcloud folder
    Then the mapping no longer matches any folder
    And the file inside it resolves to no mapping
    And nothing warns the user that the connection is broken

  # notes: ../AGENTS.md#renaming-a-mapped-folder-keeps-the-mapping-pointing-at-it
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Renaming a mapped folder keeps the mapping pointing at it
    Given a managed "sync" dashboard file in the "alpha" folder
    When I rename the mapped Nextcloud folder
    Then the mapping still owns that folder under its new name
    And the file inside it is still managed "sync" under the mapping
    And nothing changes in Grafana

  # The alternative, if following is not wanted: refuse the gesture rather than let
  # it half-happen. Either answer is defensible; silence is not.
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A mapped folder that cannot follow a rename refuses it with a reason
    Given a managed "sync" dashboard file in the "alpha" folder
    When I try to rename the mapped Nextcloud folder
    Then the rename is refused with a message naming the mapping
    And the folder keeps its name

  # ── a mirrored subfolder ─────────────────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Renaming a mirrored subfolder renames the Grafana subfolder
    Given a mirrored Grafana folder "Team A" under the "alpha" folder, holding a dashboard
    When I rename the subfolder to "Team B"
    Then the Grafana subfolder is renamed to "Team B"
    And the dashboards inside keep their uids

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Renaming an untagged subfolder never reaches Grafana
    Given an untagged subfolder of "alpha" holding a managed "sync" dashboard file
    When I rename the subfolder
    Then Grafana is not contacted
    And the dashboard stays bound to the "alpha" mapping

  # notes: ../AGENTS.md#a-failed-subfolder-rename-leaves-the-local-rename-standing
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A failed subfolder rename leaves the local rename standing
    Given a mirrored Grafana folder under the "alpha" folder, holding a dashboard
    And Grafana will reject the folder rename
    When I rename the subfolder
    Then the Nextcloud folder keeps its new name
    And the failure is reported to the user

  # ── renamed on the Grafana side ──────────────────────────────────────────────────

  # notes: ../AGENTS.md#renaming-the-mapped-folder-in-grafana-does-not-break-the-mapping
  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: Renaming the mapped folder in Grafana does not break the mapping
    Given a managed "sync" dashboard file in the "alpha" folder
    When the "alpha" folder is renamed in Grafana
    And the mapping is pulled
    Then the mapping still resolves to that Grafana folder by uid
    And the dashboards inside are unaffected

  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: Renaming a mirrored Grafana subfolder renames the Nextcloud subfolder
    Given a mirrored Grafana folder "Team A" under the "alpha" folder, holding a dashboard
    When the Grafana subfolder is renamed to "Team B"
    And the mapping is pulled
    Then the Nextcloud subfolder is renamed to "Team B"
    And the dashboard file inside keeps its uid and its place
