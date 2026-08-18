# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsmove

Feature: Moving a dashboard file
  As a Nextcloud user
  I want a move to mean the same thing in Grafana
  So that relocating a file never duplicates a dashboard or silently desyncs one

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Demo         |
      | nc folder      | Demo         |
      | mode           | sync         |
      | storage        | admin folder |
    And a mapping with the following values:
      | grafana folder | Reports      |
      | nc folder      | Reports      |
      | mode           | sync         |
      | storage        | admin folder |
    And a mapping with the following values:
      | grafana folder | links        |
      | nc folder      | Pointers     |
      | mode           | link         |
      | storage        | admin folder |
    And a mapping with the following values:
      | grafana folder | mirrors      |
      | nc folder      | Mirrors      |
      | mode           | link         |
      | storage        | admin folder |
    And a folder "Scratch" that is not mapped
    And a Grafana folder "Archive" that is not mapped
    And the Grafana recycle-bin folder is named "nextcloud-trash"

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: inside its own mapping, the subfolder decides ────────────────────

  # notes: ../AGENTS.md#moving-into-an-untagged-subfolder-is-local-only-stays-bound-to-the-parent
  @user @in-nextcloud @gesture @ui @todo
  Scenario: Move a dashboard into an untagged subfolder of its mapping
    Given a dashboard file in "Demo"
    When I move the file into an untagged subfolder of "Demo"
    Then the dashboard stays in the "Demo" Grafana folder
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
      | grafana_mode    | the mapping's mode  |

  # notes: ../AGENTS.md#moving-a-dashboard-into-a-tagged-subfolder-re-parents-it-in-grafana
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Move a dashboard into a tagged subfolder of its mapping
    Given a dashboard file in "Demo"
    And a subfolder of "Demo" that is its own Grafana folder
    When I move the file into that subfolder
    Then the dashboard is in that subfolder's Grafana folder
    And the dashboard keeps the same "grafana_uid"

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

    # ── RULE: a link belongs to Grafana — it may re-home, but never leave ──────

  # notes: ../AGENTS.md#a-link-cannot-be-deleted-from-nextcloud
  @user @in-nextcloud @gesture @ui
  Scenario: Move a link out of its mapping
    Given a dashboard file in "Pointers"
    When I try to move the file into "Scratch"
    Then the move is refused with a message
    And the file stays in "Pointers"

  # notes: ../AGENTS.md#moving-a-link-from-one-mapped-folder-to-another-only-re-homes-the-pointer
  @user @in-nextcloud @gesture @ui @todo
  Scenario: Move a link to another link mapping
    Given a dashboard file in "Pointers"
    When I move the file into "Mirrors"
    Then Grafana is not contacted
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
      | grafana_mode    | "link"              |

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
    And the dashboard is named after the file, in the "Demo" Grafana folder
    And the file holds:
      | grafana_uid     | set                |
      | grafana_mapping | the mapping's id   |
      | grafana_mode    | the mapping's mode |

  @user @in-nextcloud @gesture @ui
  Scenario: Move a dashboard from one mapping to another
    Given a dashboard file in "Demo"
    When I move the file into "Reports"
    Then the dashboard is in the "Reports" Grafana folder
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
    Then the dashboard is in the "Reports" Grafana folder
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
  @grafana @in-grafana @gesture @ui
  Scenario: Move a dashboard to another mapped folder in Grafana
    Given a dashboard file in "Demo"
    When someone moves the dashboard into the "Reports" Grafana folder
    Then the file is gone from "Demo"
    And the file arrives in "Reports", holding:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
      | grafana_mode    | the mapping's mode  |

  @grafana @in-grafana @gesture @ui
  Scenario: Move a dashboard to an unmapped folder in Grafana
    Given a dashboard file in "Demo"
    When someone moves the dashboard into the "Archive" Grafana folder
    Then the file is gone from "Demo"
    And the file is recoverable from the Nextcloud trash
    And the dashboard still exists in Grafana
