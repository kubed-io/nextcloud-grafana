# Uninstall lifecycle — what happens to the SYSTEM and to the user's DATA when the
# app is removed, and that a reinstall reconnects cleanly.
#
#   - SYSTEM: removing the app runs the <uninstall> repair step (UnregisterMimetype),
#     which REVERTS the custom-mimetype registration the install wrote into the
#     Nextcloud core tree (config/mimetype*.json, core/img/filetypes/Grafana.svg,
#     core/js/mimetypelist.js) and re-stamps the .grafana.json filecache rows back to
#     application/json. The store's clean-uninstall rule is about this shared state.
#   - DATA: the app ORPHANS the user's data — it never deletes the .grafana.json files,
#     never clears their Files-Metadata, never deletes Team Folders, never touches
#     Grafana. A sync folder is a full backup, so deleting it would be data loss. To wipe
#     the Nextcloud side deliberately, an admin uses Purge first (see purge.feature).
#
# Because the files keep their grafana_uid, a reinstall + pull RECONCILES them in
# place (matched by uid, never duplicated) — the reconnect is free, by design.
#
# The <uninstall> system leg needs a full app remove on a live pod (CI can't drive
# it), so it stays @todo; the data-orphan + reinstall-reconnect legs are provable via
# disable/re-enable + a pull, which exercises the same metadata-keyed reconcile.

# Spec-first / @todo: the SYSTEM leg needs a real app-remove on a live pod (the CI
# harness can only disable/enable, not remove+reinstall), so it stays manual. The
  # DATA promise — reinstall reconciles existing files in place by uid with NO
  # duplicates — is already proven LIVE by reconcile.feature ("existing files are
  # updated in place — matched by dashboard uid, never duplicated"); a disable/enable
# changes nothing about that reconcile, so re-proving it here would be redundant.
Feature: Uninstall reverts the system and reinstall reconnects the data
  As a Nextcloud admin
  I want removing the app to leave Nextcloud clean and reinstalling to just resync
  So that uninstalling is safe and never costs me data or creates duplicates

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ── system cleanup ───────────────────────────────────────────────────────────
  # @blocked, and the missing capability is named: this harness can only DISABLE and
  # ENABLE the app, never remove and reinstall it, so the uninstall repair step
  # (UnregisterMimetype) is unreachable from CI. The two scenarios below stay live
  # because disable/enable is exactly what they need.
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
