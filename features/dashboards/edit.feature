# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsedit

Feature: Editing a dashboard file
  As someone who keeps dashboards in Nextcloud
  I want my edits to reach Grafana
  So that the file I edited and the dashboard it mirrors do not drift apart

  Background:
    Given the app is connected to Grafana

  # Editing is the behaviour; the push and the pull are how it travels.
  # notes: ../AGENTS.md#dashboardsedit

  @admin @in-nextcloud @occ @ui
  Scenario: A local edit reaches its dashboard in Grafana
    Given an admin-owned mapping from Grafana folder "nc-bravo" to Nextcloud folder "bravo-dash"
    And the admin pulls from Grafana
    When the dashboard file "bravo-dash/Bravo Demo.grafana.json" is edited to title "Bravo Demo (edited by NC)"
    And the admin pushes to Grafana
    Then the Grafana dashboard "nc-bravo-demo" has title "Bravo Demo (edited by NC)"
    # notes: ../AGENTS.md#a-local-edit-reaches-its-dashboard-in-grafana

  @grafana @in-grafana @occ @unbuilt
  Scenario: An edit in Grafana reaches the mirrored file
    Given a managed "sync" dashboard file in the "flows" folder
    When its dashboard is edited in Grafana
    Then the file body is updated from Grafana
    And the file's Nextcloud system tags match the dashboard's Grafana tags
    And the file keeps its "grafana_uid"
    # notes: ../AGENTS.md#an-edit-in-grafana-reaches-the-mirrored-file
