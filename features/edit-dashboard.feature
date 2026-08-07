# Notes, decisions and history for this feature: AGENTS.md#edit-dashboard

Feature: Editing a dashboard file
  As someone who keeps dashboards in Nextcloud
  I want my edits to reach Grafana
  So that the file I edited and the dashboard it mirrors do not drift apart

  Background:
    Given the app is connected to Grafana

  # EDITING IS THE BEHAVIOUR; THE PUSH IS HOW IT TRAVELS.
  #
  # This scenario used to live in a "Manual per-mapping sync" file as "the admin
  # clicks Sync to Grafana", which described the button rather than what anyone was
  # trying to do. Nobody edits a dashboard in order to press a button — they edit
  # it so Grafana gets the change, and the app offers three ways for that to happen
  # (on save, on the button, on the schedule). Those are mechanisms; this is the
  # behaviour they serve.
  # notes: AGENTS.md#a-local-edit-reaches-its-dashboard-in-grafana

  @admin @in-nextcloud @occ @ui
  Scenario: A local edit reaches its dashboard in Grafana
    Given an admin-owned mapping from Grafana folder "nc-bravo" to Nextcloud folder "bravo-dash"
    And the admin pulls from Grafana
    When the dashboard file "bravo-dash/Bravo Demo.grafana.json" is edited to title "Bravo Demo (edited by NC)"
    And the admin pushes to Grafana
    Then the Grafana dashboard "nc-bravo-demo" has title "Bravo Demo (edited by NC)"
    # THE UID IS THE THREAD, so the edit lands on the dashboard the file already
    # named — same dashboard, same folder, never a duplicate.
    #
    # The bravo folder, not alpha, so an edit here never mutates the fixture
    # sync-now.feature asserts an untouched mirror against.
