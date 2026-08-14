# Notes, decisions and history for this feature: ../AGENTS.md#dashboardscreate

Feature: Creating a dashboard
  As a Nextcloud user
  I want a dashboard I make on either side to exist on both
  So that I can author dashboards without opening the Grafana UI

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

    # ── RULE: where the file lands decides whether it is a dashboard ───────────

  @user @in-nextcloud @gesture @ui
  Scenario: Create a new dashboard in a mapped folder
    When I create "CPU Load.grafana.json" in "Demo" via the Files "New" menu
    Then a matching dashboard is created in Grafana
    And the dashboard is named "CPU Load", in the "Demo" Grafana folder
    And "Demo/CPU Load.grafana.json" holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
      | grafana_mode    | "sync"              |

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Create an unmapped dashboard
    When I create "CPU Load.grafana.json" in "Scratch"
    Then no dashboard is created in Grafana
    And "Scratch/CPU Load.grafana.json" holds no Grafana metadata at all

  # notes: ../AGENTS.md#a-link-mapping-authors-nothing
  @user @in-nextcloud @gesture @ui @todo
  Scenario: Creating a dashboard file in a link-mapped folder is refused
    When I try to create "CPU Load.grafana.json" in "Pointers"
    Then the creation is refused with a message
    And no dashboard is created in Grafana
    And "Pointers" holds no file named "CPU Load.grafana.json"

    # A link folder is Grafana's to write, so a file authored into one could never
    # become the dashboard it looks like. Refused at the door rather than accepted.

    # ── RULE: a creation that cannot finish leaves a plain file ────────────────

  # notes: ../AGENTS.md#a-failed-creation-leaves-an-unstamped-file-not-a-half-managed-one
  @user @in-nextcloud @gesture @ui @todo
  Scenario Outline: A body that cannot become a dashboard leaves a plain file
    When I create "CPU Load.grafana.json" in "Demo" holding <body>
    Then no dashboard is created in Grafana
    And the failure is reported to the user, naming what was wrong with it
    And "Demo/CPU Load.grafana.json" holds no Grafana metadata at all

    Examples: caught here or caught by Grafana, the file is left as the user wrote it
      | body                        |
      | text that will not parse    |
      | a dashboard Grafana rejects |

    # ── RULE: a dashboard made in Grafana arrives in the folder mapped to it ───

  @grafana @in-grafana @gesture @ui @todo
  Scenario Outline: Create a dashboard in Grafana
    When someone creates the dashboard "CPU Load" in the "<grafana folder>" Grafana folder
    Then "<nc folder>/CPU Load.grafana.json" holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
      | grafana_mode    | "<mode>"            |
    And the file holds "<contents>"

    Examples: one gesture, and the mapping decides what the file is
      | grafana folder | nc folder | mode | contents                   |
      | demo           | Demo      | sync | the dashboard's full JSON  |
      | links          | Pointers  | link | a pointer to the dashboard |
