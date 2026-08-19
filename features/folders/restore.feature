# Notes, decisions and history for this feature: ../AGENTS.md#foldersrestore

Feature: Restoring a folder from the trash
  As a Nextcloud user
  I want restoring a folder to bring its dashboards back predictably
  So that one gesture cannot silently re-mint every dashboard it held

  Background:
    Given the app is connected to Grafana
    And the following mappings were made:
      | grafana folder | nc folder | mode | storage      | groups |
      | Demo           | Demo      | sync | admin folder |        |
      | metrics        | Shared    | sync | team folder  | admin  |
      | links          | Pointers  | link | admin folder |        |
    And the following items in the mappings:
      | path                        |
      | /Demo/Overview.grafana      |
      | /Shared/Coast/Tides.grafana |
    And the Grafana recycle-bin folder is named "nextcloud-trash"

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: what the bin kept decides what a restore can give back ──────────
    # notes: ../AGENTS.md#the-recycle-bin-folder

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restore a folder with the recycle bin on
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" is in the Nextcloud trash
    And those three dashboards are in the "nextcloud-trash" Grafana folder
    When I restore "Demo/Team" from the Nextcloud trash
    Then "Demo/Team" holds the same files it held before
    And the Grafana folder "Team" is under "Demo", holding three dashboards
    And each of them kept the uid it had before

    # Nothing was destroyed, so nothing has to be rebuilt: the dashboards come back
    # with the ids, URLs and history they always had.

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restore a folder with the recycle bin off
    Given the Grafana recycle bin is off
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" is in the Nextcloud trash
    And those three dashboards no longer exist in Grafana
    When I restore "Demo/Team" from the Nextcloud trash
    Then "Demo/Team" holds the same files it held before
    And the Grafana folder "Team" is under "Demo", holding three dashboards
    And the user is told that three dashboards came back under new uids

    # The dashboards went at trash time, so a restore can only build new ones from
    # the files — and re-minting three identities is worth saying out loud.

    # ── RULE: dashboards coming back in Grafana bring their folder with them ──
    # notes: ../AGENTS.md#dashboards-leaving-the-bin-bring-their-folder-out-of-the-trash

  @grafana @in-grafana @gesture @ui @recycle-bin @unbuilt
  Scenario: Move a trashed folder's dashboards out of the bin in Grafana
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" is in the Nextcloud trash
    And those three dashboards are in the "nextcloud-trash" Grafana folder
    When someone moves those three dashboards into a "Team" folder under "Demo"
    Then "Demo/Team" is back in Nextcloud, holding the same files
    And each of them kept the uid it had before

    # The uids name files that already exist in the trash, so they are restored
    # rather than written a second time beside them.

  # notes: ../AGENTS.md#a-restore-out-of-the-bin-brings-back-whatever-shared-the-folder
  @grafana @in-grafana @gesture @ui @recycle-bin @unbuilt
  Scenario: Move a trashed folder's dashboards out of the bin, where the folder held other files
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" also holds "Budget.xlsx"
    And "Demo/Team" is in the Nextcloud trash
    And those three dashboards are in the "nextcloud-trash" Grafana folder
    When someone moves those three dashboards into a "Team" folder under "Demo"
    Then "Demo/Team" is back in Nextcloud, holding the same files
    And "Demo/Team" holds "Budget.xlsx"

    # A folder comes out of the Nextcloud trash whole — the spreadsheet rode in with
    # it and rides back out.
