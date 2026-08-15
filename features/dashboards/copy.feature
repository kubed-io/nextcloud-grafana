# Notes, decisions and history for this feature: ../AGENTS.md#dashboardscopy

Feature: Copying a dashboard
  As a Nextcloud user
  I want a copy to be a new dashboard, never a hijack of the original
  So that copying a file is safe and predictable

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
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: the copy belongs to where it lands, never to where it came from ──

  # notes: ../AGENTS.md#the-copy-belongs-to-where-it-lands
  @user @in-nextcloud @gesture @ui
  Scenario Outline: Copy a dashboard into a mapped folder
    Given a dashboard file in "<source>"
    When I copy the file into "Demo"
    Then the copy holds:
      | grafana_uid     | its own, not the original's                |
      | grafana_mapping | the mapping's id                           |
      | grafana_mode    | the mapping's mode                         |
      | Created         | when the dashboard was created in Grafana  |
      | Modified        | when the dashboard last changed in Grafana |
    And the copy is a new dashboard in the "Demo" Grafana folder
    And the original file and its dashboard are unchanged

    # The clocks are the COPY'S OWN. A new dashboard was born here, so its dates are
    # its own birth — inheriting the original's would date it before it existed.

    Examples: wherever it came from, it belongs to Demo now
      | source  |
      | Demo    |
      | Scratch |

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Copy a dashboard into an unmapped folder
    Given a dashboard file in "<source>"
    When I copy the file into "Scratch"
    Then the copy holds no Grafana metadata at all
    And no dashboard is created in Grafana for the copy
    And the copy's body is byte-for-byte the original's
    And the original file and its dashboard are unchanged

    Examples: the identity is stripped, the body it travelled with is not
      | source   |
      | Demo     |
      | Pointers |
      | Scratch  |

  # notes: ../AGENTS.md#a-copy-never-changes-a-files-mode
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario Outline: Copy a dashboard between sync and link folders
    Given a dashboard file in "<source>"
    When I try to copy the file into "<destination>"
    Then the copy is refused with a message
    And no dashboard is created in Grafana for the copy
    And "<destination>" holds no copy of the file
    And the original file and its dashboard are unchanged

    Examples: a link is read-only, so a copy neither authors into one nor escapes it
      | source   | destination |
      | Demo     | Pointers    |
      | Pointers | Demo        |

    # @unbuilt — THIS IS THE SPEC, AND THE APP DOES THE OPPOSITE TODAY: it accepts
    # the copy and gives it the landing folder's mode.

    # ── RULE: a dashboard copied in Grafana arrives as its own file ────────────

  # notes: ../AGENTS.md#a-dashboard-copied-in-grafana-arrives-as-its-own-file
  @grafana @in-grafana @gesture @ui
  Scenario Outline: Copy a dashboard in Grafana
    Given a dashboard file in "<folder>"
    When someone copies its dashboard in Grafana
    Then the copy arrives as its own file in "<folder>"
    And that file holds:
      | grafana_uid     | its own, not the original's |
      | grafana_mapping | the mapping's id            |
      | grafana_mode    | the mapping's mode          |
    And the original file and its dashboard are unchanged

    Examples: the mapping it lands in decides the mode, not the file it came from
      | folder   |
      | Demo     |
      | Pointers |
