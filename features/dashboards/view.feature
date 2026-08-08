# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsview

Feature: Looking at a dashboard file
  As someone with dashboards mirrored into Nextcloud
  I want to see them for what they are, and see what the app knows about them
  So that a mapped folder reads as dashboards rather than as anonymous JSON files

  Background:
    Given the app is connected to Grafana

  # notes: ../AGENTS.md#view-dashboard

  @user @ui @todo
  Scenario: A mapped folder shows its dashboards as dashboards
    Given a folder mapped as "sync" to the Grafana folder "flows"
    And Grafana has dashboards in the "flows" folder
    And the "flows" mapping has been synced
    When the user views the contents of the mapped folder
    Then the mapped folder shows the dashboards with the Grafana icon
    # notes: ../AGENTS.md#a-mapped-folder-shows-its-dashboards-as-dashboards

  @user @gesture @todo
  Scenario Outline: Viewing the DAV properties on a file shows Grafana specific details
    Given a mapping with the following values:
      | grafana folder | <grafana folder> |
      | nc folder      | <nc folder>      |
      | mode           | <mode>           |
    And a dashboard "<dashboard>" mirrored into that folder
    When a WebDAV client requests the file's properties
    Then the response carries the properties the app manages:
      | property                       | value               |
      | nc:metadata-grafana_uid        | the dashboard's uid |
      | nc:metadata-grafana_mapping    | the mapping's id    |
      | nc:metadata-grafana_mode       | <stored mode>       |
      | nc:metadata-grafana_version    | set                 |
      | nc:metadata-grafana_syncedHash | set                 |

    Examples: both modes a mapping can hold
      | mode | stored mode | grafana folder | nc folder | dashboard |
      | sync | sync        | bananacat      | observe   | fuzzler   |
      | link | reference   | applepie       | pointers  | wobbler   |

    # notes: ../AGENTS.md#viewing-the-dav-properties-on-a-file-shows-grafana-specific-details

  @user @gesture @todo
  Scenario: What the app manages, only the app changes
    Given a managed dashboard file
    When a client tries to change "nc:metadata-grafana_uid" via PROPPATCH
    Then the change is rejected — the sync engine owns these properties
    And the property still names the dashboard it named before
    # notes: ../AGENTS.md#what-the-app-manages-only-the-app-changes

  # notes: ../AGENTS.md#finding-dashboards-by-their-mode
  @user @gesture @blocked
  Scenario: Finding dashboards by their mode
    Given a "sync" dashboard file and a "link" dashboard file in the same user's storage
    When a DAV REPORT searches for files where "nc:metadata-grafana_mode" is "sync"
    Then only the sync file is returned
