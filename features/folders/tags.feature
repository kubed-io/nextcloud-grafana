# Notes, decisions and history for this feature: ../AGENTS.md#folderstags

Feature: Tagging a folder
  As a Nextcloud user organising my files
  I want a tag I put on a folder to behave like any other Nextcloud tag
  So that I can file my folders my way without it meaning something in Grafana

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

    # ── RULE: a folder tag is a Nextcloud fact and stays one ──────────────────
    # notes: ../AGENTS.md#a-grafana-folder-has-nowhere-to-put-a-tag

  @user @in-nextcloud @gesture @ui @decision
  Scenario Outline: Tag a folder
    Given the folder "<folder>" holding three dashboards
    When I tag "<folder>" with "quarterly"
    Then "<folder>" is tagged "quarterly" in Nextcloud
    And "<folder>" can be found by a Nextcloud tag search for "quarterly"
    And Grafana is not contacted

    Examples: which folder it is changes nothing — no folder in Grafana takes a tag
      | folder         |
      | Demo           |
      | Demo/Team      |
      | Pointers/Team  |

    # A Grafana folder's whole spec is a title and a description. The tag could be
    # parked in its Kubernetes-style annotations, losslessly — but nothing in
    # Grafana can show, search, set or remove it, so it would be a mirror with one
    # surface. The dashboards inside are the ones that carry tags both ways.

  # notes: ../AGENTS.md#nothing-in-grafana-can-start-a-folder-tag-change
  @grafana @in-grafana @decision
  Scenario: A folder's tags never arrive from Grafana
    Given the folder "Demo/Team" is tagged "quarterly" in Nextcloud
    When a sync from Grafana runs
    Then "Demo/Team" is still tagged "quarterly" in Nextcloud

    # The mirror is one-directional here because the far side is empty, not because
    # the pull declines to look. A folder tag survives every sync for the same
    # reason it never causes one.
