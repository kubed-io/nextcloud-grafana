# Notes, decisions and history for this feature: ../AGENTS.md#mappingdelete

Feature: Removing a folder mapping
  As a Nextcloud admin
  I want removing a mapping to remove only the mapping
  So that disconnecting the two sides can never cost me a dashboard or a file

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
  # notes: ../AGENTS.md#removing-a-mapping-removes-only-the-mapping

    # ── RULE: the files stay, and become nobody's ─────────────────────────────

  @admin @in-nextcloud @occ @ui @unbuilt
  Scenario: Remove a sync mapping
    Given a dashboard file in "Demo"
    When the admin removes the "Demo" mapping
    Then "Demo" holds the same files it held before
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | absent              |
      | grafana_mode    | "unmapped"          |
    And the dashboard is in the "Demo" Grafana folder
    And there is exactly 1 configured mapping

    # It keeps its uid because the dashboard is still there. The file is simply no
    # longer claimed by anything, which is what an unmapped file is.

    # ── RULE: a link has nothing of its own, so it goes with its mapping ──────

  @admin @in-nextcloud @occ @ui @unbuilt
  Scenario: Remove a link mapping
    Given a dashboard file in "Pointers"
    When the admin removes the "links" mapping
    Then "Pointers" holds no dashboard files
    And the dashboard is in the "links" Grafana folder
    And there is exactly 1 configured mapping

    # A link is a pointer at something Grafana owns. Without the mapping it points
    # nowhere, and there is no content to keep — so it goes, as if never written.
