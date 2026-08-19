# Notes, decisions and history for this feature: ../AGENTS.md#dashboardscreate

Feature: Creating a dashboard
  As a Nextcloud user
  I want a dashboard I make on either side to exist on both
  So that I can author dashboards without opening the Grafana UI

  Background:
    Given the app is connected to Grafana
    And the following mappings were made:
      | grafana folder | nc folder | mode | storage      | groups |
      | Demo           | Demo      | sync | admin folder |        |
      | links          | Pointers  | link | admin folder |        |
      | Shared         | Shared    | sync | team folder  | admin  |
    And the following items in the mappings:
      | path              |
      | /Demo/Team        |
      | /Pointers/Nested  |
      | /Shared/Quarterly |
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: creating on either side creates on both ─────────────────────────
    # The base case, seen twice: where you happened to be standing when you made
    # it is not a decision the user should have to make.

  @user @in-nextcloud @gesture @ui
  Scenario Outline: Create a new dashboard in a mapped folder
    When I create a new dashboard in "<nc folder>"
    Then a matching dashboard is created in Grafana
    And the dashboard is named after the file, in the "<grafana folder>" Grafana folder
    And "<nc folder>/New dashboard.grafana" holds:
      | grafana_uid        | the dashboard's uid |
      | grafana_mapping    | the mapping's id    |
      | grafana_mode       | "sync"              |
      | grafana_version    | set                 |
      | grafana_syncedHash | set                 |

    Examples: the folder is the whole input — the Background said what each one is
      | nc folder        | grafana folder    |
      | Demo             | Demo              |
      | Demo/Team        | Demo/Team         |
      | Shared           | Shared            |
      | Shared/Quarterly | Shared/Quarterly  |

    # A subfolder is not a second kind of destination — it is the same rule one
    # level down, which is exactly why it is worth a row rather than a scenario.

  @grafana @in-grafana @gesture @ui
  Scenario Outline: Create a dashboard in Grafana
    When someone creates a dashboard in the "<grafana folder>" Grafana folder
    Then a matching file is created in "<nc folder>"
    And the file holds:
      | grafana_uid        | the dashboard's uid |
      | grafana_mapping    | the mapping's id    |
      | grafana_mode       | "<mode>"            |
      | grafana_version    | <version>           |
      | grafana_syncedHash | set                 |
    And the file holds "<contents>"

    Examples: one gesture, and the mapping decides what the file is
      | grafana folder    | nc folder        | mode | version | contents                   |
      | Demo              | Demo             | sync | set     | the dashboard's full JSON  |
      | Demo/Team         | Demo/Team        | sync | set     | the dashboard's full JSON  |
      | links             | Pointers         | link | absent  | a pointer to the dashboard |
      | links/Nested      | Pointers/Nested  | link | absent  | a pointer to the dashboard |
      | Shared            | Shared           | sync | set     | the dashboard's full JSON  |

    # notes: ../AGENTS.md#only-a-mapping-renames-a-folder
    # A version records what a push last sent, and a link never pushes.

    # ── RULE: where the file lands decides whether it is a dashboard ───────────

  @user @in-nextcloud @gesture @ui
  Scenario: Create an unmapped dashboard
    When I create a new dashboard in "Scratch"
    Then no dashboard is created in Grafana
    And "Scratch/New dashboard.grafana" holds no Grafana metadata at all

  # notes: ../AGENTS.md#a-link-mapping-authors-nothing
  @user @in-nextcloud @gesture @ui
  Scenario Outline: Creating a dashboard in a link-mapped folder is refused
    When I try to create a new dashboard in "<nc folder>"
    Then the creation is refused with a message

    Examples: a link folder is Grafana's to write, at every depth
      | nc folder       |
      | Pointers        |
      | Pointers/Nested |

    # A link folder is Grafana's to write, so a file authored into one could never
    # become the dashboard it looks like. Refused at the door rather than accepted.
