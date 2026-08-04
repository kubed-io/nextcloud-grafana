# Notes, decisions and history for this feature: AGENTS.md#purge

Feature: Purge the app's restorable files from Nextcloud
  As a Nextcloud admin
  I want a button that removes the dashboard files this app created
  So that I can reset the Nextcloud side without ever touching Grafana or losing standalone files

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  @admin @in-nextcloud @occ @ui @todo
  Scenario: Purge deletes the synced file but leaves its dashboard in Grafana and the mapping intact
    Given a managed "sync" dashboard file in the "alpha" folder
    When the admin purges the Nextcloud files
    Then no managed dashboard files remain in the "alpha" folder
    And the dashboard still exists in Grafana
    And the "alpha" mapping is still configured

  @admin @in-nextcloud @occ @ui @todo
  Scenario: Purge keeps an unmapped file — a standalone copy is never lost
    Given an unmapped dashboard file that still carries its "grafana_uid"
    And I remember the unmapped file
    And a managed "sync" dashboard file in the "alpha" folder
    When the admin purges the Nextcloud files
    Then no managed dashboard files remain in the "alpha" folder
    And the remembered file is left in place

  @admin @in-grafana @occ @ui @todo
  Scenario: Sync from Grafana brings the file back after a purge
    Given a managed "sync" dashboard file in the "alpha" folder
    And the admin purges the Nextcloud files
    When the admin clicks "Sync from Grafana" for the "alpha" mapping
    Then the dashboard appears again as a file in the "alpha" folder

  # The in-folder mode-check (ignored stays put) and the untracked-file case are
  # covered by the SyncServiceTest unit test; their integration arrange (tagging
  # grafana:ignore / a never-tracked file) is left @todo to keep this suite lean.
  @admin @in-nextcloud @occ @ui @unbuilt
  Scenario: Purge keeps an ignored file
    Given a managed "ignored" dashboard file in the "alpha" folder
    When the admin purges the Nextcloud files
    Then that ignored file is left in place
