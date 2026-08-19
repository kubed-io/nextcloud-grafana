# Notes, decisions and history for this feature: ../AGENTS.md#foldersdelete

Feature: Deleting a folder
  As a Nextcloud user
  I want deleting a folder to be as safe and as legible as deleting one dashboard
  So that one gesture cannot quietly destroy many dashboards

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
      | /Demo/notes.txt             |
      | /Shared/Coast/Tides.grafana |
      | /Pointers/Pinned.grafana    |
    And a folder "Scratch" that is not mapped
    And the Grafana recycle-bin folder is named "nextcloud-trash"

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: trashing a folder is trashing each dashboard in it ──────────────
    # notes: ../AGENTS.md#the-recycle-bin-folder

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario Outline: Trash a folder of dashboards with the recycle bin off
    Given the Grafana recycle bin is off
    And the following items in the mappings:
      | path                         |
      | /<folder>/Team/Alpha.grafana |
      | /<folder>/Team/Beta.grafana  |
    When I move "<folder>/Team" to the trash
    Then none of those dashboards exists in Grafana
    And "<folder>/Team" is recoverable from the Nextcloud trash

    Examples: the storage a mapping uses makes no difference to what a trash is
      | folder |
      | Demo   |
      | Shared |

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Trash a folder of dashboards with the recycle bin on
    Given the Grafana recycle bin is on
    And the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
    When I move "Demo/Team" to the trash
    Then those dashboards are parked in "nextcloud-trash"
    And "Demo/Team" is recoverable from the Nextcloud trash

    # The bin decides what happens to the DASHBOARDS. The folder goes either way:
    # trashing it is a delete, and a delete carries whatever the folder held.

  # notes: ../AGENTS.md#a-link-folder-cannot-be-trashed-either
  @user @in-nextcloud @gesture @ui
  Scenario: Trashing a folder in a link mapping is refused
    Given the following items in the mappings:
      | path                         |
      | /Pointers/Team/Alpha.grafana |
      | /Pointers/Team/Beta.grafana  |
    When I try to move "Pointers/Team" to the trash
    Then the trash is refused with a message
    And Grafana mirrors the folder "Pointers/Team"

    # The same refusal a single link gets, for the same reason: under a link the
    # tree is Grafana's, and Nextcloud is a read-only mirror of it.

    # ── RULE: a folder deleted in Grafana takes only what is Grafana's ────────
    # notes: ../AGENTS.md#when-a-folder-deleted-in-grafana-may-delete-the-nextcloud-folder

  @grafana @in-grafana @gesture @ui
  Scenario: Delete a folder in Grafana whose mirror holds only dashboards
    Given the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
    When someone deletes the "Demo/Team" folder in Grafana
    Then "Demo/Team" is gone from Nextcloud

  @grafana @in-grafana @gesture @ui
  Scenario: Delete a folder in Grafana whose mirror holds other files too
    Given the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
      | /Demo/Team/Budget.xlsx   |
    When someone deletes the "Demo/Team" folder in Grafana
    Then "Demo/Team" holds no dashboard files
    And the mappings hold:
      | path       | identity |
      | /Demo/Team | absent   |

    # It stops being a mirror and goes back to being an ordinary folder. Deleting a
    # user's spreadsheets because a Grafana folder went away is not the app's call.
