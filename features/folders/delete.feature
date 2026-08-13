# Notes, decisions and history for this feature: ../AGENTS.md#foldersdelete

Feature: Deleting a folder
  As a Nextcloud user
  I want deleting a folder to be as safe and as legible as deleting one dashboard
  So that one gesture cannot quietly destroy many dashboards

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Demo |
      | nc folder      | Demo |
      | mode           | sync |
    And a mapping with the following values:
      | grafana folder | links    |
      | nc folder      | Pointers |
      | mode           | link     |
    And a folder "Scratch" that is not mapped
    And the Grafana recycle-bin folder is named "nextcloud-trash"

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: trashing a folder is trashing each dashboard in it ──────────────
    # notes: ../AGENTS.md#the-recycle-bin-folder

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Trash a folder of dashboards with the recycle bin off
    Given the Grafana recycle bin is off
    And the folder "Demo/Team" holding three dashboards
    When I move "Demo/Team" to the trash
    Then none of the three dashboards exists in Grafana
    And the Grafana folder "Team" is gone from "Demo"
    And all three files are recoverable from the Nextcloud trash

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Trash a folder of dashboards with the recycle bin on
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding three dashboards
    When I move "Demo/Team" to the trash
    Then all three dashboards are in the "nextcloud-trash" Grafana folder
    And the Grafana folder "Team" is gone from "Demo"
    And all three files are recoverable from the Nextcloud trash

    # The bin decides what happens to the DASHBOARDS. The folder goes either way:
    # trashing it is a delete, and a delete carries whatever the folder held.

  # notes: ../AGENTS.md#a-link-folder-cannot-be-trashed-either
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Trash a folder in a link mapping
    Given the folder "Pointers/Team" holding three dashboards
    When I try to move "Pointers/Team" to the trash
    Then the trash is refused with a message
    And "Pointers/Team" stays where it was
    And the Grafana folder "Team" is still under "links"

    # The same refusal a single link gets, for the same reason: under a link the
    # tree is Grafana's, and Nextcloud is a read-only mirror of it.

    # ── RULE: one gesture, many dashboards — say so before and after ──────────

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Trash a folder of many dashboards
    Given the Grafana recycle bin is off
    And the folder "Demo/Team" holding forty dashboards
    When I move "Demo/Team" to the trash
    Then I am warned that forty dashboards will be permanently deleted in Grafana

    # Deleting one dashboard is a small decision; deleting forty is not, and with the
    # bin off it is irreversible. The app knows the count before it acts.

    # ── RULE: a folder deleted in Grafana takes only what is Grafana's ────────
    # notes: ../AGENTS.md#when-a-folder-deleted-in-grafana-may-delete-the-nextcloud-folder

  @grafana @in-grafana @gesture @ui @unbuilt
  Scenario: Delete a folder in Grafana whose mirror holds only dashboards
    Given the folder "Demo/Team" holding three dashboards
    When someone deletes the "Team" folder in Grafana
    Then "Demo/Team" is gone from Nextcloud
    And it is recoverable from the Nextcloud trash

  @grafana @in-grafana @gesture @ui @unbuilt
  Scenario: Delete a folder in Grafana whose mirror holds other files too
    Given the folder "Demo/Team" holding three dashboards
    And "Demo/Team" also holds "Budget.xlsx"
    When someone deletes the "Team" folder in Grafana
    Then "Demo/Team" still holds "Budget.xlsx"
    And "Demo/Team" holds no dashboard files
    And "Demo/Team" holds:
      | grafana_folder_uid | absent |

    # It stops being a mirror and goes back to being an ordinary folder. Deleting a
    # user's spreadsheets because a Grafana folder went away is not the app's call.
