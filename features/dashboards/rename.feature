# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsrename

Feature: Renaming a dashboard
  As a Nextcloud user
  I want a rename made anywhere to reach the other two places
  So that the file name, its JSON title, and the Grafana dashboard never drift

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Demo         |
      | nc folder      | Demo         |
      | mode           | sync         |
      | storage        | admin folder |
    And a mapping with the following values:
      | grafana folder | links        |
      | nc folder      | Pointers     |
      | mode           | link         |
      | storage        | admin folder |
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a name is one value living in three places ──────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Rename the file in nextcloud
    Given a dashboard file named "Old Name.grafana" in "Demo"
    When I rename the file to "<new name>.grafana"
    Then the file is named "<new name>.grafana"
    And the JSON title is "<new name>"
    And the dashboard is named "<new name>" in Grafana
    And the file holds:
      | grafana_uid     | the dashboard's uid                        |
      | grafana_mapping | the mapping's id                           |
      | Modified        | when the dashboard last changed in Grafana |

    Examples: names that look like something the filename grammar means
      | new name                |
      | New Name                |
      | v1.2 board              |
      | Cluster (eu-west-1)     |
      | Latency — p99 · eu-west |

    # Every row is a name the CODEC could misread, not a decorative charset test: a
    # dot is where it looks for a uid and brackets are how it spells a collision.
    # notes: ../AGENTS.md#three-titles-the-filename-grammar-cannot-read-back

  @user @in-nextcloud @gesture @ui
  Scenario: Rename the dashboard inside the file
    Given a dashboard file named "Old Name.grafana" in "Demo"
    When I change the JSON "title" field to "New Name"
    Then the file is named "New Name.grafana"
    And the JSON title is "New Name"
    And the dashboard is named "New Name" in Grafana
    And the file holds:
      | grafana_uid     | the dashboard's uid                        |
      | grafana_mapping | the mapping's id                           |
      | Modified        | when the dashboard last changed in Grafana |

  # notes: ../AGENTS.md#renaming-a-dashboard-in-grafana-renames-the-mirrored-file
  @grafana @in-grafana @gesture @ui
  Scenario Outline: Rename the dashboard in Grafana
    Given a dashboard file named "Old Name.grafana" in "<folder>"
    When someone renames the dashboard to "New Name" in Grafana
    Then the file is named "New Name.grafana"
    And the JSON title is "New Name"
    And the dashboard is named "New Name" in Grafana
    And the file holds:
      | grafana_uid     | the dashboard's uid                        |
      | grafana_mapping | the mapping's id                           |
      | Modified        | when the dashboard last changed in Grafana |
    And there is exactly one file for that dashboard

    Examples: a link's body is a pointer, but its name still mirrors
      | folder   |
      | Demo     |
      | Pointers |

    # ── RULE: a link is read-only, so its name is Grafana's to set ────────────

  # notes: ../AGENTS.md#renaming-a-link-never-renames-the-dashboard
  @user @in-nextcloud @gesture @ui
  Scenario: Rename a link in Nextcloud
    Given a dashboard file named "Old Name.grafana" in "Pointers"
    When I try to rename the file to "New Name.grafana"
    Then the rename is refused with a message
    And the file is named "Old Name.grafana"
    And the JSON title is "Old Name"
    And the dashboard is named "Old Name" in Grafana

    # @unbuilt — THIS IS THE SPEC, AND THE APP DOES THE OPPOSITE TODAY: it renames
    # the file and leaves the dashboard alone, so the two disagree until the next
    # pull silently renames the file back.

    # ── RULE: two dashboards may share a title, two files may not ─────────────
    # notes: ../AGENTS.md#the-suffix-is-nextclouds-alone

  @grafana @in-grafana @gesture @ui @unbuilt
  Scenario: Rename a dashboard in Grafana to a title another one already has
    Given a dashboard file named "Alpha.grafana" in "Demo"
    And a dashboard file named "Beta.grafana" in "Demo"
    When someone renames the "Beta" dashboard to "Alpha" in Grafana
    Then "Demo/Alpha.grafana" holds:
      | grafana_uid | the uid it had before the rename |
    And "Demo/Alpha (2).grafana" holds:
      | grafana_uid | the uid of the renamed dashboard |
    And the JSON title of both files is "Alpha"
    And both dashboards are titled "Alpha" in Grafana

    # The file that held the name keeps it; the arriving one takes the suffix.

    # ── RULE: a dashboard always has a name, so the app never invents one ─────
    # Two halves from opposite ends: refused on the way out, fallen back on the way in.

  # notes: ../AGENTS.md#a-rename-to-an-empty-or-whitespace-only-name-is-refused
  @user @in-nextcloud @gesture @ui
  Scenario: Rename a dashboard file to a blank name
    Given a dashboard file named "Old Name.grafana" in "Demo"
    When I try to rename the file to a name that is only whitespace
    Then the rename is refused with a message
    And the file is named "Old Name.grafana"
    And the JSON title is "Old Name"
    And the dashboard is named "Old Name" in Grafana

  # notes: ../AGENTS.md#the-app-never-invents-a-substitute-name
  # BLOCKED: Grafana refuses to store an empty title — "Dashboard title cannot be
  # empty", measured. The behaviour stands; the state cannot be arranged from here.
  @grafana @in-grafana @gesture @ui @blocked
  Scenario: Rename a dashboard to nothing in Grafana
    Given a dashboard file named "Old Name.grafana" in "Demo"
    When someone renames the dashboard to "" in Grafana
    Then the file is named after the dashboard's uid

    # Falling back to the uid is honest and reversible. Inventing "Untitled" would
    # collide the moment a second nameless dashboard appeared.

    # ── RULE: a rename outside every mapping is nobody's business but Nextcloud's

  @user @in-nextcloud @gesture @ui
  Scenario: Rename an unmapped dashboard file
    Given a dashboard file named "Old Name.grafana" in "Scratch"
    When I rename the file to "New Name.grafana"
    Then the file is named "New Name.grafana"
    And the file holds no Grafana metadata at all

    # ── RULE: a rename we cannot propagate still stands locally ───────────────

  # notes: ../AGENTS.md#a-failed-propagation-never-reverts-the-local-rename
  @user @in-nextcloud @gesture @ui @blocked
  Scenario: Rename a file while Grafana is unreachable
    Given a dashboard file named "Old Name.grafana" in "Demo"
    And Grafana is unreachable
    When I rename the file to "New Name.grafana"
    Then the file is named "New Name.grafana"
    And the failure is reported to the user
    And the file holds:
      | grafana_uid | the dashboard's uid |

    # Nextcloud has already renamed it, and reverting would fight the user over a
    # gesture that succeeded locally.
