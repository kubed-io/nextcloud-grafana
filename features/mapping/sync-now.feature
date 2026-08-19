# Notes, decisions and history for this feature: ../AGENTS.md#mappingsync-now

Feature: Syncing one mapping from its card
  As an admin who has just mapped a folder
  I want to sync that one folder without touching the others
  So that a new mapping fills immediately and a busy instance is not re-walked

  Background:
    Given the app is connected to Grafana
    And Grafana holds these resources:
      | path                   | type      | tags       |
      | /alpha/Overview        | dashboard | dns, linux |
      | /alpha/Region          | folder    |            |
      | /alpha/Region/Latency  | dashboard | latency    |
      | /links/Overview        | dashboard | dns, linux |
      | /links/Region          | folder    |            |
      | /links/Region/Latency  | dashboard | latency    |
      | /shared/Overview       | dashboard | dns, linux |
      | /shared/Region         | folder    |            |
      | /shared/Region/Latency | dashboard | latency    |
    And the following mappings were made:
      | grafana folder | nc folder | mode | storage      | groups |
      | alpha          | Alpha     | sync | admin folder |        |
      | links          | Pointers  | link | admin folder |        |
      | shared         | Shared    | sync | team folder  | admin  |

  # notes: ../AGENTS.md#three-mappings-shaped-alike
  # None has been synced, so all three mapped folders start empty.

  # notes: ../AGENTS.md#syncing-one-mapping-fills-its-folder
  @admin @occ @ui
  Scenario Outline: A sync from Grafana mounts the mapping it was asked for
    When the admin syncs the "<nc folder>" mapping from Grafana
    Then Nextcloud holds exactly these resources:
      | path                                | tags       |
      | /<nc folder>/Overview.grafana       | dns, linux |
      | /<nc folder>/Region                 |            |
      | /<nc folder>/Region/Latency.grafana | latency    |
    And "<nc folder>/Overview.grafana" holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | set                 |
      | grafana_mode    | "<mode>"            |
    And the file "<nc folder>/Overview.grafana" carries its Grafana dates

    Examples: one mapping at a time, and every kind of mapping there is
      | nc folder | mode | storage      |
      | Alpha     | sync | admin folder |
      | Pointers  | link | admin folder |
      | Shared    | sync | team folder  |

    # notes: ../AGENTS.md#carries-its-grafana-dates
    # The storage column is the Background's; it rides here so the coverage shows.

  # ── the whole-instance mirror, which is still one mapping ──────────────────

  # notes: ../AGENTS.md#a-root-mapping-mirrors-the-whole-instance
  @admin @occ @ui @unbuilt
  Scenario: A root mapping mirrors the whole instance
    Given a folder mapped as "sync" to the Grafana root "/"
    And Grafana has dashboards at the root and inside nested folders
    When the admin syncs the "root" mapping from Grafana
    Then every Grafana folder that holds dashboards appears as a nested Nextcloud subfolder
    And every dashboard appears as a ".grafana" file in the matching subfolder
    And the Nextcloud tree is a one-to-one mirror of the Grafana folder structure
