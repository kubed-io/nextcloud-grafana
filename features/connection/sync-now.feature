# Notes, decisions and history for this feature: ../AGENTS.md#connectionsync-now

Feature: Syncing every mapping
  As a Nextcloud admin
  I want one sync, in either direction, across every mapping at once
  So that the mirror stays true without anyone tending it — and so I can declare
  Nextcloud the source of truth on the day something has gone wrong in Grafana

  Background:
    Given the app is connected to Grafana
    And Grafana holds these resources:
      | path                       | type      | tags       |
      | /Alpha/Alpha Demo          | dashboard | dns, linux |
      | /Alpha/Region              | folder    |            |
      | /Alpha/Region/Latency      | dashboard | latency    |
      | /Alpha/Region/Deep         | folder    |            |
      | /Alpha/Region/Deep/Traffic | dashboard |            |
      | /links/Pinned              | dashboard | reference  |
      | /links/Nested              | folder    |            |
      | /links/Nested/Deeper       | dashboard |            |
      | /metrics/Metrics Demo      | dashboard | ops        |
      | /metrics/Coast             | folder    |            |
      | /metrics/Coast/Tides       | dashboard |            |
    And Nextcloud holds these resources:
      | path                         | tags    |
      | /Alpha/notes.txt             | reading |
      | /Alpha/Local Only.grafana    | draft   |
      | /Alpha/Drafts                |         |
      | /Alpha/Drafts/plan.txt       |         |
      | /Alpha/Drafts/Sketch.grafana |         |
    And the following mappings were made:
      | grafana folder | nc folder | mode | storage      | groups |
      | Alpha          | Alpha     | sync | admin folder |        |
      | links          | Pointers  | link | admin folder |        |
      | metrics        | Shared    | sync | team folder  | admin  |

  # notes: ../AGENTS.md#a-background-is-a-picture-not-a-story
  # The two sides do not agree yet, and the Background never says why they don't.

  # ── one behaviour, two ways to start it across every mapping ───────────────
  # notes: ../AGENTS.md#sync-now-scope

  @admin @occ @ui
  Scenario Outline: A sync from Grafana mounts every mapped folder, however it was started
    When <actor> syncs every mapping from Grafana
    Then Nextcloud holds exactly these resources:
      | path                               | tags       |
      | /Alpha/notes.txt                   | reading    |
      | /Alpha/Local Only.grafana          | draft      |
      | /Alpha/Alpha Demo.grafana          | dns, linux |
      | /Alpha/Drafts                      |            |
      | /Alpha/Drafts/plan.txt             |            |
      | /Alpha/Drafts/Sketch.grafana       |            |
      | /Alpha/Region                      |            |
      | /Alpha/Region/Latency.grafana      | latency    |
      | /Alpha/Region/Deep                 |            |
      | /Alpha/Region/Deep/Traffic.grafana |            |
      | /Pointers/Pinned.grafana           | reference  |
      | /Pointers/Nested                   |            |
      | /Pointers/Nested/Deeper.grafana    |            |
      | /Shared/Metrics Demo.grafana       | ops        |
      | /Shared/Coast                      |            |
      | /Shared/Coast/Tides.grafana        |            |

    Examples: both ways an instance-wide sync starts
      | actor        |
      | the admin    |
      | the schedule |

    # notes: ../AGENTS.md#the-tree-is-the-assertion
    # notes: ../AGENTS.md#a-mirror-follows-its-dashboard-between-folders

    # ── RULE: the other direction — Nextcloud is declared the source of truth ──

  @admin @occ @ui
  Scenario: A sync to Grafana makes Grafana match Nextcloud, however deep the file sits
    Given Grafana and Nextcloud are in sync
    And these dashboards were changed in Grafana after their files were written:
      | path                  |
      | /Alpha/Alpha Demo     |
      | /Alpha/Region/Latency |
      | /metrics/Coast/Tides  |
    When the admin syncs every mapping to Grafana
    Then each of those dashboards in Grafana holds its file's panels

    # notes: ../AGENTS.md#a-sync-to-grafana-makes-grafana-match-nextcloud-however-deep-the-file-sits
