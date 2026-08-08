# Notes, decisions and history for this feature: ../AGENTS.md#foldersrestore

Feature: Restoring a folder from the trash
  As a Nextcloud user
  I want restoring a folder to bring its dashboards back predictably
  So that one gesture cannot silently re-mint every dashboard it held

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ══ RESTORED IN NEXTCLOUD ══════════════════════════════════════════════════════

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restoring a trashed subfolder brings its dashboards back with the same ids (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed subfolder whose three dashboards are parked in "nextcloud-trash"
    When I restore the subfolder from the trash
    Then all three dashboards are back in the "alpha" Grafana folder
    And each keeps the uid it had before

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restoring a trashed subfolder re-creates its dashboards with new ids (bin off)
    Given the Grafana recycle-bin folder is off
    And a trashed subfolder holding three dashboard files whose dashboards were deleted
    When I restore the subfolder from the trash
    Then three dashboards exist in Grafana holding the files' content
    And each has a new uid

  # notes: ../AGENTS.md#a-folder-restore-reports-which-dashboards-came-back-with-new-identities
  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: A folder restore reports which dashboards came back with new identities
    Given the Grafana recycle-bin folder is off
    And a trashed subfolder holding three dashboard files whose dashboards were deleted
    When I restore the subfolder from the trash
    Then the user is told that three dashboards were re-created with new uids

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A folder restore that fails part-way reports what did and did not come back
    Given a trashed subfolder holding three dashboard files whose dashboards were deleted
    And Grafana will reject the creation of one of them
    When I restore the subfolder from the trash
    Then the user is told which dashboards came back and which did not
    And the files that failed carry no "grafana_uid"
