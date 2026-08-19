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

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Restore a folder with the recycle bin on
    Given the Grafana recycle bin is on
    And the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
    And "Demo/Team" is in the Nextcloud trash
    When I restore "Demo/Team" from the Nextcloud trash
    Then Grafana mirrors the folder "Demo/Team"
    And the mappings hold:
      | path                     | identity        |
      | /Demo/Team/Alpha.grafana | the original id |
      | /Demo/Team/Beta.grafana  | the original id |

  # notes: ../AGENTS.md#restoring-a-folder-in-a-team-folder
  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Restore a folder in a Team Folder
    Given the Grafana recycle bin is on
    And the following items in the mappings:
      | path                       |
      | /Shared/Team/Alpha.grafana |
      | /Shared/Team/Beta.grafana  |
    And "Shared/Team" is in the Nextcloud trash
    When I restore "Shared/Team" from the Nextcloud trash
    Then Grafana mirrors the folder "Shared/Team"
    And the mappings hold:
      | path                       | identity        |
      | /Shared/Team/Alpha.grafana | the original id |

    # Nothing was destroyed, so nothing has to be rebuilt: the dashboards come back
    # with the ids, URLs and history they always had.

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Restore a folder with the recycle bin off
    Given the Grafana recycle bin is off
    And the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
    And "Demo/Team" is in the Nextcloud trash
    When I restore "Demo/Team" from the Nextcloud trash
    Then Grafana mirrors the folder "Demo/Team"
    And the mappings hold:
      | path                     | identity |
      | /Demo/Team/Alpha.grafana | a new id |
      | /Demo/Team/Beta.grafana  | a new id |

    # The dashboards went at trash time, so a restore can only build new ones from
    # the files — the bodies survive, the identities cannot.

    # ── RULE: dashboards coming back in Grafana bring their folder with them ──
    # notes: ../AGENTS.md#dashboards-leaving-the-bin-bring-their-folder-out-of-the-trash

  @grafana @in-grafana @gesture @ui @recycle-bin @unbuilt
  Scenario: Move a trashed folder's dashboards out of the bin in Grafana
    Given the Grafana recycle bin is on
    And the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
    And "Demo/Team" is in the Nextcloud trash
    When someone moves those dashboards back into "Demo/Team" in Grafana
    Then Grafana mirrors the folder "Demo/Team"
    And the mappings hold:
      | path                     | identity        |
      | /Demo/Team/Alpha.grafana | the original id |

    # The uids name files that already exist in the trash, so they are restored
    # rather than written a second time beside them.

  # notes: ../AGENTS.md#a-restore-out-of-the-bin-brings-back-whatever-shared-the-folder
  @grafana @in-grafana @gesture @ui @recycle-bin @unbuilt
  Scenario: Move a trashed folder's dashboards out of the bin, where the folder held other files
    Given the Grafana recycle bin is on
    And the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Budget.xlsx   |
    And "Demo/Team" is in the Nextcloud trash
    When someone moves those dashboards back into "Demo/Team" in Grafana
    Then "Demo/Team" holds "Budget.xlsx"

    # A folder comes out of the Nextcloud trash whole — the spreadsheet rode in with
    # it and rides back out.
