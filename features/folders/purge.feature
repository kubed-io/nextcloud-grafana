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

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a purge reaches everything the trash gesture put there ──────────
    # notes: ../AGENTS.md#a-purge-reaches-everything-the-trash-gesture-put-there

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario Outline: Purge a trashed folder of dashboards
    Given the Grafana recycle-bin folder is <bin>
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" is in the Nextcloud trash
    When I purge "Demo/Team" from the trash
    Then none of the three dashboards exists in Grafana
    And "Demo/Team" is gone from the Nextcloud trash

    Examples: the bin decides where they waited, not whether they go
      | bin                             |
      | off                             |
      | on and set to "nextcloud-trash" |

    # One gesture, three dashboards. The count is the only thing that differs from
    # purging one file, which is why the bin modes are a row and not two scenarios.

  # notes: ../AGENTS.md#a-purge-reaches-through-every-level-it-was-given
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Purge a trashed folder of nested folders
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And the folder "Demo/Team" holding a dashboard
    And the folder "Demo/Team/Drafts" holding two dashboards
    And "Demo/Team" is in the Nextcloud trash
    When I purge "Demo/Team" from the trash
    Then none of the three dashboards exists in Grafana
    And "Demo/Team" is gone from the Nextcloud trash

    # Depth is not a special case: the purge reaches whatever the trash held, and
    # the trash held the whole subtree.

    # ── RULE: a purge takes only what was Grafana's ───────────────────────────

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Purge a trashed folder holding other files too
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" also holds "Budget.xlsx"
    And "Demo/Team" is in the Nextcloud trash
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
