# Notes, decisions and history for this feature: ../AGENTS.md#dashboardstags

Feature: Changing a dashboard's tags
  As a Grafana admin browsing dashboards in Nextcloud
  I want a change I make to a dashboard's tags to reach every other surface
  So that the mirror is as searchable as Grafana and I can re-tag from either side

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Flows |
      | nc folder      | Flows |
      | mode           | sync  |
    And a mapping with the following values:
      | grafana folder | Reports |
      | nc folder      | Reports |
      | mode           | link    |
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: applying a set of tags is ONE gesture, on any surface ─────────────
    # notes: ../AGENTS.md#applying-a-set-of-tags-is-one-gesture

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario Outline: Changing a dashboard's tags in Nextcloud changes them in Grafana
    Given a dashboard file in "Flows" whose tags are "<tags before>"
    When I change the Nextcloud tags to "<tags after>"
    Then the dashboard's tags are "<tags after>" in Nextcloud
    And the dashboard's tags are "<tags after>" in the file
    And the dashboard's tags are "<tags after>" in Grafana

    Examples: adding, subtracting, and doing both at once are one gesture
      | tags before | tags after        |
      | dns, linux  | dns, linux, prod  |
      | dns, linux  | dns               |
      | dns, linux  | linux, prod       |
      | dns         | prod, staging     |
      | dns, linux  |                   |
      |             | dns               |
      | 2024, linux | 2024, linux, prod |

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario Outline: Changing a dashboard's tags in the file changes them in Grafana
    Given a dashboard file in "Flows" whose tags are "<tags before>"
    When I change the tags in the file to "<tags after>"
    Then the dashboard's tags are "<tags after>" in Nextcloud
    And the dashboard's tags are "<tags after>" in the file
    And the dashboard's tags are "<tags after>" in Grafana

    Examples: the same gesture, typed into the JSON instead of clicked on the file
      | tags before | tags after       |
      | dns, linux  | dns, linux, prod |
      | dns, linux  | prod             |
      | dns         | linux, prod      |
      |             | dns, linux       |
      | dns, linux  |                  |

  @grafana @in-grafana @occ @ui @unbuilt
  Scenario Outline: Changing a dashboard's tags in Grafana changes them in Nextcloud
    Given a dashboard file in "Flows" whose tags are "<tags before>"
    When the dashboard's tags are changed to "<tags after>" in Grafana
    Then the dashboard's tags are "<tags after>" in Nextcloud
    And the dashboard's tags are "<tags after>" in the file
    And the dashboard's tags are "<tags after>" in Grafana
    And nothing else in the file changed

    Examples: Grafana is the system of record, so its set wins outright
      | tags before | tags after       |
      | dns, linux  | dns, linux, prod |
      | dns, linux  | dns              |
      | dns         | linux, prod      |
      | dns, linux  |                  |
      |             | dns, linux       |
      | dns, linux  | prod, staging    |

    # ── RULE: a change only travels where the mode lets it ─────────────────────

  # notes: ../AGENTS.md#changing-the-tags-on-a-link-does-not-change-them-in-grafana
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Changing the tags on a link does not change them in Grafana
    Given a dashboard file in "Reports" whose tags are "prod, dns"
    When I change the Nextcloud tags to "prod, dns, mine"
    Then the dashboard's tags are still "prod, dns" in Grafana
    And the file's tags settle back to "prod, dns"
    And the file can be found by a Nextcloud tag search for "prod"

  # notes: ../AGENTS.md#changing-the-tags-on-a-file-the-mapping-does-not-own
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Changing the tags on an unmapped file keeps them local
    Given a dashboard file in "Scratch" whose tags are "dns"
    When I change the Nextcloud tags to "mine"
    Then the dashboard's tags are "mine" in Nextcloud
    And the dashboard's tags are "mine" in the file
