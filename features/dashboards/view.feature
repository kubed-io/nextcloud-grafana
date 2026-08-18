# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsview

Feature: Looking at a dashboard file
  As someone with dashboards mirrored into Nextcloud
  I want to see them for what they are, and see what the app knows about them
  So that a mapped folder reads as dashboards rather than as anonymous JSON files

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Demo         |
      | nc folder      | Demo         |
      | mode           | sync         |
      | storage        | admin folder |
    And a mapping with the following values:
      | grafana folder | links        |
      | nc folder      | Pointers     |
      | mode           | link         |
      | storage        | admin folder |

  # notes: ../AGENTS.md#the-mappings-in-the-background
  # notes: ../AGENTS.md#view-dashboard

    # ── RULE: a mirror reads as a dashboard, not as the JSON it happens to be ─

  # notes: ../AGENTS.md#a-mapped-folder-shows-its-dashboards-as-dashboards
  @user @ui
  Scenario: A mapped folder shows its dashboards as dashboards
    Given a dashboard file named "Fleet Health.grafana" in "Demo"
    And a dashboard file named "Cluster Load.grafana" in "Demo"
    When I open "Demo" in the Files app
    Then the mapped folder shows the dashboards with the Grafana icon

    # ── RULE: a client can read what the app knows about the file ────────────

  # notes: ../AGENTS.md#viewing-the-dav-properties-on-a-file-shows-grafana-specific-details
  @user @dav
  Scenario Outline: Viewing the DAV properties on a file shows Grafana specific details
    Given a dashboard file named "Fleet Health.grafana" in "<folder>"
    When a WebDAV client requests the file's properties
    Then the file holds:
      | grafana_uid        | the dashboard's uid |
      | grafana_mapping    | the mapping's id    |
      | grafana_mode       | the mapping's mode  |
      | grafana_version    | <version>           |
      | grafana_syncedHash | set                 |

    Examples: both modes a mapping can hold, and only one of them has a version
      | folder   | version |
      | Demo     | set     |
      | Pointers | absent  |

    # A version records what a push last sent, and a link never pushes — so it is
    # deliberately left empty rather than being a value nobody maintains.

  # notes: ../AGENTS.md#finding-dashboards-by-their-mode
  @user @dav @blocked
  Scenario: Finding dashboards by their mode
    Given a dashboard file named "Fleet Health.grafana" in "Demo"
    And a dashboard file named "Fleet Health.grafana" in "Pointers"
    When a DAV REPORT searches for files where "nc:metadata-grafana_mode" is "sync"
    Then only the file in "Demo" is returned
