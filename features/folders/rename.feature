# Notes, decisions and history for this feature: ../AGENTS.md#foldersrename

Feature: Renaming a subfolder
  As a Nextcloud user
  I want a subfolder renamed on either side to be recognised as the same folder
  So that a rename never costs a dashboard its uid, its history, or its URL

  Background:
    Given the app is connected to Grafana
    And the following mappings were made:
      | grafana folder | nc folder | mode | storage      | groups |
      | Demo           | Demo      | sync | admin folder |        |
      | metrics        | Shared    | sync | team folder  | admin  |
    And the following items in the mappings:
      | path                        |
      | /Demo/Overview.grafana      |
      | /Demo/notes.txt             |
      | /Shared/Coast/Tides.grafana |

  # notes: ../AGENTS.md#the-mappings-in-the-background
  # notes: ../AGENTS.md#a-subfolder-shares-its-name-with-grafana-exactly

    # ── RULE: the uid is what makes a rename a rename ─────────────────────────
    # notes: ../AGENTS.md#a-nextcloud-folder-carries-its-grafana-folder-uid

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Rename a subfolder in Nextcloud
    Given the folder "<from>" holding a dashboard
    When I rename "<from>" to "<to>"
    Then Grafana mirrors the folder "<to>"
    And "<to>" holds:
      | grafana_folder_uid | the uid it had before the rename |
    And the dashboard inside "<to>" holds:
      | grafana_uid | the uid it had before the rename |

    Examples: however deep it sits, in either kind of storage
      | from               | to                   |
      | Demo/Team A        | Demo/Team B          |
      | Demo/Team A/Drafts | Demo/Team A/Sketches |
      | Shared/Team A      | Shared/Team B        |

    # notes: ../AGENTS.md#the-uid-is-why-this-is-a-rename

  @grafana @in-grafana @gesture @ui
  Scenario Outline: Rename a subfolder in Grafana
    Given the folder "<from>" holding a dashboard
    When someone renames the "<from>" Grafana folder to "<name>"
    Then Grafana mirrors the folder "<to>"
    And "<from>" is gone from Nextcloud
    And "<to>" holds:
      | grafana_folder_uid | the uid it had before the rename |
    And the dashboard inside "<to>" holds:
      | grafana_uid | the uid it had before the rename |

    Examples: read by name this is a folder vanishing; read by uid it is a rename
      | from               | name     | to                   |
      | Demo/Team A        | Team B   | Demo/Team B          |
      | Demo/Team A/Drafts | Sketches | Demo/Team A/Sketches |
      | Shared/Team A      | Team B   | Shared/Team B        |

    # ── RULE: a rename Grafana will not take leaves the local one standing ────

  # notes: ../AGENTS.md#a-failed-subfolder-rename-leaves-the-local-rename-standing
  @user @in-nextcloud @gesture @ui @blocked
  Scenario: Rename a subfolder while Grafana is unreachable
    Given the folder "Demo/Team A" holding a dashboard
    And Grafana is unreachable
    When I rename "Demo/Team A" to "Demo/Team B"
    Then "Demo/Team B" exists in Nextcloud
    And the failure is reported to the user
    And "Demo/Team B" holds:
      | grafana_folder_uid | the uid it had before the rename |
