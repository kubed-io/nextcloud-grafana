# Notes, decisions and history for this feature: ../AGENTS.md#folderspurge

Feature: Emptying the trash of a folder
  As a Nextcloud user
  I want purging a trashed folder to finish the delete for everything it held
  So that one gesture leaves nothing behind, and takes nothing else with it

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | demo |
      | nc folder      | Demo |
      | mode           | sync |
    And a mapping with the following values:
      | grafana folder | links    |
      | nc folder      | Pointers |
      | mode           | link     |
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

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Purge a trashed folder with the recycle bin on
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" is in the Nextcloud trash
    And the three dashboards are parked in "nextcloud-trash"
    And each of the three files still holds its "grafana_uid"
    When I purge "Demo/Team" from the trash
    Then none of the three dashboards exists in Grafana
    And "Demo/Team" is gone from the Nextcloud trash

    # Three parked dashboards, three deletes. This is where the bin's promise ends
    # and the cascade it was holding back becomes permanent.

  # notes: ../AGENTS.md#a-purge-reaches-through-every-level-it-was-given
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Purge a trashed folder of nested folders
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding a dashboard
    And the folder "Demo/Team/Drafts" holding two dashboards
    And "Demo/Team" is in the Nextcloud trash
    And the three dashboards are parked in "nextcloud-trash"
    When I purge "Demo/Team" from the trash
    Then none of the three dashboards exists in Grafana
    And "Demo/Team" is gone from the Nextcloud trash

    # Depth is not a special case: the purge reaches whatever the trash held, and
    # the trash held the whole subtree.

    # ── RULE: a purge takes only what was Grafana's ───────────────────────────

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Purge a trashed folder holding other files too
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" also holds "Budget.xlsx"
    And "Demo/Team" is in the Nextcloud trash
    And the three dashboards are parked in "nextcloud-trash"
    When I purge "Demo/Team" from the trash
    Then none of the three dashboards exists in Grafana
    And the dashboards Nextcloud never managed are still in "nextcloud-trash"

    # The spreadsheet has no far side, so it goes the way any purged file goes and
    # takes nothing with it.

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Purge a trashed folder from a link mapping
    Given the folder "Pointers/Team" holding three dashboards
    And "Pointers/Team" is in the Nextcloud trash
    When I purge "Pointers/Team" from the trash
    Then all three dashboards still exist in Grafana
    And the Grafana folder "Team" is still under "links"

    # A link never owned the dashboards, so finishing the delete on the Nextcloud
    # side finishes all of it.
