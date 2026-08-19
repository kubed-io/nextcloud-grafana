# Notes, decisions and history for this feature: ../AGENTS.md#folderspurge

Feature: Emptying the trash of a folder
  As a Nextcloud user
  I want purging a trashed folder to finish the delete for everything it held
  So that one gesture leaves nothing behind, and takes nothing else with it

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

    # ── RULE: a purge reaches everything the trash gesture put there ──────────
    # notes: ../AGENTS.md#a-purge-reaches-everything-the-trash-gesture-put-there

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Purge a trashed folder with the recycle bin off
    Given the Grafana recycle bin is off
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" is in the Nextcloud trash
    And none of the three dashboards exists in Grafana
    And none of the three files holds a "grafana_uid"
    When I purge "Demo/Team" from the trash
    Then Grafana is not contacted
    And "Demo/Team" is gone from the Nextcloud trash

    # The dashboards went when the folder was trashed and the files lost their ids
    # with them, so there is nothing left to finish on the far side.

  # notes: ../AGENTS.md#what-the-folder-held-is-an-example-not-a-scenario
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario Outline: Purge a trashed folder with the recycle bin on
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding <contents>
    And "Demo/Team" is in the Nextcloud trash
    And every dashboard it held is parked in "nextcloud-trash"
    When I purge "Demo/Team" from the trash
    Then no dashboard it held exists in Grafana
    And "Demo/Team" is gone from the Nextcloud trash

    Examples: recursive is recursive — what was inside makes no difference
      | contents                                     |
      | one dashboard                                |
      | three dashboards                             |
      | a dashboard and a subfolder holding two more |
      | a dashboard and a subfolder holding none     |
      | three dashboards and "Budget.xlsx"           |

    # Three parked dashboards, three deletes. This is where the bin's promise ends
    # and the cascade it was holding back becomes permanent.

    # ── RULE: a purge takes only what was Grafana's ───────────────────────────

  # notes: ../AGENTS.md#a-purge-never-clears-the-bin-folder-wholesale
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Purge a trashed folder while other dashboards are parked
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" is in the Nextcloud trash
    And the three dashboards are parked in "nextcloud-trash"
    And "nextcloud-trash" also holds dashboards Nextcloud never managed
    When I purge "Demo/Team" from the trash
    Then none of the three dashboards exists in Grafana
    And the dashboards Nextcloud never managed are still in "nextcloud-trash"

    # A folder purge is still a set of individual deletes, so it can no more clear
    # the bin wholesale than purging one file can.

    # A link folder has no scenario in this file, deliberately: it cannot be trashed,
    # so it can never be in the trash to purge — see folders/delete.feature.

    # ── RULE: a purge in the Grafana bin finishes the delete from that side ───
    # notes: ../AGENTS.md#a-purge-in-the-grafana-bin-reaches-back-into-the-nextcloud-trash

  @grafana @in-grafana @gesture @ui @recycle-bin @unbuilt
  Scenario: Purge a trashed folder's dashboards in the Grafana bin
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" is in the Nextcloud trash
    And the three dashboards are parked in "nextcloud-trash"
    When someone deletes those three dashboards from "nextcloud-trash" in Grafana
    Then "Demo/Team" is gone from the Nextcloud trash

    # The parked dashboards were the only thing a restore could have brought back,
    # so with them gone the trashed mirror has nothing left to be restored to.

  # notes: ../AGENTS.md#a-grafana-purge-may-not-destroy-what-was-never-grafanas
  @grafana @in-grafana @gesture @ui @recycle-bin @unbuilt
  Scenario: Purge a trashed folder's dashboards in the Grafana bin, where the mirror holds other files
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" also holds "Budget.xlsx"
    And "Demo/Team" is in the Nextcloud trash
    And the three dashboards are parked in "nextcloud-trash"
    When someone deletes those three dashboards from "nextcloud-trash" in Grafana
    Then the three dashboard files are gone from the Nextcloud trash
    And "Demo/Team" is still in the Nextcloud trash, holding "Budget.xlsx"

    # The same respect the Grafana-side folder delete shows: a spreadsheet has no
    # far side, so nothing that happened in Grafana may destroy it.
