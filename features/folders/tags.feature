# Notes, decisions and history for this feature: ../AGENTS.md#folderstags

Feature: Tagging a folder
  As a Grafana admin browsing folders in Nextcloud
  I want a folder tagged in Grafana to be tagged in Nextcloud
  So that automation can file my folders for me without my own filing leaking back

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Demo |
      | nc folder      | Demo |
      | mode           | sync |
    And a mapping with the following values:
      | grafana folder | links    |
      | nc folder      | Pointers |
      | mode           | link     |

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a folder tagged in Grafana is tagged in Nextcloud ───────────────
    # notes: ../AGENTS.md#a-folder-tagged-in-grafana-is-tagged-in-nextcloud

  @grafana @in-grafana @unbuilt
  Scenario Outline: Tag a folder in Grafana
    Given the folder "Demo/Team" whose tags are "<tags before>"
    When the folder's tags are changed to "<tags after>" in Grafana
    Then the folder's tags are "<tags after>" in Nextcloud

    Examples: Grafana is the system of record, so its set wins outright
      | tags before    | tags after     |
      | quarterly      | quarterly, ops |
      | quarterly, ops | quarterly      |
      | quarterly      | archived       |
      | quarterly      |                |
      |                | quarterly      |

    # ── RULE: my own filing is mine, and stays here ───────────────────────────
    # notes: ../AGENTS.md#a-tag-i-put-on-a-folder-stays-in-nextcloud

  @user @in-nextcloud @gesture @ui @decision
  Scenario Outline: Tag a folder in Nextcloud
    Given the folder "<folder>" holding three dashboards
    When I tag "<folder>" with "mine"
    Then the folder's tags are "mine" in Nextcloud
    And the folder's tags are unchanged in Grafana

    Examples: which folder it is changes nothing — none of them reach Grafana
      | folder        |
      | Demo          |
      | Demo/Team     |
      | Pointers/Team |

    # The two directions are deliberately not symmetric. A folder's tags live in
    # Grafana on a field only a deliberate API call writes, so a tag arriving from
    # there is always someone meaning it. A tag I apply in Nextcloud is usually me
    # filing my own folders, and pushing every one of those outward would fill
    # Grafana with somebody's private organisation. The dashboards inside are the
    # ones that carry tags both ways.
