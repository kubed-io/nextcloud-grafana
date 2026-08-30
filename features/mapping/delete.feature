# Notes, decisions and history for this feature: ../AGENTS.md#mappingdelete

Feature: Removing a mapping tears down the connection without ever touching Grafana
  As a Nextcloud admin
  I want removing a mapping to keep whatever each file's mode made worth keeping
  So that disconnecting the two sides can never cost me a dashboard or a file

  Background:
    Given the app is connected to Grafana
    And the following mappings were made:
      | grafana folder | nc folder | mode |
      | Demo           | Demo      | sync |
      | links          | Pointers  | link |

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: teardown keeps whatever the mode made worth keeping ─────────────
    # notes: ../AGENTS.md#removing-a-mapping-keeps-what-the-mode-made-worth-keeping

  @admin @in-nextcloud @occ @ui
  Scenario: Removing a sync mapping leaves its dashboards behind, unmapped
    Given the following items in the mappings:
      | path                       |
      | /Demo/Fleet Health.grafana |
      | /Demo/Coast/Tides.grafana  |
    When the admin removes the "Demo" mapping
    Then the "Demo" mapping is no longer configured
    And "Demo" holds the same files it held before
    And "Demo/Coast/Tides.grafana" holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | absent              |
      | grafana_mode    | "unmapped"          |
    And the dashboards are still in the "Demo" Grafana folder
    And the "Demo" folder and the "Demo" Grafana folder both outlive the mapping

    # A sync file holds the dashboard itself and may be the last copy of it.
    # Disconnecting is administrative; destroying an archive on the way past is not.

  @admin @in-nextcloud @occ @ui
  Scenario: Removing a link mapping takes its dashboards with it
    Given the following items in the mappings:
      | path                            |
      | /Pointers/Pinned.grafana        |
      | /Pointers/Coast/Latency.grafana |
    When the admin removes the "links" mapping
    Then the "links" mapping is no longer configured
    And "Pointers" holds no dashboard files
    And the dashboards are still in the "links" Grafana folder
    And the "Pointers" folder and the "links" Grafana folder both outlive the mapping

    # A link is a pointer whose only meaning was the mapping, so once the mapping
    # is gone there is nothing left for it to be.
