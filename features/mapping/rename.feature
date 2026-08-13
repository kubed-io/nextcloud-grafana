# Notes, decisions and history for this feature: ../AGENTS.md#mappingrename

Feature: Renaming a mapped folder
  As a Nextcloud admin
  I want renaming either side of a mapping to change nothing about the mapping
  So that reorganising folders never quietly disconnects dashboards from Grafana

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Demo |
      | nc folder      | Demo |
      | mode           | sync |

  # notes: ../AGENTS.md#the-mappings-in-the-background
  # notes: ../AGENTS.md#a-mapping-is-a-pair-of-ids-so-a-rename-is-a-no-op

    # ── RULE: a mapping is held by id on both sides, so a rename reaches it ───

  @admin @in-nextcloud @gesture @ui @todo
  Scenario: Rename the mapped Nextcloud folder
    Given a dashboard file in "Demo"
    When I rename "Demo" to "Dashboards"
    Then the mapping's Nextcloud folder is "Dashboards"
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
      | grafana_mode    | the mapping's mode  |
    And the dashboard is in the "Demo" Grafana folder

    # Nothing is sent to Grafana: the mapping names a folder UID there, and the
    # Nextcloud folder it pairs with is the one it has always pointed at.

  # notes: ../AGENTS.md#renaming-the-mapped-folder-in-grafana-does-not-break-the-mapping
  @grafana @in-grafana @gesture @ui @todo
  Scenario: Rename the mapped Grafana folder
    Given a dashboard file in "Demo"
    When someone renames the "Demo" Grafana folder to "metrics"
    Then the mapping's Grafana folder is still the one it was created with
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
    And the file is in "Demo"

    # The Nextcloud folder does NOT follow: a mapping is the one place a pair of
    # differing names is legitimate.


