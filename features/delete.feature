# Deletion semantics — the highest-stakes surface in the app, because Grafana has NO undo.
#
# THE FINDING THAT SHAPES THIS FILE: we proved on live Grafana that the service account
# cannot reach any soft-delete/trash. A `DELETE /api/dashboards/uid/{uid}` is PERMANENT.
# So the master's (n8n) recipe — trash=archive, purge=delete, restore=unarchive — does not
# translate: Grafana has no archive to fall back to.
#
# THE RE-PLATED MODEL (resolved with Dr K) — Nextcloud's recycle bin IS the feature we're
# adding to Grafana. Grafana can only ever *delete*; Nextcloud has a real trash you can
# restore from, so deleting a dashboard file is native NC trash on the Nextcloud side +
# a Grafana action that depends on ONE optional setting — the **Grafana recycle-bin folder**.
# Delete is really just "move-out, but the destination is the trash" (see move.feature):
# either way Grafana gets the same treatment.
#
#   • BIN OFF (default, aggressive): trashing a sync file is a **true Grafana delete** right
#     then — the content is safe in the NC file (now in the trash), so we delete the
#     dashboard and STRIP the file's grafana_uid (the id is dead). RESTORE from the NC trash
#     lands the plain file back in its mapped folder → **create-on-land** re-creates the
#     dashboard with a **NEW uid**. Emptying the NC trash is then a Nextcloud-only act — the
#     Grafana dashboard is already gone.
#
#   • BIN ON (opt-in, id-preserving): trashing a sync file **moves its Grafana dashboard into
#     the designated bin folder** (NOT a true delete), so the file KEEPS its uid. RESTORE
#     moves the dashboard back to its folder, **same uid**. The ONE irreversible moment is
#     **emptying the NC trash**: we then permanently delete from the Grafana bin — but ONLY
#     the items being cleared (never a wholesale bin-clear; the bin may hold things Nextcloud
#     does not manage).
#
# A `link` file is a pointer, so trashing it only severs the tie — the dashboard is never
# deleted. An untracked `.grafana.json` (no grafana_uid) is never our business.
#
# DESIGN, NOT WIRED: the whole feature is @todo — CI skips it — until the delete engine is
# cooked. Some legs depend on the trashbin-DAV listener firing BeforeNodeDeletedEvent /
# NodeRestoredEvent on the pod, to be proven live when implemented.

@todo
Feature: Deleting a dashboard file
  As a Nextcloud user
  I want delete/trash/restore to do the right thing per mode and per recycle-bin setting
  So that removing a file never loses a dashboard's content and never silently desyncs

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ── BIN OFF (default): trash = true Grafana delete + strip; restore = re-create ──

  Scenario: Trashing a sync file deletes it in Grafana and strips ALL its metadata (bin off)
    Given the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file
    When I move it to the trash
    Then the dashboard is deleted in Grafana
    And the trashed file carries NO Grafana metadata (uid, mode, mapping, version, hash all cleared)
    And the file is recoverable from the Nextcloud trash (its JSON is intact)

  Scenario: Restoring a sync file re-creates the dashboard with a new id (bin off)
    Given the Grafana recycle-bin folder is off
    And a trashed sync dashboard file (its Grafana dashboard already deleted)
    When I restore it from the trash
    Then a dashboard is re-created in Grafana from the file's JSON
    And it has a NEW "grafana_uid"
    And the file's mode becomes "sync" under its original mapping

  # The full round-trip the user asked us to pin + live-verify (bin off): the content
  # never leaves Nextcloud, the Grafana dashboard blips out on trash and comes back fresh
  # on restore. No data loss; only the Grafana id/history is not preserved.
  Scenario: Round-trip — delete then restore re-creates the dashboard (bin off)
    Given the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file for a dashboard "uid-A"
    When I move it to the trash
    Then dashboard "uid-A" no longer exists in Grafana
    And the trashed file carries no Grafana metadata
    When I restore it from the trash
    Then a dashboard exists in Grafana again with the same content
    And its uid is new (not "uid-A")
    And the file is managed "sync" again

  Scenario: Emptying the trash for a bin-off file touches nothing in Grafana
    Given the Grafana recycle-bin folder is off
    And a trashed sync dashboard file (its Grafana dashboard already deleted)
    When I purge it from the trash
    Then Grafana is not contacted (the dashboard was already deleted at trash time)

  # ── BIN ON (opt-in): trash = park in bin (uid kept); empty trash = the real delete ──

  Scenario: Trashing a sync file parks its dashboard in the bin, keeping the id (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file
    When I move it to the trash
    Then the dashboard is moved into the "nextcloud-trash" Grafana folder (not deleted)
    And the trashed file KEEPS its "grafana_uid"
    And the file is recoverable from the Nextcloud trash

  Scenario: Restoring a parked file moves its dashboard back with the same id (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    When I restore it from the trash
    Then the dashboard is moved out of "nextcloud-trash" back into its mapped folder
    And the dashboard keeps the same "grafana_uid"

  # The full round-trip the user asked us to pin + live-verify (bin on): the move happens
  # in BOTH systems — the file to the NC trash, the dashboard to the Grafana bin folder —
  # and both come back together on restore, id preserved.
  Scenario: Round-trip — delete moves to the bin in both systems, restore moves both back (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file for a dashboard "uid-B"
    When I move it to the trash
    Then the file is in the Nextcloud trash
    And dashboard "uid-B" is in the "nextcloud-trash" Grafana folder (still exists)
    When I restore it from the trash
    Then the file is back in its mapped folder
    And dashboard "uid-B" is back in its mapped Grafana folder with the same uid

  Scenario: Emptying the trash permanently deletes only the cleared file's dashboard from the bin (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    And another dashboard in "nextcloud-trash" that Nextcloud does not manage
    When I purge the trashed file from the trash
    Then that file's dashboard is permanently deleted from Grafana
    And the unmanaged dashboard in "nextcloud-trash" is left untouched

  # ── link: trashing only severs the tie; the dashboard is never deleted ───────────

  Scenario: Trashing a link never deletes the dashboard
    Given a managed "link" dashboard file
    When I move it to the trash
    Then the dashboard in Grafana is not deleted
    And the link is recoverable from the Nextcloud trash

  # ── untracked: never our business ────────────────────────────────────────────────

  Scenario: Deleting an untracked dashboard file touches nothing in Grafana
    Given an untracked ".grafana.json" file
    When I delete it
    Then Grafana is not contacted

  # ── the irreversible step must never half-happen ─────────────────────────────────
  # Whichever step issues the real Grafana DELETE (trash when bin-off, empty-trash when
  # bin-on), if Grafana can't confirm it we abort so the file stays recoverable. Best
  # covered by a unit test against a mocked GrafanaClient. @todo.
  @todo
  Scenario: The Grafana delete is aborted if Grafana is unreachable
    Given a managed "sync" dashboard file about to be deleted in Grafana
    And Grafana is unreachable
    When the delete step runs
    Then it is aborted and the file stays recoverable in Nextcloud
