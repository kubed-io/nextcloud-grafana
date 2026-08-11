# Notes, decisions and history for this feature: ../AGENTS.md#foldersrename

Feature: Renaming a subfolder
  As a Nextcloud user
  I want a subfolder renamed on either side to be recognised as the same folder
  So that a rename never costs a dashboard its uid, its history, or its URL

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | demo |
      | nc folder      | Demo |
      | mode           | sync |

  # notes: ../AGENTS.md#the-mappings-in-the-background
  # notes: ../AGENTS.md#a-subfolder-shares-its-name-with-grafana-exactly

    # ── RULE: the uid is what makes a rename a rename ─────────────────────────
    # notes: ../AGENTS.md#a-nextcloud-folder-carries-its-grafana-folder-uid

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Rename a subfolder in Nextcloud
    Given the folder "Demo/Team A" holding a dashboard
    When I rename "Demo/Team A" to "Demo/Team B"
    Then the Grafana folder is named "Team B"
    And "Demo/Team B" holds:
      | grafana_folder_uid | the uid it had before the rename |
    And the dashboard inside it holds:
      | grafana_uid | the uid it had before the rename |

    # The uid is why this is a rename and not a delete plus a create: the folder that
    # holds it is the same folder, whatever it is called.

  @grafana @in-grafana @gesture @ui @unbuilt
  Scenario: Rename a subfolder in Grafana
    Given the folder "Demo/Team A" holding a dashboard
    When someone renames the "Team A" Grafana folder to "Team B"
    Then "Demo/Team B" exists in Nextcloud
    And "Demo/Team A" is gone from Nextcloud
    And "Demo/Team B" holds:
      | grafana_folder_uid | the uid it had before the rename |
    And the dashboard inside it holds:
      | grafana_uid | the uid it had before the rename |

    # Read by NAME this is one folder vanishing and another appearing; read by uid it
    # is one folder with a new name.

    # ── RULE: a rename Grafana will not take leaves the local one standing ────

  # notes: ../AGENTS.md#a-failed-subfolder-rename-leaves-the-local-rename-standing
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Rename a subfolder while Grafana is unreachable
    Given the folder "Demo/Team A" holding a dashboard
    And Grafana is unreachable
    When I rename "Demo/Team A" to "Demo/Team B"
    Then "Demo/Team B" exists in Nextcloud
    And the failure is reported to the user
    And "Demo/Team B" holds:
      | grafana_folder_uid | the uid it had before the rename |

    # The local rename stands because Nextcloud already did it, and the uid is what
    # lets the next sync finish the job rather than guess at a delete.
