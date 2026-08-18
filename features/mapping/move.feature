# Notes, decisions and history for this feature: ../AGENTS.md#mappingmove

Feature: Moving a mapped folder
  As a Nextcloud admin
  I want moving either side of a mapping to change nothing about the mapping
  So that reorganising folders never quietly disconnects dashboards from Grafana

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Demo |
      | nc folder      | Demo |
      | mode           | sync |
    And a folder "Archive" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background
  # notes: ../AGENTS.md#a-mapping-is-a-pair-of-ids-so-a-move-is-a-no-op

    # ── RULE: a mapping is held by id on both sides, so a move reaches it ─────

  @admin @in-nextcloud @gesture @ui @todo
  Scenario: Move the mapped Nextcloud folder
    Given a dashboard file named "Fleet Health.grafana" in "Demo"
    When I move "Demo" into "Archive"
    Then the mapping's Nextcloud folder is "Archive/Demo"
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
      | grafana_mode    | the mapping's mode  |
    And the dashboard is in the "Demo" Grafana folder

    # Nothing is sent to Grafana. The mapping names a folder UID there, and the
    # Nextcloud folder it pairs with is the one it has always pointed at.

  # notes: ../AGENTS.md#moving-the-mapped-folder-in-grafana-does-not-break-the-mapping
  @grafana @in-grafana @gesture @ui @todo
  Scenario: Move the mapped Grafana folder
    Given a dashboard file named "Fleet Health.grafana" in "Demo"
    And a Grafana folder "Retired" that is not mapped
    When someone moves the "Demo" Grafana folder under "Retired"
    Then the mapping's Grafana folder is still the one it was created with
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
    And the file is in "Demo"

    # The Nextcloud folder does NOT follow. A mapping is the one place a pair of
    # differing locations is legitimate, exactly as it is for a pair of names.

    # ── RULE: a folder that is gone is gone, not re-adopted by name ───────────

  # notes: ../AGENTS.md#a-mapped-folder-that-was-deleted-is-not-re-adopted-by-name
  @admin @in-nextcloud @gesture @ui @unbuilt
  Scenario: A new folder reusing the mapped folder's name is not adopted
    Given a dashboard file named "Fleet Health.grafana" in "Demo"
    And "Demo" is in the Nextcloud trash
    When I create the folder "Demo"
    Then the mapping does not resolve to the new "Demo"
    And a dashboard file created in the new "Demo" is not managed

    # A folder that merely shares the name is a different folder, and adopting it
    # would point the mapping at something nobody chose.
