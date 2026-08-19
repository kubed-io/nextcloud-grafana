# Notes, decisions and history for this feature: ../AGENTS.md#folderscopy

Feature: Copying a folder
  As a Nextcloud user
  I want a copied folder to be a fresh folder, never a second claim on the original
  So that duplicating a folder cannot leave two files fighting over one dashboard

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

  # notes: ../AGENTS.md#the-background-is-the-neighbourhood-not-the-subject
  # notes: ../AGENTS.md#the-two-sides-already-agree

    # ── RULE: a copied folder is a new folder, holding new dashboards ─────────
    # notes: ../AGENTS.md#a-copied-folder-is-a-new-folder

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Copy a folder within a mapped folder
    Given the following items in the mappings:
      | path                         |
      | /<folder>/Team/Alpha.grafana |
      | /<folder>/Team/Beta.grafana  |
      | /<folder>/Team/Gamma.grafana |
    When I copy "<folder>/Team" to "<folder>/Team copy"
    Then the mappings hold:
      | path                              | identity                       |
      | /<folder>/Team                    | the uid it had before the copy |
      | /<folder>/Team/Alpha.grafana      | the uid it had before the copy |
      | /<folder>/Team copy               | its own, not the original's    |
      | /<folder>/Team copy/Alpha.grafana | its own, not the original's    |
      | /<folder>/Team copy/Beta.grafana  | its own, not the original's    |
      | /<folder>/Team copy/Gamma.grafana | its own, not the original's    |

    Examples: the storage a mapping uses makes no difference to what a copy is
      | folder |
      | Demo   |
      | Shared |

    # ── RULE: a copy outside every mapping is an ordinary folder ──────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Copy a folder out of every mapping
    Given the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
      | /Demo/Team/Beta.grafana  |
      | /Demo/Team/Gamma.grafana |
    When I copy "Demo/Team" to "Scratch/Team"
    Then the mappings hold:
      | path                        | identity |
      | /Scratch/Team               | absent   |
      | /Scratch/Team/Alpha.grafana | absent   |
      | /Scratch/Team/Beta.grafana  | absent   |
      | /Scratch/Team/Gamma.grafana | absent   |

    # ── RULE: a link is read-only, so a copy neither enters nor leaves one ────
    # notes: ../AGENTS.md#a-copy-never-changes-a-files-mode

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Copying a folder between sync and link mappings is refused
    Given the following items in the mappings:
      | path                         |
      | /<source>/Team/Alpha.grafana |
      | /<source>/Team/Beta.grafana  |
      | /<source>/Team/Gamma.grafana |
    When I try to copy "<source>/Team" to "<destination>/Team copy"
    Then the copy is refused with a message
    And "<destination>" holds no folder named "Team copy"

    Examples: a mode belongs to the folder, and a copy may not change one
      | source   | destination |
      | Demo     | Pointers    |
      | Pointers | Demo        |

  # notes: ../AGENTS.md#copying-a-folder-inside-a-link-mapping-is-refused
  @user @in-nextcloud @gesture @ui
  Scenario: Copying a folder inside a link mapping is refused
    Given the following items in the mappings:
      | path                         |
      | /Pointers/Team/Alpha.grafana |
      | /Pointers/Team/Beta.grafana  |
      | /Pointers/Team/Gamma.grafana |
    When I try to copy "Pointers/Team" to "Pointers/Team copy"
    Then the copy is refused with a message
    And "Pointers" holds no folder named "Team copy"

    # ── RULE: a copy Grafana will not take creates nothing ────────────────────

  @user @in-nextcloud @gesture @ui @blocked
  Scenario: Copy a folder while Grafana is unreachable
    Given the following items in the mappings:
      | path                     |
      | /Demo/Team/Alpha.grafana |
    And Grafana is unreachable
    When I copy "Demo/Team" to "Demo/Team copy"
    Then the failure is reported to the user
    And Grafana holds no folder named "Team copy"
    And "Demo/Team copy" holds:
      | grafana_folder_uid | absent |
