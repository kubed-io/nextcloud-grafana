# Notes, decisions and history for this feature: ../AGENTS.md#folderstags

Feature: Tagging a folder
  As a Grafana admin browsing folders in Nextcloud
  I want a folder's tags to be one set however I reach them
  So that I can re-tag a folder from whichever side I happen to be on

  Background:
    Given the app is connected to Grafana
    And Grafana holds these resources:
      | path        | type   | tags           |
      | /Demo       | folder | strategic      |
      | /Demo/Team  | folder | quarterly, ops |
      | /links      | folder | reference      |
      | /links/Team | folder | reference      |
    And the following mappings were made:
      | grafana folder | nc folder | mode | storage      |
      | Demo           | Demo      | sync | admin folder |
      | links          | Pointers  | link | admin folder |
    And Grafana and Nextcloud are in sync

  # notes: ../AGENTS.md#the-tags-were-there-before-the-mapping-was
  # The tags predate the connection, as an admin's own API call would leave them.

    # ── RULE: a folder's tags are one set, changed from either side ───────────
    # notes: ../AGENTS.md#a-folders-tags-are-one-set-on-both-sides

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Tag a folder in Nextcloud
    Given the folder "<folder>" whose tags are "<tags before>"
    When I change the Nextcloud tags on "<folder>" to "<tags after>"
    Then the folder "<folder>" is tagged "<tags after>" in Nextcloud
    And the folder "<folder>" is tagged "<tags after>" in Grafana

    Examples: the mapped folder and a subfolder under it are the same gesture
      | folder    | tags before    | tags after     |
      | Demo      | strategic      | strategic, ops |
      | Demo      |                | quarterly      |
      | Demo/Team | quarterly, ops | quarterly      |
      | Demo/Team | quarterly      | archived       |
      | Demo/Team | quarterly      |                |

  # this is only possible through API with annotations
  @grafana @in-grafana @gesture @ui
  Scenario Outline: Tag a folder in Grafana
    Given the folder "<folder>" whose tags are "<tags before>"
    When the tags on "<folder>" are changed to "<tags after>" in Grafana
    Then the folder "<folder>" is tagged "<tags after>" in Nextcloud
    And the folder "<folder>" is tagged "<tags after>" in Grafana

    Examples: Grafana is the system of record, so its set wins outright
      | folder    | tags before    | tags after     |
      | Demo      | strategic      | strategic, ops |
      | Demo/Team | quarterly, ops | quarterly      |
      | Demo/Team | quarterly      | archived       |
      | Demo/Team | quarterly      |                |
      | Demo/Team |                | quarterly      |

    # ── RULE: a change only travels where the mode lets it ────────────────────

  # notes: ../AGENTS.md#tagging-a-folder-in-a-link-mapping-does-not-reach-grafana
  @user @in-nextcloud @gesture @ui
  Scenario: Tagging a folder in a link mapping does not reach Grafana
    Given the folder "Pointers/Team" whose tags are "reference"
    When I change the Nextcloud tags on "Pointers/Team" to "reference, mine"
    Then the folder "Pointers/Team" is untouched in Grafana

    # Nothing refuses the gesture: to Nextcloud a folder is a folder, and this one
    # may hold anything the user likes. It simply does not travel.
