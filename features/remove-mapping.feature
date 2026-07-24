# Removing a folder mapping — the admin deletes a mapping from the list (or
# `occ grafana_sync:remove-mapping <id>`). This is NOT the "Purge Nextcloud files"
# button (that keeps the mapping + never touches Grafana — see purge.feature). Removing
# a MAPPING tears down the connection, and the question is: what happens to the files
# and dashboards that were connected through it?
#
# THE CONTRACT (resolved with Dr K) — trash the connected, leave the rest, lose nothing:
#   • Every file ACTIVELY CONNECTED to the mapping (a managed sync/link file whose
#     grafana_mapping is this mapping) is moved to the **Nextcloud trash** — it becomes
#     unmapped and goes to the bin. Because a trash move rides the delete contract
#     (delete.feature), the Grafana side follows automatically:
#       - recycle-bin OFF → the connected dashboard is deleted in Grafana at trash-time
#         and the file's metadata is stripped (restore re-creates with a new uid);
#       - recycle-bin ON  → the connected dashboard is MOVED into the bin folder, uid
#         kept (restore moves it back, same uid).
#   • Files that are NOT connected are LEFT ALONE, untouched: an `unmapped`/`untracked`
#     standalone `.grafana.json` only ever existed in Nextcloud, so removing a mapping
#     it was never part of must never move or delete it — no data loss.
#   • The Nextcloud trash is the safety net: we don't surgically decide what to keep —
#     we trash exactly the connected files, and the trash is fully recoverable. Fully
#     **emptying the trash** then does the permanent clean-up (recycle-bin ON → the
#     matching dashboards are deleted from the Grafana bin; OFF → already gone).
#   • RECONNECTION: if a new mapping to the same Grafana/Nextcloud folder is created
#     later, the trashed files can simply be **restored (untrashed)** to reconnect —
#     cleanest with the recycle bin ON (the dashboards were only parked, so restore
#     re-links the SAME uids); with the bin OFF, a restore is a re-create (new uids).
#
# DESIGN, NOT WIRED: the whole feature is @todo — CI skips it — until the mode/delete
# engine (Course 4 · Slice 2) is cooked. Some legs live-verify the trashbin listener.

@todo
Feature: Removing a folder mapping tears down the connection safely
  As a Nextcloud admin
  I want removing a mapping to clean up only what it connected, via the trash
  So that I never lose data and never leave orphaned dashboards behind

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ── the connected go to trash; the standalone are untouched ──────────────────────

  Scenario: Removing a mapping trashes its connected files and leaves standalone files alone
    Given a managed "sync" dashboard file in the "alpha" folder
    And an unmapped standalone ".grafana.json" file in the "alpha" folder
    When the admin removes the "alpha" mapping
    Then the connected file is moved to the Nextcloud trash
    And the connected file becomes "unmapped"
    And the standalone file is left in place, untouched
    And the "alpha" mapping is no longer configured

  # ── recycle-bin OFF (default): the connected dashboard is deleted in Grafana ──────

  Scenario: Removing a mapping deletes its connected dashboards in Grafana (bin off)
    Given the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file in the "alpha" folder for a dashboard "uid-A"
    When the admin removes the "alpha" mapping
    Then the connected file is in the Nextcloud trash with its metadata stripped
    And dashboard "uid-A" no longer exists in Grafana
    When the admin empties the Nextcloud trash
    Then the trashed file is permanently gone
    And Grafana is not contacted again (the dashboard was already deleted)

  # ── recycle-bin ON: the connected dashboard is parked in the bin (uid kept) ───────

  Scenario: Removing a mapping parks its connected dashboards in the bin (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file in the "alpha" folder for a dashboard "uid-B"
    When the admin removes the "alpha" mapping
    Then the connected file is in the Nextcloud trash
    And dashboard "uid-B" is parked in the "nextcloud-trash" Grafana folder (still exists)
    When the admin empties the Nextcloud trash
    Then dashboard "uid-B" is permanently deleted from the Grafana bin
    And unmanaged dashboards in "nextcloud-trash" are left untouched

  # ── reconnection: re-map the folder, restore from trash, reconnect ───────────────

  Scenario: Re-mapping the folder and restoring from trash reconnects the dashboards (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file in the "alpha" folder for a dashboard "uid-B"
    And the admin removed the "alpha" mapping (the file is trashed, the dashboard parked)
    When the admin adds a "sync" mapping for grafana folder "alpha" in folder "alpha" again
    And the admin restores the trashed file
    Then the file is back in the "alpha" folder, managed "sync" under the new mapping
    And dashboard "uid-B" is moved back out of the bin into the "alpha" Grafana folder
    And it keeps the same uid "uid-B"

  # With the bin OFF the reconnection still works, but the dashboards are re-created
  # (their originals were permanently deleted at trash-time), so the restored files come
  # back under NEW uids — same content, new identity. Pinned for live-verify.
  @todo
  Scenario: Re-mapping and restoring reconnects by re-creating the dashboards (bin off)
    Given the Grafana recycle-bin folder is off
    And a trashed file (once connected to a removed "alpha" mapping, metadata stripped)
    When the admin adds a "sync" mapping for grafana folder "alpha" in folder "alpha" again
    And the admin restores the trashed file
    Then the file is re-created as a dashboard in Grafana under a new uid
    And the file is managed "sync" under the new "alpha" mapping

  # ── a link mapping: removing it trashes the pointer, never deletes the dashboard ──

  Scenario: Removing a link mapping trashes the pointer files but never deletes the dashboards
    Given a folder mapped as "link" to the Grafana folder "reports"
    And a managed "link" file in the "reports" folder for a dashboard "uid-R"
    When the admin removes the "reports" mapping
    Then the link file is moved to the Nextcloud trash
    And dashboard "uid-R" is NOT deleted in Grafana (a link never owned it)
