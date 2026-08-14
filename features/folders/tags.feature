# Notes, decisions and history for this feature: ../AGENTS.md#folderstags

Feature: Tagging a folder
  As a Grafana admin browsing folders in Nextcloud
  I want a folder's tags to be one set however I reach them
  So that I can re-tag a folder from whichever side I happen to be on

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

    # ── RULE: a folder's tags are one set, changed from either side ───────────
    # notes: ../AGENTS.md#a-folders-tags-are-one-set-on-both-sides

  @user @in-nextcloud @gesture @ui @todo
  Scenario Outline: Tag a folder in Nextcloud
    Given the folder "<folder>" whose tags are "<tags before>"
    When I change the Nextcloud tags to "<tags after>"
    Then the folder's tags are "<tags after>" in Nextcloud
    And the folder's tags are "<tags after>" in Grafana
    And the folder holds:
      | Modified | when the folder last changed in Grafana |

    Examples: the mapped folder and a subfolder under it are the same gesture
      | folder    | tags before    | tags after     |
      | Demo      | quarterly      | quarterly, ops |
      | Demo      |                | quarterly      |
      | Demo/Team | quarterly, ops | quarterly      |
      | Demo/Team | quarterly      | archived       |
      | Demo/Team | quarterly      |                |

  @grafana @in-grafana @todo
  Scenario Outline: Tag a folder in Grafana
    Given the folder "<folder>" whose tags are "<tags before>"
    When the folder's tags are changed to "<tags after>" in Grafana
    Then the folder's tags are "<tags after>" in Nextcloud
    And the folder's tags are "<tags after>" in Grafana
    And the folder holds:
      | Modified | when the folder last changed in Grafana |

    Examples: Grafana is the system of record, so its set wins outright
      | folder    | tags before    | tags after     |
      | Demo      | quarterly      | quarterly, ops |
      | Demo/Team | quarterly, ops | quarterly      |
      | Demo/Team | quarterly      | archived       |
      | Demo/Team | quarterly      |                |
      | Demo/Team |                | quarterly      |

    # ── RULE: a change only travels where the mode lets it ────────────────────

  # notes: ../AGENTS.md#tagging-a-folder-in-a-link-mapping-does-not-reach-grafana
  @user @in-nextcloud @gesture @ui @todo
  Scenario: Tag a folder in a link mapping
    Given the folder "Pointers/Team" whose tags are "quarterly"
    When I change the Nextcloud tags to "quarterly, mine"
    Then the folder's tags are still "quarterly" in Grafana
    And the folder's tags settle back to "quarterly" in Nextcloud
