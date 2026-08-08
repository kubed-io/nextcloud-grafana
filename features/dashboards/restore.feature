# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsrestore

Feature: Restoring a dashboard file from the trash
  As a Nextcloud user
  I want a restore to bring back my dashboard, with its identity where possible
  So that the Nextcloud trash is a real undo for a system that has none of its own

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ══ RESTORED IN NEXTCLOUD ══════════════════════════════════════════════════════

  # THE DEFAULT PATH. The dashboard was destroyed at trash time; this creates one
  # from the file's JSON. Same content, new object.
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restoring a sync file re-creates the dashboard with a new id (bin off)
    Given the Grafana recycle-bin folder is off
    And a trashed sync dashboard file whose dashboard is already deleted
    When I restore it from the trash
    Then a dashboard is re-created in Grafana from the file's JSON body
    And it has a NEW "grafana_uid"
    And the file's mode becomes "sync" under its original mapping

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Restoring a parked file moves its dashboard back with the same id (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    When I restore it from the trash
    Then the dashboard is moved out of "nextcloud-trash" back into its mapped folder
    And the dashboard keeps the same "grafana_uid"

  # notes: ../AGENTS.md#restoring-into-a-mapping-that-no-longer-exists-leaves-a-plain-file
  @user @in-nextcloud @gesture @ui @todo
  Scenario: Restoring into a mapping that no longer exists leaves a plain file
    Given a trashed sync dashboard file
    And its mapping has since been removed
    When I restore it from the trash
    Then the file is back in Nextcloud
    And it is not managed by any mapping
    And Grafana is not contacted

  # notes: ../AGENTS.md#restoring-a-parked-file-whose-dashboard-was-deleted-in-grafana-re-creates-it
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restoring a parked file whose dashboard was deleted in Grafana re-creates it
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    And that parked dashboard has since been permanently deleted in Grafana
    When I restore it from the trash
    Then a dashboard exists in Grafana again holding the file's content
    And the file points at that dashboard

  # notes: ../AGENTS.md#restoring-a-file-whose-dashboard-is-already-back-in-place-is-not-a-conflict
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restoring a file whose dashboard is already back in place is not a conflict
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard has already been moved back to its mapped folder
    When I restore it from the trash
    Then the file is back in its mapped folder
    And the dashboard exists exactly once, in its mapped Grafana folder

  # notes: ../AGENTS.md#a-bin-off-restore-cannot-preserve-the-old-dashboards-url-or-history
  @user @in-nextcloud @gesture @ui @recycle-bin @decision
  Scenario: A bin-off restore cannot preserve the old dashboard's URL or history
    Given the Grafana recycle-bin folder is off
    And a trashed sync dashboard file whose dashboard is already deleted
    When I restore it from the trash
    Then the dashboard's previous URL no longer resolves in Grafana
    And the restored dashboard has no version history from before the delete
    And nothing in Grafana that referenced the old uid points at it

  # notes: ../AGENTS.md#moving-a-dashboard-out-of-the-bin-in-grafana-brings-its-file-back-out-of-the-trash

  @grafana @in-grafana @occ @ui @recycle-bin @unbuilt
  Scenario: Moving a dashboard out of the bin in Grafana brings its file back out of the trash
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    When someone moves that dashboard back to its mapped folder in Grafana
    And the "alpha" mapping is pulled
    Then the trashed file is restored rather than a second file being created
    And exactly one file carries that dashboard's uid
    And it holds the dashboard's current content

  # notes: ../AGENTS.md#a-dashboard-reappearing-in-grafana-never-empties-the-nextcloud-trash

  @user @in-nextcloud @gesture @ui @decision
  Scenario: A dashboard reappearing in Grafana never empties the Nextcloud trash
    Given a trashed sync dashboard file
    When the dashboard exists in Grafana again
    And the "alpha" mapping is pulled
    Then the file is still in the Nextcloud trash
    And the user is the only one who can restore it
