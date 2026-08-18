# Notes, decisions and history for this feature: ../AGENTS.md#dashboardscopy

Feature: Copying a dashboard
  As a Nextcloud user
  I want a copy to be a new dashboard, never a hijack of the original
  So that copying a file is safe and predictable

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
    And a mapping with the following values:
      | grafana folder | Shared      |
      | nc folder      | Shared      |
      | mode           | sync        |
      | storage        | team folder |
      | groups         | admin       |
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: the copy belongs to where it lands, never to where it came from ──
    # The base case from both sides — the destination is the whole input.

  # notes: ../AGENTS.md#the-copy-belongs-to-where-it-lands
  @user @in-nextcloud @gesture @ui
  Scenario Outline: Copy a dashboard into a mapped folder
    Given a dashboard file named "Fleet Health.grafana" in "<source>"
    When I copy the file into "<destination>"
    Then the copy holds:
      | filename           | "<copy>"                                  |
      | title in the file  | "<named>"                                 |
      | title in Grafana   | "<named>"                                 |
      | grafana_uid        | its own, not the original's               |
      | grafana_mapping    | the mapping's id                          |
      | grafana_mode       | the mapping's mode                        |
      | grafana_version    | set                                       |
      | grafana_syncedHash | set                                       |
      | Created            | when the dashboard was created in Grafana |
    And the copy is a new dashboard in the "<destination>" Grafana folder
    And the original file and its dashboard are unchanged

  # notes: ../AGENTS.md#the-modified-clock-a-copy-cannot-keep-until-the-next-sync

    Examples: Nextcloud names the copy, and that is its name everywhere — both storage kinds
      | source  | destination | copy                     | named            |
      | Demo    | Demo        | Fleet Health (1).grafana | Fleet Health (1) |
      | Scratch | Demo        | Fleet Health.grafana     | Fleet Health     |
      | Demo    | Shared      | Fleet Health.grafana     | Fleet Health     |

  # notes: ../AGENTS.md#a-copy-made-in-nextcloud-is-named-by-nextcloud

  # notes: ../AGENTS.md#a-dashboard-copied-in-grafana-arrives-as-its-own-file
  @grafana @in-grafana @gesture @ui
  Scenario Outline: Copy a dashboard in Grafana
    Given a dashboard file named "Fleet Health.grafana" in "<folder>"
    When someone copies its dashboard in Grafana, keeping the title
    Then the copy arrives as its own file in "<folder>"
    And that file holds:
      | filename          | "Fleet Health (1).grafana"  |
      | title in the file | "Fleet Health"              |
      | title in Grafana  | "Fleet Health"              |
      | grafana_uid       | its own, not the original's |
      | grafana_mapping   | the mapping's id            |
      | grafana_mode      | the mapping's mode          |
    And the original file and its dashboard are unchanged

    Examples: the mapping it lands in decides the mode, not the file it came from
      | folder   |
      | Demo     |
      | Pointers |

  # notes: ../AGENTS.md#a-copy-made-in-grafana-is-named-by-grafana

    # ── RULE: a link is not copyable, and a link mapping is not a destination ──

  # notes: ../AGENTS.md#a-link-is-not-copyable-and-a-link-mapping-is-not-a-destination
  @user @in-nextcloud @gesture @ui
  Scenario Outline: Copying a link, or into a link mapping, is refused
    Given a dashboard file named "Fleet Health.grafana" in "<source>"
    When I try to copy the file into "<destination>"
    Then the copy is refused with a message
    And no file is added to "<destination>"
    And no dashboard is created in Grafana for the copy
    And the original file and its dashboard are unchanged

    Examples: a link is read-only in Nextcloud, and there is nowhere it may go
      | source   | destination |
      | Pointers | Demo        |
      | Pointers | Scratch     |
      | Pointers | Pointers    |

    Examples: and a link mapping is filled from Grafana, whatever is arriving
      | source | destination |
      | Demo   | Pointers    |

    # ── RULE: a copy landing outside every mapping is a plain document ─────────

  # notes: ../AGENTS.md#a-copy-landing-outside-every-mapping-is-a-plain-document
  @user @in-nextcloud @gesture @ui
  Scenario Outline: Copy a dashboard into an unmapped folder
    Given a dashboard file named "Fleet Health.grafana" in "<source>"
    When I copy the file into "Scratch"
    Then the copy holds no Grafana metadata at all
    And no dashboard is created in Grafana for the copy
    And the copy's body is byte-for-byte the original's
    And the original file and its dashboard are unchanged

    Examples: the identity is stripped, the body it travelled with is not
      | source  |
      | Demo    |
      | Scratch |

  # notes: ../AGENTS.md#the-second-suffix-and-the-pull-that-used-to-fight-it
  @grafana @in-grafana @gesture @ui
  Scenario: Three dashboards in Grafana wearing one title
    Given a dashboard file named "Fleet Health.grafana" in "Demo"
    When someone copies its dashboard in Grafana, keeping the title
    And someone copies its dashboard in Grafana, keeping the title
    Then "Demo" holds one file per dashboard, named:
      | Fleet Health.grafana     |
      | Fleet Health (1).grafana |
      | Fleet Health (2).grafana |
    And all three dashboards are still titled "Fleet Health" in Grafana
