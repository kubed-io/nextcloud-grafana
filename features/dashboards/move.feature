# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsmove

Feature: Moving a dashboard file
  As a Nextcloud user
  I want a move to mean the same thing in Grafana
  So that relocating a file never duplicates a dashboard or silently desyncs one

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | demo |
      | nc folder      | Demo |
      | mode           | sync |
    And a mapping with the following values:
      | grafana folder | reports |
      | nc folder      | Reports |
      | mode           | sync    |
    And a mapping with the following values:
      | grafana folder | links    |
      | nc folder      | Pointers |
      | mode           | link     |
    And a folder "Scratch" that is not mapped
    And a Grafana folder "Archive" that is not mapped
    And the Grafana recycle-bin folder is named "nextcloud-trash"

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a subfolder holding a dashboard is a Grafana folder too ─────────

  # notes: ../AGENTS.md#a-subfolder-is-in-grafana-when-a-dashboard-is-in-it
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Move a dashboard into a subfolder of its mapping
    Given a dashboard file in "Demo"
    And a folder "Demo/Team/Drafts" holding no dashboards
    When I move the file into "Demo/Team/Drafts"
    Then Grafana holds "Team" under "demo", and "Drafts" under "Team"
    And the dashboard is in the "Drafts" Grafana folder
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
      | grafana_mode    | the mapping's mode  |

    # @unbuilt — THIS IS THE SPEC, AND THE APP DOES THE OPPOSITE TODAY: a subfolder
    # is local-only and the dashboard stays put in the mapping's Grafana folder.

    # ── RULE: leaving a mapping, and what the recycle bin makes of it ──────────
    # notes: ../AGENTS.md#the-recycle-bin-folder

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Move a dashboard out of its mapping with the recycle bin off
    Given the Grafana recycle bin is off
    And a dashboard file in "Demo"
    When I move the file into "Scratch"
    Then the dashboard no longer exists in Grafana
    And the file holds no Grafana metadata at all
    And the full dashboard JSON is still in the Nextcloud file

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Move a dashboard out of its mapping with the recycle bin on
    Given the Grafana recycle bin is on
    And a dashboard file in "Demo"
    When I move the file into "Scratch"
    Then the dashboard is in the "nextcloud-trash" Grafana folder
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | absent              |
      | grafana_mode    | "unmapped"          |
    And the full dashboard JSON is still in the Nextcloud file

    # It keeps the uid because nothing was truly deleted — the dashboard is parked,
    # and moving the file back into a mapping is what brings it out again.

    # ── RULE: a link is read-only, so it does not travel ───────────────────────

  # notes: ../AGENTS.md#a-link-cannot-be-deleted-from-nextcloud
  @user @in-nextcloud @gesture @ui
  Scenario: Move a link out of its mapping
    Given a dashboard file in "Pointers"
    When I try to move the file into "Scratch"
    Then the move is refused with a message
    And the file stays in "Pointers"

  # notes: ../AGENTS.md#a-copy-never-changes-a-files-mode
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario Outline: Move a dashboard between sync and link folders
    Given a dashboard file in "<source>"
    When I try to move the file into "<destination>"
    Then the move is refused with a message
    And the file stays in "<source>"

    Examples: a mode is a property of the folder, and a move may not change one
      | source   | destination |
      | Demo     | Pointers    |
      | Pointers | Demo        |

    # ── RULE: arriving in a mapping — the same dashboard, or a new one ─────────

  @user @in-nextcloud @gesture @ui
  Scenario: Move an unmapped file into a mapping
    Given a dashboard file in "Scratch"
    When I move the file into "Demo"
    Then a matching dashboard is created in Grafana
    And the dashboard is named after the file, in the "demo" Grafana folder
    And the file holds:
      | grafana_uid     | set                |
      | grafana_mapping | the mapping's id   |
      | grafana_mode    | the mapping's mode |

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Move a dashboard from one mapping to another
    Given a dashboard file in "Demo"
    When I move the file into "Reports"
    Then the dashboard is in the "reports" Grafana folder
    And the dashboard is not deleted or recreated
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
      | grafana_mode    | the mapping's mode  |

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Move a file back into a mapping while its dashboard sits in the bin
    Given the Grafana recycle bin is on
    And an unmapped dashboard file whose Grafana dashboard is parked in the "nextcloud-trash" folder
    When I move the file into "Reports"
    Then the dashboard is in the "reports" Grafana folder
    And the dashboard keeps the same "grafana_uid"
    And the file holds:
      | grafana_mapping | the mapping's id   |
      | grafana_mode    | the mapping's mode |

    # ── RULE: a move that cannot finish leaves the file as it was ──────────────

  # notes: ../AGENTS.md#a-failed-grafana-delete-on-move-out-never-strips-the-files-identity
  @user @in-nextcloud @gesture @ui @blocked
  Scenario: Move a dashboard out of its mapping while Grafana is unreachable
    Given a dashboard file in "Demo"
    And Grafana is unreachable
    When I try to move the file into "Scratch"
    Then the move is aborted and the file stays in "Demo"
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |

    # ── RULE: a dashboard moved in Grafana takes its file with it ──────────────

  # notes: ../AGENTS.md#a-dashboard-moved-to-another-mapped-folder-in-grafana-relocates-its-mirror
  @grafana @in-grafana @gesture @ui @todo
  Scenario: Move a dashboard to another mapped folder in Grafana
    Given a dashboard file in "Demo"
    When someone moves the dashboard into the "reports" Grafana folder
    Then the file is gone from "Demo"
    And the file arrives in "Reports", holding:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
      | grafana_mode    | the mapping's mode  |

  @grafana @in-grafana @gesture @ui @todo
  Scenario: Move a dashboard to an unmapped folder in Grafana
    Given a dashboard file in "Demo"
    When someone moves the dashboard into the "Archive" Grafana folder
    Then the file is gone from "Demo"
    And the file is recoverable from the Nextcloud trash
    And the dashboard still exists in Grafana
