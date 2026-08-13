# Notes, decisions and history for this feature: ../AGENTS.md#folderscopy

Feature: Copying a folder
  As a Nextcloud user
  I want a copied folder to be a fresh folder, never a second claim on the original
  So that duplicating a folder cannot leave two files fighting over one dashboard

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Demo         |
      | nc folder      | Demo         |
      | mode           | sync         |
      | storage        | admin folder |
    And a mapping with the following values:
      | grafana folder | Shared      |
      | nc folder      | Shared      |
      | mode           | sync        |
      | storage        | team folder |
      | groups         | admin       |
    And a mapping with the following values:
      | grafana folder | links    |
      | nc folder      | Pointers |
      | mode           | link     |
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a copied folder is a new folder, holding new dashboards ─────────
    # notes: ../AGENTS.md#a-copied-folder-is-a-new-folder

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario Outline: Copy a folder inside its mapping
    Given the folder "<folder>/Team" holding three dashboards
    When I copy "<folder>/Team" to "<folder>/Team copy"
    Then "<folder>/Team copy" holds the same files "<folder>/Team" does
    And the Grafana folder "Team copy" is under "<grafana folder>", holding three dashboards
    And "<folder>/Team copy" holds:
      | grafana_folder_uid | its own, not the original's |
    And "<folder>/Team" holds:
      | grafana_folder_uid | the uid it had before the copy |

    Examples: the storage a mapping uses makes no difference to what a copy is
      | folder | grafana folder |
      | Demo   | demo           |
      | Shared | shared         |

    # No dashboard is ever claimed twice: the copies are new dashboards with their
    # own uids, in a new Grafana folder with its own.

    # ── RULE: a copy outside every mapping is an ordinary folder ──────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Copy a folder out of every mapping
    Given the folder "Demo/Team" holding three dashboards
    When I copy "Demo/Team" to "Scratch/Team"
    Then "Scratch/Team" holds the same files "Demo/Team" does
    And Grafana is not contacted
    And "Scratch/Team" holds:
      | grafana_folder_uid | absent |

    # ── RULE: a link is read-only, so a copy neither enters nor leaves one ────
    # notes: ../AGENTS.md#a-copy-never-changes-a-files-mode

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario Outline: Copy a folder between sync and link mappings
    Given the folder "<source>/Team" holding three dashboards
    When I try to copy "<source>/Team" to "<destination>/Team"
    Then the copy is refused with a message
    And "<destination>" holds no folder named "Team"

    Examples: a mode belongs to the folder, and a copy may not change one
      | source   | destination |
      | Demo     | Pointers    |
      | Pointers | Demo        |

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Copy a folder inside a link mapping
    Given the folder "Pointers/Team" holding three dashboards
    When I try to copy "Pointers/Team" to "Pointers/Team copy"
    Then the copy is refused with a message
    And "Pointers" holds no folder named "Team copy"

    # A link folder is Grafana's to write. Copying one would have to author three
    # dashboards into a mapping that never writes back.

    # ── RULE: there is no such thing as a copy made in Grafana ────────────────
    # notes: ../AGENTS.md#a-folder-copied-in-grafana-is-indistinguishable-from-a-new-one

  @grafana @in-grafana @decision
  Scenario: A folder duplicated in Grafana arrives as a new folder
    Given the folder "Demo/Team" holding three dashboards
    When someone creates a folder under "Demo" holding copies of those dashboards
    Then it arrives in Nextcloud as an ordinary new folder
    And nothing marks it as a copy of "Demo/Team"

    # Grafana has no duplicate-folder call, so a "copy" there is a create plus
    # creates — and nothing distinguishes it from any other new folder.

    # ── RULE: a copy Grafana will not take creates nothing ────────────────────

  @user @in-nextcloud @gesture @ui @blocked
  Scenario: Copy a folder while Grafana is unreachable
    Given the folder "Demo/Team" holding three dashboards
    And Grafana is unreachable
    When I copy "Demo/Team" to "Demo/Team copy"
    Then the failure is reported to the user
    And Grafana holds no folder named "Team copy"
    And "Demo/Team copy" holds:
      | grafana_folder_uid | absent |
