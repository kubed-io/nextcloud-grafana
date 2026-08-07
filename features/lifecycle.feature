# Notes, decisions and history for this feature: AGENTS.md#lifecycle

Feature: App install lifecycle
  As a Nextcloud admin
  I want the grafana_sync app to enable and disable cleanly
  So that installing or removing it never leaves the instance broken

  @admin @occ @ui
  Scenario: Enabling the app
    When the admin enables the app
    Then the app should be enabled
    And the app is installed correctly
    And ".grafana.json" files are registered as their own file type
    # THE MIMETYPE IS AN END STATE OF ENABLING, not a feature of its own. It used
    # to head a file called "Grafana dashboard is a first-class file type", which
    # described the registration as though someone had gone and done it — but
    # nobody registers a mimetype; they install an app, and the registration is
    # what the install left behind. Its visible consequence (a mapped folder that
    # looks like dashboards) is view-dashboard.feature's, and its removal is
    # uninstall.feature's.
    # notes: AGENTS.md#enabling-the-app

  @admin @occ @ui
  Scenario: Disabling the app
    Given the app is enabled
    When the admin disables the app
    Then the app is not enabled
