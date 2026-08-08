# Notes, decisions and history for this feature: ../AGENTS.md#mappingdelete

Feature: Removing a folder mapping tears down the connection safely
  As a Nextcloud admin
  I want removing a mapping to clean up only what it connected, via the trash
  So that I never lose data and never leave orphaned dashboards behind

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ── the connected go to trash; the standalone are untouched ──────────────────────

  @admin @in-nextcloud @occ @ui @todo
  Scenario: Removing a mapping trashes its connected files and leaves standalone files alone
    Given a managed "sync" dashboard file in the "alpha" folder
    And an unmapped standalone ".grafana.json" file in the "alpha" folder
    When the admin removes the "alpha" mapping
    Then the connected file is moved to the Nextcloud trash
    And the connected file becomes "unmapped"
    And the standalone file is left in place, untouched
    And the "alpha" mapping is no longer configured

  # ── recycle-bin OFF (default): the connected dashboard is deleted in Grafana ──────

  @admin @in-nextcloud @occ @ui @recycle-bin @todo
  Scenario: Removing a mapping deletes its connected dashboards in Grafana (bin off)
    Given the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file in the "alpha" folder for a dashboard "uid-A"
    When the admin removes the "alpha" mapping
    Then the connected file is in the Nextcloud trash with its metadata stripped
    And dashboard "uid-A" no longer exists in Grafana
    When the admin empties the Nextcloud trash
    Then the trashed file is permanently gone
    And Grafana is not contacted again

  # ── recycle-bin ON: the connected dashboard is parked in the bin (uid kept) ───────

  @admin @in-nextcloud @occ @ui @recycle-bin @todo
  Scenario: Removing a mapping parks its connected dashboards in the bin (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file in the "alpha" folder for a dashboard "uid-B"
    When the admin removes the "alpha" mapping
    Then the connected file is in the Nextcloud trash
    And dashboard "uid-B" is parked in the "nextcloud-trash" Grafana folder and still exists
    When the admin empties the Nextcloud trash
    Then dashboard "uid-B" is permanently deleted from the Grafana bin
    And unmanaged dashboards in "nextcloud-trash" are left untouched

  # ── reconnection: re-map the folder, restore from trash, reconnect ───────────────

  @admin @in-nextcloud @occ @ui @recycle-bin @todo
  Scenario: Re-mapping the folder and restoring from trash reconnects the dashboards (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file in the "alpha" folder for a dashboard "uid-B"
    And the admin removed the "alpha" mapping, so the file is trashed and the dashboard parked
    When the admin adds a "sync" mapping for grafana folder "alpha" in folder "alpha" again
    And the admin restores the trashed file
    Then the file is back in the "alpha" folder, managed "sync" under the new mapping
    And dashboard "uid-B" is moved back out of the bin into the "alpha" Grafana folder
    And it keeps the same uid "uid-B"

  # notes: ../AGENTS.md#re-mapping-and-restoring-reconnects-by-re-creating-the-dashboards-bin-off
  @admin @in-nextcloud @occ @ui @recycle-bin @todo
  Scenario: Re-mapping and restoring reconnects by re-creating the dashboards (bin off)
    Given the Grafana recycle-bin folder is off
    And a trashed file once connected to a removed "alpha" mapping, its metadata stripped
    When the admin adds a "sync" mapping for grafana folder "alpha" in folder "alpha" again
    And the admin restores the trashed file
    Then the file is re-created as a dashboard in Grafana under a new uid
    And the file is managed "sync" under the new "alpha" mapping

  # ── a link mapping: removing it trashes the pointer, never deletes the dashboard ──

  @admin @in-nextcloud @occ @ui @todo
  Scenario: Removing a link mapping trashes the pointer files but never deletes the dashboards
    Given a folder mapped as "link" to the Grafana folder "reports"
    And a managed "link" file in the "reports" folder for a dashboard "uid-R"
    When the admin removes the "reports" mapping
    Then the link file is moved to the Nextcloud trash
    And dashboard "uid-R" is NOT deleted in Grafana
