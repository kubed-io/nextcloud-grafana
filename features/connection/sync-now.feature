# Notes, decisions and history for this feature: ../AGENTS.md#connectionsync-now

Feature: Syncing every mapping
  As a Nextcloud admin
  I want one sync to bring every mapped folder up to date
  So that the mirror stays true without anyone tending it

  Background:
    Given the app is connected to Grafana

  # ── one behaviour, two ways to start it across every mapping ───────────────
  # notes: ../AGENTS.md#sync-now-scope

  @admin @occ @ui
  Scenario Outline: A sync fills the mapped folder, however it was started
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "<folder>"
    And the Grafana folder "nc-alpha" already contains:
      | dashboard  | uid           |
      | Alpha Demo | nc-alpha-demo |
    When <actor> syncs <scope>
    Then the mapped folder "<folder>" holds:
      | file               |
      | Alpha Demo.grafana |
    And "<folder>/Alpha Demo.grafana" holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mode    | "sync"              |
      | grafana_version | set                 |
    And the file "<folder>/Alpha Demo.grafana" carries its Grafana dates

    Examples: both ways an instance-wide sync starts
      | actor        | scope         | folder       |
      | the admin    | every mapping | all-mappings |
      | the schedule | every mapping | on-schedule  |

    # notes: ../AGENTS.md#carries-its-grafana-dates

  # notes: ../AGENTS.md#a-sync-leaves-the-mirror-wearing-grafanas-tags
  @admin @occ @ui
  Scenario: A sync leaves the mirror wearing Grafana's tags
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "tagged"
    And the Grafana folder "nc-alpha" is tagged "quarterly"
    And the Grafana folder "nc-alpha" already contains:
      | dashboard  | uid           | tags       |
      | Alpha Demo | nc-alpha-demo | dns, linux |
    When the admin syncs every mapping
    Then the folder "tagged" is tagged "quarterly" in Nextcloud
    And the tags on "tagged/Alpha Demo.grafana" are "dns, linux" in Nextcloud
    And the tags in the file "tagged/Alpha Demo.grafana" are "dns, linux"
    And the file "tagged/Alpha Demo.grafana" can be found by a Nextcloud tag search for "linux"

  # ── what a first sync does with a folder that already holds mirrors ────────

  @admin @occ @ui
  Scenario: A folder that already holds a mirror is filled in place, not doubled
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "alpha-again"
    And the admin pulls from Grafana
    When the admin pulls from Grafana
    Then "alpha-again" holds exactly 1 dashboard file
    # notes: ../AGENTS.md#a-folder-that-already-holds-a-mirror-is-filled-in-place-not-doubled

  # ── the whole-instance mirror ──────────────────────────────────────────────
