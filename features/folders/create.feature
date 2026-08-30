# Notes, decisions and history for this feature: ../AGENTS.md#folderscreate

Feature: Creating a folder
  As a Nextcloud user
  I want the folders holding my dashboards to exist in Grafana too
  So that the two trees look the same without my having to manage either

  Background:
    Given the app is connected to Grafana
    And Grafana holds these resources:
      | path             | type   |
      | /nextcloud-trash | folder |
    And Nextcloud holds these resources:
      | path              |
      | /Scratch          |
      | /Shared           |
      | /Shared/notes.txt |
    And the following mappings were made:
      | grafana folder | nc folder | mode | storage      | groups |
      | Demo           | Demo      | sync | admin folder |        |
      | links          | Pointers  | link | admin folder |        |
      | metrics        | Shared    | sync | team folder  | admin  |
    And the following items in the mappings:
      | path               |
      | /Demo/Existing     |
      | /Pointers/Existing |
      | /Shared/Existing   |
    And the Grafana recycle-bin folder is named "nextcloud-trash"

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a folder is in Grafana when a dashboard is in it ────────────────
    # notes: ../AGENTS.md#a-subfolder-is-in-grafana-when-a-dashboard-is-in-it

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Create a dashboard in a folder of a mapping which is not yet in Grafana
    Given the folder "<folder>" holding no dashboards
    And no part of "<folder>" exists in Grafana yet
    When I create a new dashboard in "<folder>"
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
  Scenario Outline: Move a dashboard into a folder of a mapping which is not yet in Grafana
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

  @user @in-nextcloud @gesture @ui
  Scenario: Create a new folder in a mapping
    When I create the folder "Demo/Notes"
    Then Grafana holds no folder named "Notes"
    And "Demo/Notes" holds:
      | grafana_folder_uid | absent |

    # The other half of the same rule. An empty folder is just a folder, and a
    # folder with no uid is one the app has never had anything to do with.

    # ── RULE: a folder made in Grafana arrives as a folder ────────────────────

  @grafana @in-grafana @gesture @ui
  Scenario Outline: Create a folder in Grafana under a mapped folder
    When someone creates the Grafana folder "<grafana folder>"
    Then Grafana mirrors the folder "<folder>"

    Examples: at the mapping's root, all the way down, and under a folder it already had
      | grafana folder      | folder                 |
      | Demo/Bubbles        | Demo/Bubbles           |
      | Demo/Deep/Down/Low  | Demo/Deep/Down/Low     |
      | Demo/Existing/Nubs  | Demo/Existing/Nubs     |
      | links/Bubbles       | Pointers/Bubbles       |
      | links/Existing/Nubs | Pointers/Existing/Nubs |
      | metrics/Deep/Down   | Shared/Deep/Down       |

    # notes: ../AGENTS.md#grafana-owns-the-tree
