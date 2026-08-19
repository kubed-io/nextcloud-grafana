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

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Move a folder within its own mapping
    Given the folder "Demo/Team" holding three dashboards
    When I move "Demo/Team" into "Demo/Archive"
    Then "Demo/Archive/Team" holds the same files it held before the move
    And the Grafana folder "Team" is under "Archive", holding the same dashboards
    And "Demo/Archive/Team" holds:
      | grafana_folder_uid | the original id |

    # Without the uid this reads as a folder disappearing and another appearing, and
    # three dashboards would be deleted and re-created under new ones.

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Move a folder into another mapping
    Given the folder "Demo/Team" holding three dashboards
    When I move "Demo/Team" into "Reports"
    Then "Reports/Team" holds the same files it held before the move
    And the Grafana folder "Team" is under "Reports", holding the same dashboards
    And "Reports/Team" holds:
      | grafana_folder_uid | the original id |

    # ── RULE: leaving the mapped set is leaving it, dashboard by dashboard ────
    # notes: ../AGENTS.md#the-recycle-bin-folder

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Move a folder out of every mapping with the recycle bin off
    Given the Grafana recycle bin is off
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
    Given the Grafana recycle bin is on
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
    And the Grafana folder "Team" is under "Demo", holding a dashboard for each of them
    And "Demo/Team" holds:
      | grafana_folder_uid | the uid of the "Team" Grafana folder |

    # ── RULE: a folder move is not a way around the link guard ────────────────
    # notes: ../AGENTS.md#a-copy-never-changes-a-files-mode

  @user @in-nextcloud @gesture @ui @todo
  Scenario Outline: Move a folder between sync and link mappings
    Given the folder "<source>/Team" holding three dashboards
    When I try to move "<source>/Team" into "<destination>"
    Then the move is refused with a message
    And "<source>/Team" stays where it was

    Examples: a mode belongs to the folder, and a folder move may not change one
      | source   | destination |
      | Demo     | Pointers    |
      | Pointers | Demo        |

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Move a folder of links out of its mapping
    Given the folder "Pointers/Team" holding three dashboards
    When I try to move "Pointers/Team" into "Scratch"
    Then the move is refused with a message
    And all three dashboards still exist in Grafana

    # ── RULE: a folder moved in Grafana moves in Nextcloud ────────────────────
    # notes: ../AGENTS.md#a-folder-moved-in-grafana-is-recognised-by-its-uid

  @grafana @in-grafana @gesture @ui @todo
  Scenario: Move a folder in Grafana
    Given the folder "Demo/Team" holding three dashboards
    And the folder "Demo/Archive" holding no dashboards
    When someone moves the "Team" Grafana folder under "Archive"
    Then "Demo/Archive/Team" holds the same files "Demo/Team" did
    And "Demo/Team" is gone from Nextcloud
    And "Demo/Archive/Team" holds:
      | grafana_folder_uid | the original id |

    # Read by name this is one folder vanishing and another appearing; read by uid
    # it is one folder with a new parent.

  # notes: ../AGENTS.md#a-move-and-a-rename-at-once-is-just-a-move
  @grafana @in-grafana @gesture @ui @todo
  Scenario: Move and rename a folder in Grafana at once
    Given the folder "Demo/Team" holding three dashboards
    And the folder "Demo/Archive" holding no dashboards
    When someone moves the "Team" Grafana folder under "Archive" and renames it "Squad"
    Then "Demo/Archive/Squad" holds the same files "Demo/Team" did
    And "Demo/Team" is gone from Nextcloud
    And "Demo/Archive/Squad" holds:
      | grafana_folder_uid | the original id |

    # Grafana can do both in one go where a Files gesture cannot. The uid makes it
    # one move to a new place under a new name, not a delete and a create.
