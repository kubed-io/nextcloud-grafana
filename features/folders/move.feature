# Notes, decisions and history for this feature: ../AGENTS.md#foldersmove

Feature: Moving a folder
  As a Nextcloud user
  I want moving a folder to have a predictable effect on the dashboards inside it
  So that reorganising my files cannot silently delete or orphan them

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | demo |
      | nc folder      | Demo |
      | mode           | sync |
    And a mapping with the following values:
      | grafana folder | reports |
      | nc folder      | Reports |
      | mode           | sync    |
    And a mapping with the following values:
      | grafana folder | links    |
      | nc folder      | Pointers |
      | mode           | link     |
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: the folder's uid is what makes a move a move ────────────────────
    # notes: ../AGENTS.md#a-nextcloud-folder-carries-its-grafana-folder-uid

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Move a folder within its own mapping
    Given the folder "Demo/Team" holding three dashboards
    When I move "Demo/Team" into "Demo/Archive"
    Then "Demo/Archive/Team" holds the same files it held before the move
    And the Grafana folder "Team" is under "Archive", holding the same dashboards
    And "Demo/Archive/Team" holds:
      | grafana_folder_uid | the uid it had before the move |

    # Without the uid this reads as a folder disappearing and another appearing, and
    # three dashboards would be deleted and re-created under new ones.

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Move a folder into another mapping
    Given the folder "Demo/Team" holding three dashboards
    When I move "Demo/Team" into "Reports"
    Then "Reports/Team" holds the same files it held before the move
    And the Grafana folder "Team" is under "reports", holding the same dashboards
    And "Reports/Team" holds:
      | grafana_folder_uid | the uid it had before the move |

    # ── RULE: leaving the mapped set is leaving it, dashboard by dashboard ────
    # notes: ../AGENTS.md#the-recycle-bin-folder

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Move a folder out of every mapping with the recycle bin off
    Given the Grafana recycle-bin folder is off
    And the folder "Demo/Team" holding three dashboards
    When I move "Demo/Team" into "Scratch"
    Then "Scratch/Team" holds the same files it held before the move
    And none of those dashboards exists in Grafana
    And "Scratch/Team" holds:
      | grafana_folder_uid | absent |

    # Nothing is lost: the files keep their bodies, so the dashboards can be made
    # again by moving the folder back into a mapping.

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Move a folder out of every mapping with the recycle bin on
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And the folder "Demo/Team" holding three dashboards
    When I move "Demo/Team" into "Scratch"
    Then "Scratch/Team" holds the same files it held before the move
    And those dashboards are in the "nextcloud-trash" Grafana folder

    # ── RULE: arriving in a mapping makes every dashboard in it real ──────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Move a folder of unmapped files into a mapping
    Given the folder "Scratch/Team" holding three dashboards
    When I move "Scratch/Team" into "Demo"
    Then "Demo/Team" holds the same files it held before the move
    And the Grafana folder "Team" is under "demo", holding a dashboard for each of them
    And "Demo/Team" holds:
      | grafana_folder_uid | the uid of the "Team" Grafana folder |

    # ── RULE: a folder move is not a way around the link guard ────────────────
    # notes: ../AGENTS.md#a-copy-never-changes-a-files-mode

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario Outline: Move a folder between sync and link mappings
    Given the folder "<source>/Team" holding three dashboards
    When I try to move "<source>/Team" into "<destination>"
    Then the move is refused with a message
    And "<source>/Team" stays where it was

    Examples: a mode belongs to the folder, and a folder move may not change one
      | source   | destination |
      | Demo     | Pointers    |
      | Pointers | Demo        |

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Move a folder of links out of its mapping
    Given the folder "Pointers/Team" holding three dashboards
    When I try to move "Pointers/Team" into "Scratch"
    Then the move is refused with a message
    And all three dashboards still exist in Grafana
