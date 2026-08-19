# Notes, decisions and history for this feature: ../AGENTS.md#foldersmove

Feature: Moving a folder
  As a Nextcloud user
  I want moving a folder to have a predictable effect on the dashboards inside it
  So that reorganising my files cannot silently delete or orphan them

  Background:
    Given the app is connected to Grafana
    And the following mappings were made:
      | grafana folder | nc folder | mode | storage      | groups |
      | Demo           | Demo      | sync | admin folder |        |
      | Reports        | Reports   | sync | admin folder |        |
      | metrics        | Shared    | sync | team folder  | admin  |
      | links          | Pointers  | link | admin folder |        |
    And the following items in the mappings:
      | path                        |
      | /Demo/Overview.grafana      |
      | /Reports/Quarterly.grafana  |
      | /Shared/Coast/Tides.grafana |
      | /Pointers/Pinned.grafana    |
    And a folder "Scratch" that is not mapped
    And the Grafana recycle-bin folder is named "nextcloud-trash"

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: the folder's uid is what makes a move a move ────────────────────
    # notes: ../AGENTS.md#a-nextcloud-folder-carries-its-grafana-folder-uid

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Move a folder within its own mapping
    Given the following items in the mappings:
      | path                         |
      | /<folder>/Team/Alpha.grafana |
      | /<folder>/Team/Beta.grafana  |
      | /<folder>/Archive            |
    When I move "<folder>/Team" into "<folder>/Archive"
    Then Grafana mirrors the folder "<folder>/Archive/Team"
    And the mappings hold:
      | path                                 | identity        |
      | /<folder>/Archive/Team               | the original id |
      | /<folder>/Archive/Team/Alpha.grafana | the original id |
      | /<folder>/Archive/Team/Beta.grafana  | the original id |

    Examples: the storage a mapping uses makes no difference to what a move is
      | folder |
      | Demo   |
      | Shared |

    # Without the uid this reads as a folder disappearing and another appearing, and
    # two dashboards would be deleted and re-created under new ones.

  @user @in-nextcloud @gesture @ui
  Scenario: Move a folder into another mapping
    Given the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
    When I move "Demo/Team" into "Reports"
    Then Grafana mirrors the folder "Reports/Team"
    And the mappings hold:
      | path                        | identity        |
      | /Reports/Team               | the original id |
      | /Reports/Team/Alpha.grafana | the original id |
      | /Reports/Team/Beta.grafana  | the original id |

    # ── RULE: leaving the mapped set is leaving it, dashboard by dashboard ────
    # notes: ../AGENTS.md#the-recycle-bin-folder

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Move a folder out of every mapping with the recycle bin off
    Given the Grafana recycle bin is off
    And the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
    When I move "Demo/Team" into "Scratch"
    Then none of those dashboards exists in Grafana
    And the mappings hold:
      | path                        | identity |
      | /Scratch/Team               | absent   |
      | /Scratch/Team/Alpha.grafana | absent   |
      | /Scratch/Team/Beta.grafana  | absent   |

    # Nothing is lost: the files keep their bodies, so the dashboards can be made
    # again by moving the folder back into a mapping.

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Move a folder out of every mapping with the recycle bin on
    Given the Grafana recycle bin is on
    And the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
    When I move "Demo/Team" into "Scratch"
    Then those dashboards are parked in "nextcloud-trash"

  # notes: ../AGENTS.md#a-parked-folder-coming-back
  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Move a parked folder back into its mapping
    Given the Grafana recycle bin is on
    And the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
    And I move "Demo/Team" into "Scratch"
    When I move "Scratch/Team" into "Demo"
    Then Grafana mirrors the folder "Demo/Team"
    And the mappings hold:
      | path                     | identity        |
      | /Demo/Team/Alpha.grafana | the original id |
      | /Demo/Team/Beta.grafana  | the original id |

    # The bin held them, so coming back is an un-parking rather than a rebuild —
    # the dashboards keep the ids, URLs and history they left with.

    # ── RULE: arriving in a mapping makes every dashboard in it real ──────────

  @user @in-nextcloud @gesture @ui
  Scenario: Move a folder of unmapped files into a mapping
    Given Nextcloud holds these resources:
      | path                        |
      | /Scratch/Team/Alpha.grafana |
      | /Scratch/Team/Beta.grafana  |
    When I move "Scratch/Team" into "Demo"
    Then Grafana mirrors the folder "Demo/Team"
    And the mappings hold:
      | path                     | identity |
      | /Demo/Team/Alpha.grafana | a new id |
      | /Demo/Team/Beta.grafana  | a new id |

    # ── RULE: a folder move is not a way around the link guard ────────────────
    # notes: ../AGENTS.md#a-copy-never-changes-a-files-mode

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Moving a folder between sync and link mappings is refused
    Given the following items in the mappings:
      | path                         |
      | /<source>/Team/Alpha.grafana |
      | /<source>/Team/Beta.grafana  |
    When I try to move "<source>/Team" into "<destination>"
    Then the move is refused with a message
    And "<destination>" holds no folder named "Team"

    Examples: a mode belongs to the folder, and a folder move may not change one
      | source   | destination |
      | Demo     | Pointers    |
      | Pointers | Demo        |

  @user @in-nextcloud @gesture @ui
  Scenario: Moving a folder of links out of its mapping is refused
    Given the following items in the mappings:
      | path                         |
      | /Pointers/Team/Alpha.grafana |
      | /Pointers/Team/Beta.grafana  |
    When I try to move "Pointers/Team" into "Scratch"
    Then the move is refused with a message
    And "Scratch" holds no folder named "Team"
    And Grafana mirrors the folder "Pointers/Team"

    # ── RULE: a folder moved in Grafana moves in Nextcloud ────────────────────
    # notes: ../AGENTS.md#a-folder-moved-in-grafana-is-recognised-by-its-uid

  @grafana @in-grafana @gesture @ui
  Scenario Outline: Move a folder in Grafana
    Given the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
      | /Demo/Archive            |
    When someone moves the "Demo/Team" Grafana folder under "Demo/Archive" as "<name>"
    Then "Demo/Team" is gone from Nextcloud
    And Grafana mirrors the folder "Demo/Archive/<name>"
    And the mappings hold:
      | path                               | identity        |
      | /Demo/Archive/<name>               | the original id |
      | /Demo/Archive/<name>/Alpha.grafana | the original id |

    Examples: Grafana can move and rename in one call where a Files gesture cannot
      | name  |
      | Team  |
      | Squad |

    # notes: ../AGENTS.md#a-move-and-a-rename-at-once-is-just-a-move
    # Read by name this is a folder vanishing; read by uid it is one folder moving.

    # ── RULE: a move Grafana will not take leaves the local one standing ──────

  @user @in-nextcloud @gesture @ui @blocked
  Scenario: Move a folder out of its mapping while Grafana is unreachable
    Given the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
    And Grafana is unreachable
    When I move "Demo/Team" into "Scratch"
    Then the failure is reported to the user
    And "Scratch/Team" holds the same files "Demo/Team" did
