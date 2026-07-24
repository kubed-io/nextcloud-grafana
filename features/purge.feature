# Purge — an admin-only button beside "Sync from/to Grafana" and "Test connection"
# (also `occ grafana_sync:purge`) that removes the dashboard files THIS APP created and
# nothing else. It deletes every **restorable** managed file — `sync` and `link`,
# whose dashboard is still live + tagged in Grafana — across all mappings, and:
#   - never contacts Grafana (the delete runs under SyncGuard so it can't mirror out);
#   - leaves the mappings configured;
#   - leaves the custom mimetype registration alone (that is uninstall's job).
#
# It deliberately KEEPS files a "Sync from Grafana" could not bring back, so purge can
# never cost you data: `unmapped` files (moved out of a mapping — a standalone copy /
# template you kept, whose full JSON lives in the file), `ignored` files, and untracked
# `.grafana.json` (a plain document the app never created).
#
# Driven headlessly through `occ grafana_sync:purge` ({@see \OCA\GrafanaSync\Command\Purge}).
# Two intended flows: purge → "Sync from Grafana" (everything reappears), and
# purge → uninstall (Nextcloud looks like the app was never there).

@todo
Feature: Purge the app's restorable files from Nextcloud
  As a Nextcloud admin
  I want a button that removes the dashboard files this app created
  So that I can reset the Nextcloud side without ever touching Grafana or losing standalone files

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  Scenario: Purge deletes the synced file but leaves its dashboard in Grafana and the mapping intact
    Given a managed "sync" dashboard file in the "alpha" folder
    When the admin purges the Nextcloud files
    Then no managed dashboard files remain in the "alpha" folder
    And the dashboard still exists in Grafana
    And the "alpha" mapping is still configured

  Scenario: Purge keeps an unmapped file — a standalone copy is never lost
    Given an unmapped dashboard file that still carries its "grafana_uid"
    And I remember the unmapped file
    And a managed "sync" dashboard file in the "alpha" folder
    When the admin purges the Nextcloud files
    Then no managed dashboard files remain in the "alpha" folder
    And the remembered file is left in place

  Scenario: Sync from Grafana brings the file back after a purge
    Given a managed "sync" dashboard file in the "alpha" folder
    And the admin purges the Nextcloud files
    When the admin clicks "Sync from Grafana" for the "alpha" mapping
    Then the dashboard appears again as a file in the "alpha" folder

  # The in-folder mode-check (ignored stays put) and the untracked-file case are
  # covered by the SyncServiceTest unit test; their integration arrange (tagging
  # Grafana:ignore / a never-tracked file) is left @todo to keep this suite lean.
  @todo
  Scenario: Purge keeps an ignored file
    Given a managed "ignored" dashboard file in the "alpha" folder
    When the admin purges the Nextcloud files
    Then that ignored file is left in place
