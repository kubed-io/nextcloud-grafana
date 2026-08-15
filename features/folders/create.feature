# Notes, decisions and history for this feature: ../AGENTS.md#folderscreate

Feature: Creating a folder
  As a Nextcloud user
  I want the folders holding my dashboards to exist in Grafana too
  So that the two trees look the same without my having to manage either

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Demo         |
      | nc folder      | Demo         |
      | mode           | sync         |
      | storage        | admin folder |
    And a mapping with the following values:
      | grafana folder | links    |
      | nc folder      | Pointers |
      | mode           | link     |
    And the Grafana recycle-bin folder is named "nextcloud-trash"

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a folder is in Grafana when a dashboard is in it ────────────────
    # notes: ../AGENTS.md#a-subfolder-is-in-grafana-when-a-dashboard-is-in-it

  @user @in-nextcloud @gesture @ui
  Scenario: Create a dashboard in a folder of a mapping
    Given the folder "Demo/Team/Drafts" holding no dashboards
    When I create "CPU Load.grafana" in "Demo/Team/Drafts"
    Then Grafana holds "Team" under "Demo", and "Drafts" under "Team"
    And the dashboard is in the "Drafts" Grafana folder
    And "Demo/Team" holds:
      | grafana_folder_uid | the uid of the "Team" Grafana folder |
    And "Demo/Team/Drafts" holds:
      | grafana_folder_uid | the uid of the "Drafts" Grafana folder |

    # The parents come with it: a dashboard three folders deep needs all three.

  # notes: ../AGENTS.md#a-subfolder-is-in-grafana-when-a-dashboard-is-in-it
  @user @in-nextcloud @gesture @ui
  Scenario: Move a dashboard into a folder of a mapping
    Given a dashboard file in "Demo"
    And the folder "Demo/Team/Drafts" holding no dashboards
    When I move the file into "Demo/Team/Drafts"
    Then Grafana holds "Team" under "Demo", and "Drafts" under "Team"
    And the dashboard is in the "Drafts" Grafana folder
    And "Demo/Team/Drafts" holds:
      | grafana_folder_uid | the uid of the "Drafts" Grafana folder |

    # The second of exactly two ways a Grafana folder is born from Nextcloud, which
    # is why it lives here and not with the other move gestures.

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Create a folder holding no dashboards
    When I create the folder "Demo/Notes"
    Then Grafana holds no folder named "Notes"
    And "Demo/Notes" holds:
      | grafana_folder_uid | absent |

    # The other half of the same rule. An empty folder is just a folder, and a
    # folder with no uid is one the app has never had anything to do with.

    # ── RULE: a folder made in Grafana arrives as a folder ────────────────────
    # notes: ../AGENTS.md#a-folder-the-user-made-for-something-else-stays-theirs

  @grafana @in-grafana @gesture @ui
  Scenario Outline: Create a folder in Grafana under a mapped folder
    Given the folder "<folder>/Holiday Photos" holding no dashboards
    When someone creates the folder "Bubbles" under the "<grafana folder>" Grafana folder
    Then "<folder>/Bubbles" exists in Nextcloud
    And "<folder>/Bubbles" holds:
      | grafana_folder_uid | the uid of the "Bubbles" Grafana folder |
    And "<folder>/Holiday Photos" holds:
      | grafana_folder_uid | absent |

    Examples: Grafana owns the tree in both modes — a link mirrors it too
      | folder   | grafana folder |
      | Demo     | Demo           |
      | Pointers | links          |

    # The pull claims the folders it mirrors and no others, which is the same
    # sentence as the rule above read from Grafana's side.

    # ── RULE: the recycle bin's folder is the app's, not a user's ─────────────
    # notes: ../AGENTS.md#the-recycle-bin-folders-name-is-reserved

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Create a dashboard in a folder named after the recycle-bin folder
    Given the Grafana recycle bin is on
    And the folder "Demo/nextcloud-trash" holding no dashboards
    When I create "CPU Load.grafana" in "Demo/nextcloud-trash"
    Then the creation is refused with a message, explaining the name is reserved
    And the recycle-bin folder still holds what it held
