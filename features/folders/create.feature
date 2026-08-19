# Notes, decisions and history for this feature: ../AGENTS.md#folderscreate

Feature: Creating a folder
  As a Nextcloud user
  I want the folders holding my dashboards to exist in Grafana too
  So that the two trees look the same without my having to manage either

  Background:
    Given the app is connected to Grafana
    And Grafana holds these resources:
      | path             | type   |
      | /Demo/Existing   | folder |
      | /links/Existing  | folder |
      | /shared/Existing | folder |
    And the following mappings were made:
      | grafana folder | nc folder | mode | storage      | groups |
      | Demo           | Demo      | sync | admin folder |        |
      | links          | Pointers  | link | admin folder |        |
      | shared         | Shared    | sync | team folder  | admin  |
    And a folder "Scratch" that is not mapped
    And the Grafana recycle-bin folder is named "nextcloud-trash"
    And Grafana and Nextcloud are in sync

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a folder is in Grafana when a dashboard is in it ────────────────
    # notes: ../AGENTS.md#a-subfolder-is-in-grafana-when-a-dashboard-is-in-it

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Create a dashboard in a folder of a mapping
    Given the folder "<folder>" holding no dashboards
    And no part of "<folder>" exists in Grafana yet
    When I create "CPU Load.grafana" in "<folder>"
    Then Grafana mirrors the folder "<folder>"
    And the dashboard is in the folder mirroring "<folder>"

    Examples: however deep it lands, in either kind of storage
      | folder                         |
      | Demo/Team                      |
      | Demo/Team/Drafts               |
      | Shared/Team                    |
      | Shared/Team/Drafts/Deep/Deeper |

    # notes: ../AGENTS.md#the-parents-come-with-it

  # notes: ../AGENTS.md#a-subfolder-is-in-grafana-when-a-dashboard-is-in-it
  @user @in-nextcloud @gesture @ui
  Scenario Outline: Move a dashboard into a folder of a mapping
    Given a dashboard file named "Fleet Health.grafana" in "<source>"
    And the folder "<folder>" holding no dashboards
    And no part of "<folder>" exists in Grafana yet
    When I move the file into "<folder>"
    Then Grafana mirrors the folder "<folder>"
    And the dashboard is in the folder mirroring "<folder>"

    Examples: wherever it came from, it ends up in the same place
      | source  | folder                         |
      | Scratch | Demo/Team                      |
      | Demo    | Demo/Team/Drafts               |
      | Shared  | Demo/Team                      |
      | Demo    | Shared/Team/Drafts/Deep/Deeper |

    # notes: ../AGENTS.md#wherever-it-came-from

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Create a new folder in a mapping
    When I create the folder "Demo/Notes"
    Then Grafana holds no folder named "Notes"
    And "Demo/Notes" holds:
      | grafana_folder_uid | absent |

    # The other half of the same rule. An empty folder is just a folder, and a
    # folder with no uid is one the app has never had anything to do with.

    # ── RULE: a folder made in Grafana arrives as a folder ────────────────────
    # notes: ../AGENTS.md#a-folder-the-user-made-for-something-else-stays-theirs

  @in-grafana @gesture @ui
  Scenario Outline: Create a folder in Grafana under a mapped folder
    Given the folder "<folder>/Holiday Photos" holding no dashboards
    When someone creates the folder "Bubbles" under the "<grafana folder>" Grafana folder
    Then "<folder>/Bubbles" exists in Nextcloud
    And "<folder>/Bubbles" holds:
      | grafana_folder_uid | the uid of the "Bubbles" Grafana folder |
    And "<folder>/Holiday Photos" holds:
      | grafana_folder_uid | absent |

    Examples: Grafana owns the tree, in every kind of mapping and at either depth
      | folder            | grafana folder  |
      | Demo              | Demo            |
      | Demo/Existing     | Demo/Existing   |
      | Pointers          | links           |
      | Pointers/Existing | links/Existing  |
      | Shared            | shared          |
      | Shared/Existing   | shared/Existing |

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
