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
  Scenario Outline: Restore a folder with the recycle bin on
    Given the Grafana recycle bin is on
    And the following items in the mappings:
      | path                         |
      | /<folder>/Team/Alpha.grafana |
      | /<folder>/Team/Beta.grafana  |
      | /<folder>/Team/Budget.xlsx   |
    And "<folder>/Team" is in the Nextcloud trash
    When I restore "<folder>/Team" from the Nextcloud trash
    Then "<folder>/Team" is back in Grafana
    And the mappings hold:
      | path                         | identity        |
      | /<folder>/Team/Alpha.grafana | the original id |
      | /<folder>/Team/Beta.grafana  | the original id |
      | /<folder>/Team/Budget.xlsx   | NA              |

    Examples: the storage a mapping uses makes no difference to what a restore is
      | folder |
      | Demo   |
      | Shared |

    # Nothing was destroyed, so nothing is rebuilt — and the folder comes back whole,
    # spreadsheet included, because the gesture was Nextcloud's own.

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario Outline: Restore a folder with the recycle bin off
    Given the Grafana recycle bin is off
    And the following items in the mappings:
      | path                         |
      | /<folder>/Team/Alpha.grafana |
      | /<folder>/Team/Beta.grafana  |
    And "<folder>/Team" is in the Nextcloud trash
    When I restore "<folder>/Team" from the Nextcloud trash
    Then "<folder>/Team" is back in Grafana
    And the mappings hold:
      | path                         | identity |
      | /<folder>/Team/Alpha.grafana | a new id |
      | /<folder>/Team/Beta.grafana  | a new id |

    Examples: and it makes none to a rebuild either
      | folder |
      | Demo   |
      | Shared |

    # The dashboards went at trash time, so a restore can only build new ones from
    # the files — the bodies survive, the identities cannot.

    # ── RULE: dashboards coming back in Grafana bring their folder with them ──
    # notes: ../AGENTS.md#dashboards-leaving-the-bin-bring-their-folder-out-of-the-trash

  @grafana @in-grafana @gesture @ui @recycle-bin
  Scenario: Restore a trashed folder's dashboards from the Grafana bin
    Given the Grafana recycle bin is on
    And the following items in the mappings:
      | path                        |
      | /Demo/Revived/Alpha.grafana |
      | /Demo/Revived/Beta.grafana  |
    And "Demo/Revived" is in the Nextcloud trash
    When someone moves "Revived" from "nextcloud-trash" back under "Demo" in Grafana
    Then "Demo/Revived" is gone from the Nextcloud trash
    And the mappings hold:
      | path                        | identity        |
      | /Demo/Revived/Alpha.grafana | the original id |
      | /Demo/Revived/Beta.grafana  | the original id |

    # The uids name files that already exist in the trash, so they are restored
    # rather than written a second time beside them.

  # notes: ../AGENTS.md#a-restore-in-grafana-speaks-for-dashboards-and-nothing-else
  @grafana @in-grafana @gesture @ui @recycle-bin
  Scenario: Restore a trashed folder's dashboards from the Grafana bin, where the folder held other files
    Given the Grafana recycle bin is on
    And the following items in the mappings:
      | path                        |
      | /Demo/Rescued/Alpha.grafana |
      | /Demo/Rescued/Budget.xlsx   |
    And "Demo/Rescued" is in the Nextcloud trash
    When someone moves "Rescued" from "nextcloud-trash" back under "Demo" in Grafana
    Then "Demo/Rescued/Budget.xlsx" is still in the Nextcloud trash
    And the mappings hold:
      | path                        | identity        |
      | /Demo/Rescued/Alpha.grafana | the original id |

    # A gesture in Grafana speaks for dashboards and nothing else. The spreadsheet has
    # no far side, so it stays where the user's own trash gesture put it.
