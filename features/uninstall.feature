# Notes, decisions and history for this feature: AGENTS.md#uninstall

Feature: Uninstall reverts the system and reinstall reconnects the data
  As a Nextcloud admin
  I want removing the app to leave Nextcloud clean and reinstalling to just resync
  So that uninstalling is safe and never costs me data or creates duplicates

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # notes: AGENTS.md#removing-the-app-reverts-the-custom-mimetype-registration
  @admin @occ @blocked
  Scenario: Removing the app reverts the custom mimetype registration
    Given the app registered the "application/grafana+json" mimetype on install
    When the app is removed
    Then the mimetype mapping for "Grafana.json" is gone from the Nextcloud config
    And the Grafana icon is removed from the core filetype icons
    And a ".grafana.json" file resolves to "application/json" again

  # ── data is orphaned, never deleted ───────────────────────────────────────────
  @admin @occ @ui @todo
  Scenario: Disabling the app leaves the dashboard files (and their identity) in place
    Given the "alpha" folder has managed sync dashboard files
    When the admin disables the app
    Then the ".grafana.json" files are still in the folder
    And each file still carries its "grafana_uid" metadata

  # ── reinstall reconnects with no duplicates (the headline) ────────────────────
  @admin @occ @ui @todo
  Scenario: Re-enabling and syncing reconciles the existing files without duplicates
    Given the "alpha" folder has managed sync dashboard files
    And the admin disables and then re-enables the app
    When the admin clicks "Sync from Grafana" for the "alpha" mapping
    Then each existing file is updated in place, matched by its "grafana_uid"
    And no file gains a " (2)" collision-suffixed duplicate
