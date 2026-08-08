# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsrename

Feature: Renaming keeps file, JSON, and Grafana in agreement
  As a Nextcloud user
  I want renames to propagate everywhere
  So that the file name, its JSON name, and the Grafana dashboard name never drift

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ══ RENAMED IN NEXTCLOUD ═══════════════════════════════════════════════════════

  @user @in-nextcloud @gesture @ui
  Scenario: Renaming the file updates the backend JSON name and Grafana
    Given a managed "sync" dashboard file named "Old Name.grafana.json"
    When I rename the file to "New Name.grafana.json"
    Then the JSON "title" field inside the file becomes "New Name"
    And the dashboard is renamed to "New Name" in Grafana

  @user @in-nextcloud @gesture @ui
  Scenario: Editing the JSON name renames the file and updates Grafana
    Given a managed "sync" dashboard file
    When I edit the file and change the JSON "title" field to "Renamed In JSON"
    Then the file is renamed to "Renamed In JSON.grafana.json"
    And the dashboard is renamed to "Renamed In JSON" in Grafana

  @user @in-nextcloud @gesture @ui
  Scenario: Renaming never breaks the link
    Given a managed "sync" dashboard file with a known "grafana_uid"
    When the file is renamed by any of the above means
    Then the "grafana_uid" metadata is unchanged

  # A rename must not become a move. The file stays exactly where the user filed it,
  # including in a subfolder the reconciler would never have chosen.
  @user @in-nextcloud @gesture @ui @todo
  Scenario: A rename never relocates the file
    Given a managed "sync" dashboard file the user moved into a subfolder of "alpha"
    When I rename the file
    Then the file is still in that subfolder

  # notes: ../AGENTS.md#renaming-a-link-never-renames-the-dashboard
  @user @in-nextcloud @gesture @ui
  Scenario: Renaming a link never renames the dashboard
    Given a folder mapped as "link" to the Grafana folder "links"
    And a managed "link" dashboard file in that folder
    When I rename the file
    Then the dashboard keeps its name in Grafana

  @user @in-nextcloud @gesture @ui
  Scenario: Renaming an untracked ".grafana.json" file is not a failure
    Given an untracked ".grafana.json" file outside any mapping
    When I rename the file
    Then Grafana is not contacted
    And the rename succeeds

  # ══ RENAMED IN GRAFANA ═════════════════════════════════════════════════════════
  # notes: ../AGENTS.md#renaming-a-dashboard-in-grafana-renames-the-mirrored-file

  @grafana @in-grafana @occ @ui @todo
  Scenario: Renaming a dashboard in Grafana renames the mirrored file
    Given a managed "sync" dashboard file named "Old Name.grafana.json"
    When the dashboard is renamed to "New Name" in Grafana
    And the "alpha" mapping is pulled
    Then the file is renamed to "New Name.grafana.json"
    And the file's "grafana_uid" is unchanged

  @grafana @in-grafana @occ @ui @todo
  Scenario: A rename in Grafana reaches a link the same way
    Given a folder mapped as "link" to the Grafana folder "links"
    And a managed "link" dashboard file in that folder
    When the dashboard is renamed in Grafana
    And the "links" mapping is pulled
    Then the pointer file is renamed to match
    And the pointer's title reflects the new name

  # Two dashboards can share a title in Grafana; two files in one folder cannot share
  # a name. The collision suffix is what keeps one dashboard to one file.
  @grafana @in-grafana @occ @ui @todo
  Scenario: A rename that collides with an existing filename is suffixed, not overwritten
    Given two managed "sync" dashboard files in the "alpha" folder
    When one dashboard is renamed in Grafana to the other's title
    And the "alpha" mapping is pulled
    Then both files still exist
    And each still carries its own uid

  # notes: ../AGENTS.md#the-app-never-invents-a-substitute-name
  @grafana @in-grafana @occ @ui @todo
  Scenario: The app never invents a substitute name
    Given a dashboard in the "alpha" Grafana folder whose title is empty
    When the "alpha" mapping is pulled
    Then the file is named after the dashboard's uid
    And the file is a valid ".grafana.json"

  # ══ REFUSALS AND FAILURES ══════════════════════════════════════════════════════

  # notes: ../AGENTS.md#a-rename-to-an-empty-or-whitespace-only-name-is-refused
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A rename to an empty or whitespace-only name is refused
    Given a managed "sync" dashboard file
    When I try to rename the file to a whitespace-only name
    Then the rename is refused with a message
    And the file, its JSON title, and the dashboard still agree

  # notes: ../AGENTS.md#a-failed-propagation-never-reverts-the-local-rename
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A failed propagation never reverts the local rename
    Given a managed "sync" dashboard file
    And Grafana will reject the rename
    When I rename the file
    Then the file keeps its new name
    And the failure is reported to the user
