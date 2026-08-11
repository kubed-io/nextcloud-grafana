# Notes, decisions and history for this feature: ../AGENTS.md#folderscreate

Feature: Creating a folder
  As a Nextcloud user
  I want the folders holding my dashboards to exist in Grafana too
  So that the two trees look the same without my having to manage either

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | demo |
      | nc folder      | Demo |
      | mode           | sync |
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a folder is in Grafana when a dashboard is in it ────────────────
    # notes: ../AGENTS.md#a-subfolder-is-in-grafana-when-a-dashboard-is-in-it

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Create a folder inside a mapping
    When I create the folder "Demo/Notes"
    Then "Demo/Notes" holds:
      | grafana_folder_uid | absent |
    And Grafana holds no folder named "Notes"

    # An empty folder is just a folder. Nothing has asked for it in Grafana yet.

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Create a dashboard inside a folder of a mapping
    Given the folder "Demo/Team/Drafts" holding no dashboards
    When I create "CPU Load.grafana.json" in "Demo/Team/Drafts"
    Then Grafana holds "Team" under "demo", and "Drafts" under "Team"
    And the dashboard is in the "Drafts" Grafana folder
    And "Demo/Team" holds:
      | grafana_folder_uid | the uid of the "Team" Grafana folder |
    And "Demo/Team/Drafts" holds:
      | grafana_folder_uid | the uid of the "Drafts" Grafana folder |

    # The parents come with it: a dashboard three folders deep needs all three.

    # ── RULE: a folder made in Grafana arrives as a folder ────────────────────

  @grafana @in-grafana @gesture @ui @unbuilt
  Scenario: Create a folder in Grafana under a mapped folder
    When someone creates the folder "Bubbles" under the "demo" Grafana folder
    Then "Demo/Bubbles" exists in Nextcloud
    And "Demo/Bubbles" holds:
      | grafana_folder_uid | the uid of the "Bubbles" Grafana folder |

  # notes: ../AGENTS.md#a-folder-the-user-made-for-something-else-stays-theirs
  @grafana @in-grafana @gesture @ui @unbuilt
  Scenario: A folder the user made for something else stays theirs
    Given the folder "Demo/Holiday Photos" holding no dashboards
    When someone creates the folder "Bubbles" under the "demo" Grafana folder
    Then "Demo/Holiday Photos" holds:
      | grafana_folder_uid | absent |

    # The pull claims the folders it mirrors and no others. A folder with no uid is
    # a folder the app has never had anything to do with.

    # ── RULE: the recycle bin's folder is the app's, not a user's ─────────────
    # notes: ../AGENTS.md#a-folder-cannot-be-opted-in-under-the-recycle-bin-folders-name

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Create a folder named after the recycle-bin folder
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And the folder "Demo/nextcloud-trash" holding no dashboards
    When I create "CPU Load.grafana.json" in "Demo/nextcloud-trash"
    Then the creation is refused with a message, explaining the name is reserved
    And the recycle-bin folder still holds what it held
