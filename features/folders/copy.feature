# Notes, decisions and history for this feature: ../AGENTS.md#folderscopy

Feature: Copying a folder
  As a Nextcloud user
  I want a copied folder to be a fresh folder, never a second claim on the original
  So that duplicating a folder cannot leave two files fighting over one dashboard

  Background:
    Given the app is connected to Grafana
    And Grafana holds these resources:
      | path                 | type      | tags      |
      | /Demo/Overview       | dashboard | strategic |
      | /metrics/Coast       | folder    |           |
      | /metrics/Coast/Tides | dashboard |           |
      | /links/Pinned        | dashboard | reference |
    And Nextcloud holds these resources:
      | path            |
      | /Demo/notes.txt |
    And the following mappings were made:
      | grafana folder | nc folder | mode | storage      | groups |
      | Demo           | Demo      | sync | admin folder |        |
      | metrics        | Shared    | sync | team folder  | admin  |
      | links          | Pointers  | link | admin folder |        |
    And Grafana and Nextcloud are in sync
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-background-is-the-neighbourhood-not-the-subject
  # notes: ../AGENTS.md#the-mappings-in-the-background
  # None of this is copied; it is what the mappings look like before anyone does.

    # ── RULE: a copied folder is a new folder, holding new dashboards ─────────
    # notes: ../AGENTS.md#a-copied-folder-is-a-new-folder

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Copy a folder within a mapped folder
    Given Grafana holds these resources:
      | path                         | type      |
      | /<grafana folder>/Team       | folder    |
      | /<grafana folder>/Team/Alpha | dashboard |
      | /<grafana folder>/Team/Beta  | dashboard |
      | /<grafana folder>/Team/Gamma | dashboard |
    And Grafana and Nextcloud are in sync
    When I copy "<folder>/Team" to "<folder>/Team copy"
    Then "<folder>/Team copy" holds the same files "<folder>/Team" does
    And Grafana mirrors the folder "<folder>/Team copy"
    And the dashboards in "<folder>/Team copy" are new, not the originals
    And "<folder>/Team copy" holds:
      | grafana_folder_uid | its own, not the original's |
    And "<folder>/Team" holds:
      | grafana_folder_uid | the uid it had before the copy |

    Examples: the storage a mapping uses makes no difference to what a copy is
      | folder | grafana folder |
      | Demo   | Demo           |
      | Shared | metrics        |

    # No dashboard is ever claimed twice: the copies are new dashboards with their
    # own uids, in a new Grafana folder with its own.

    # ── RULE: a copy outside every mapping is an ordinary folder ──────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Copy a folder out of every mapping
    Given Grafana holds these resources:
      | path             | type      |
      | /Demo/Team       | folder    |
      | /Demo/Team/Alpha | dashboard |
      | /Demo/Team/Beta  | dashboard |
      | /Demo/Team/Gamma | dashboard |
    And Grafana and Nextcloud are in sync
    When I copy "Demo/Team" to "Scratch/Team"
    Then "Scratch/Team" holds the same files "Demo/Team" does
    And the dashboards in "Scratch/Team" hold no Grafana metadata at all
    And "Scratch/Team" holds:
      | grafana_folder_uid | absent |

    # ── RULE: a link is read-only, so a copy neither enters nor leaves one ────
    # notes: ../AGENTS.md#a-copy-never-changes-a-files-mode

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Copying a folder between sync and link mappings is refused
    Given Grafana holds these resources:
      | path                         | type      |
      | /<source grafana>/Team       | folder    |
      | /<source grafana>/Team/Alpha | dashboard |
      | /<source grafana>/Team/Beta  | dashboard |
      | /<source grafana>/Team/Gamma | dashboard |
    And Grafana and Nextcloud are in sync
    When I try to copy "<source>/Team" to "<destination>/Team copy"
    Then the copy is refused with a message
    And "<destination>" holds no folder named "Team copy"

    Examples: a mode belongs to the folder, and a copy may not change one
      | source   | source grafana | destination |
      | Demo     | Demo           | Pointers    |
      | Pointers | links          | Demo        |

  # notes: ../AGENTS.md#copying-a-folder-inside-a-link-mapping-is-refused
  @user @in-nextcloud @gesture @ui
  Scenario: Copying a folder inside a link mapping is refused
    Given Grafana holds these resources:
      | path              | type      |
      | /links/Team       | folder    |
      | /links/Team/Alpha | dashboard |
      | /links/Team/Beta  | dashboard |
      | /links/Team/Gamma | dashboard |
    And Grafana and Nextcloud are in sync
    When I try to copy "Pointers/Team" to "Pointers/Team copy"
    Then the copy is refused with a message
    And "Pointers" holds no folder named "Team copy"

    # A link folder is Grafana's to write. Copying one would have to author three
    # dashboards into a mapping that never writes back.

    # ── RULE: a copy Grafana will not take creates nothing ────────────────────

  @user @in-nextcloud @gesture @ui @blocked
  Scenario: Copy a folder while Grafana is unreachable
    Given Grafana holds these resources:
      | path             | type      |
      | /Demo/Team       | folder    |
      | /Demo/Team/Alpha | dashboard |
      | /Demo/Team/Beta  | dashboard |
      | /Demo/Team/Gamma | dashboard |
    And Grafana and Nextcloud are in sync
    And Grafana is unreachable
    When I copy "Demo/Team" to "Demo/Team copy"
    Then the failure is reported to the user
    And Grafana holds no folder named "Team copy"
    And "Demo/Team copy" holds:
      | grafana_folder_uid | absent |
