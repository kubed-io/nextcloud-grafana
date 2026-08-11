# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsedit

Feature: Editing a dashboard
  As someone who keeps dashboards in Nextcloud
  I want an edit made on either side to reach the other
  So that the file I edited and the dashboard it mirrors do not drift apart

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | demo |
      | nc folder      | Demo |
      | mode           | sync |
    And a mapping with the following values:
      | grafana folder | links    |
      | nc folder      | Pointers |
      | mode           | link     |
    And a folder "Scratch" that is not mapped

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: an edit to a mirror is an edit to the dashboard ────────────────
    # notes: ../AGENTS.md#a-local-edit-reaches-its-dashboard-in-grafana

  @user @in-nextcloud @gesture @ui
  Scenario: Edit a dashboard file
    Given a dashboard file in "Demo"
    When I edit the file's panels and save
    Then the dashboard in Grafana holds the file's panels
    And the file holds:
      | grafana_uid        | the dashboard's uid                        |
      | grafana_mapping    | the mapping's id                           |
      | grafana_mode       | the mapping's mode                         |
      | grafana_version    | set                                        |
      | grafana_syncedHash | set                                        |
      | Modified           | when the dashboard last changed in Grafana |

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Edit a dashboard file outside every mapping
    Given a dashboard file in "Scratch"
    When I edit the file's panels and save
    Then the file holds no Grafana metadata at all

    # ── RULE: an edit made in Grafana reaches the mirror, dates and all ───────
    # notes: ../AGENTS.md#an-edit-in-grafana-reaches-the-mirrored-file

  @grafana @in-grafana @gesture @ui @unbuilt
  Scenario: Edit a dashboard in Grafana that a sync mirrors
    Given a dashboard file in "Demo"
    When someone edits the dashboard's panels in Grafana
    Then the file holds the dashboard's panels as Grafana has them
    And the file holds:
      | grafana_uid        | the dashboard's uid                        |
      | grafana_mapping    | the mapping's id                           |
      | grafana_mode       | the mapping's mode                         |
      | grafana_version    | set                                        |
      | grafana_syncedHash | set                                        |
      | Modified           | when the dashboard last changed in Grafana |

  @grafana @in-grafana @gesture @ui @unbuilt
  Scenario: Edit a dashboard in Grafana that a link mirrors
    Given a dashboard file in "Pointers"
    When someone edits the dashboard's panels in Grafana
    Then the file holds a pointer:
      | uid   | the dashboard's uid           |
      | title | the dashboard's title         |
      | url   | a deep link to it in Grafana  |
    And the file holds:
      | grafana_uid     | the dashboard's uid                        |
      | grafana_mapping | the mapping's id                           |
      | grafana_mode    | the mapping's mode                         |
      | Modified        | when the dashboard last changed in Grafana |

    # A link holds a pointer, so an edit in Grafana moves its dates and its title
    # without ever putting the dashboard's panels in the file.

    # ── RULE: the metadata is the app's record, and nobody else may edit it ───
    # notes: ../AGENTS.md#what-the-app-manages-only-the-app-changes

  @user @dav @todo
  Scenario: A client cannot edit the metadata the app stamps
    Given a dashboard file in "Demo"
    When a client tries to change every property the app stamps via PROPPATCH
    Then every change is refused
    And the file holds:
      | grafana_uid     | the dashboard's uid |
      | grafana_mapping | the mapping's id    |
      | grafana_mode    | the mapping's mode  |

    # It moved here from view.feature: a client attempting a change is an edit
    # gesture that gets refused, and nothing about it is viewing.
