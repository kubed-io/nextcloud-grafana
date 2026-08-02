# Deletion semantics — the highest-stakes surface in the app, because Grafana has NO undo.
#
# THE FINDING THAT SHAPES THIS FILE: we proved on live Grafana that the service account
# cannot reach any soft-delete/trash. A `DELETE /api/dashboards/uid/{uid}` is PERMANENT.
# So the master's (n8n) recipe — trash=archive, purge=delete, restore=unarchive — does not
# translate: Grafana has no archive to fall back to.
#
# THE RE-PLATED MODEL — Nextcloud's recycle bin IS the feature we're adding to Grafana.
# Grafana can only ever *delete*; Nextcloud has a real trash you can restore from, so
# deleting a dashboard file is native NC trash on the Nextcloud side + a Grafana action
# that depends on ONE optional setting — the **Grafana recycle-bin folder**.
#
# ── THE BIN IS A SHIM WE BUILT, NOT A FEATURE GRAFANA HAS ────────────────────────
#
# This is the single most important sentence in the file. **Grafana has no trashbin at
# all.** Not a hidden one, not an admin-only one — a service account cannot reach any
# soft-delete, and `DELETE /api/dashboards/uid/{uid}` is final. The n8n sibling has
# archive/unarchive and Penpot has its own bin; Grafana has nothing, so we could not
# port either recipe.
#
# What the "Grafana recycle-bin folder" setting does is EMULATE one, by designating an
# ordinary Grafana folder as a parking space. Everything follows from the word
# *ordinary*:
#
#   - It is **visible in Grafana's own UI.** Anyone browsing folders sees it and the
#     dashboards in it. There is no "deleted items" chrome telling them what it means.
#   - **Anyone can move things out of it** — a colleague spotting a dashboard they still
#     need can simply drag it back. That is a rescue, and it must be respected.
#   - **Anyone can delete things in it,** or delete the folder itself.
#   - **It can hold dashboards Nextcloud never managed.** It is a folder; people put
#     things in folders.
#   - **It outlives this app.** Uninstalling does not empty it.
#
# A real trashbin is privileged storage the owning system controls. This is a shared
# folder with a name we agreed on. Every rule below that looks paranoid is paranoid for
# that reason, and the sharpest one is on the purge: we delete a parked dashboard only
# while it is **still in the bin**. If it has been moved out, emptying a Nextcloud trash
# is not authority to chase it down and destroy it.
#
# WITH THE SHIM OFF — the default — none of that exists and the model is simply: trash
# the file, the dashboard is gone, and the file's JSON in the Nextcloud trash is the only
# copy left. Every scenario below states which world it is in, because they share almost
# no behaviour.
#
# ── RULE: TWO DELETE MODELS, AND THEY AGREE ABOUT NOTHING ────────────────────────
#
#   |                        | bin OFF (default)          | bin ON (opt-in)              |
#   |------------------------|----------------------------|------------------------------|
#   | trash a sync file      | true Grafana DELETE, now   | dashboard MOVED to the bin   |
#   | the file's uid         | STRIPPED (the id is dead)  | KEPT                         |
#   | restore                | create-on-land → NEW uid   | moved back, SAME uid         |
#   | empty the NC trash     | Nextcloud-only no-op       | THE irreversible step        |
#   | point of no return     | the trash gesture          | emptying the trash           |
#
# Read that table as two columns, never as one story. The bin setting does not tune the
# behaviour, it *replaces* it — including which gesture is the one you cannot undo. Every
# scenario below therefore states its model in a `Given`, and the two are never rows of a
# single Scenario Outline (features/README.md explains why that would hide the asymmetry).
#
# THE BIN FOLDER IS NOT A MAPPING. It may hold dashboards Nextcloud does not manage, so no
# operation here ever clears it wholesale — only the specific items being purged.
#
# ── RULE: THE TWO STEPS ARRIVE THROUGH TWO DIFFERENT DOORS ───────────────────────
#
#   - trash-move (soft) → `BeforeNodeDeletedEvent`, a typed event → DeleteToGrafanaListener
#   - purge     (hard)  → the legacy `\OCP\Trashbin` `preDelete` hook → TrashPurgeHook
#
# Nextcloud dispatches NO typed event for a purge. That hook only fires if the app is
# LOADED on a WebDAV request, which needs `<types><filesystem/></types>` in info.xml —
# missing until this PR, so with the bin on, emptying the trash silently left every parked
# dashboard alive in Grafana forever. A perfectly correct hook in a method that never ran.
#
# ── RULE: NEXTCLOUD DRIVES; GRAFANA DOES NOT DRIVE BACK ──────────────────────────
#
# Nextcloud's trash is the user's own undo history, and this app does not reach into it in
# either direction. A dashboard deleted in Grafana does not empty a Nextcloud trash, and
# the pull cannot see into the trash at all (`SyncService` indexes the folder listing, and
# a trashed file is not in the folder). Sometimes that blindness is right and sometimes it
# is a gap — the `@unbuilt` scenarios at the bottom are the same blindness, benign in one
# case and harmful in the other. Worth reading them as a pair.
#
# STATUS: the delete engine IS cooked (Course 4 · Slice 3) — DeleteService +
# DeleteToGrafanaListener + RestoreFromTrashListener + TrashPurgeHook. The rule table is
# unit-tested (failed-delete-never-strips, bin-ON-parks-not-deletes, bin-OFF-restore-
# recreates) and verified live on the pod. The scenarios below are tagged per-scenario:
# live where the WebDAV trash/restore/purge steps now exist, @todo where they do not
# yet, @unbuilt where there is genuinely no code, @blocked where the harness cannot
# reach it. The file-level @todo this used to carry hid that distinction entirely.
#
# THE PURGE SCENARIOS ARE THE REGRESSION TEST FOR THIS PR'S <types> FIX. A purge fires
# no typed Nextcloud event — only the legacy `\OCP\Trashbin` `preDelete` hook, which
# runs only if the app is LOADED on a WebDAV request. Before `<types><filesystem/></types>`
# they would have failed; that they can pass at all is the proof.

Feature: Deleting a dashboard file
  As a Nextcloud user
  I want delete/trash/restore to do the right thing per mode and per recycle-bin setting
  So that removing a file never loses a dashboard's content and never silently desyncs

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ══ BIN OFF (default): the trash gesture IS the delete ═════════════════════════
  # The content is safe in the file (now in the NC trash), so the dashboard goes
  # immediately and the file's uid is stripped — the id is dead and must not be
  # reused. Restore therefore cannot "put it back"; it re-creates.

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Trashing a sync file deletes it in Grafana and strips ALL its metadata (bin off)
    Given the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file
    When I move it to the trash
    Then the dashboard is deleted in Grafana
    And the file is recoverable from the Nextcloud trash with its JSON intact
    # The other half of the rule — that the dead uid is STRIPPED — cannot be observed
    # here: Nextcloud's trashbin DAV endpoint serves no `nc:metadata-*`, so every key
    # reads null for a trashed file whether it was stripped or not. Asserting it here
    # passed vacuously until its bin-on counterpart proved the surface was dead.
    #
    # It is observable one step later, and restore-dashboard.feature asserts it there:
    # a stripped file restores as a NEW dashboard with a NEW uid, a kept one restores
    # to the same dashboard. Identity is proven by what comes back, not by reading a
    # property off a file in the bin.

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Emptying the trash for a bin-off file touches nothing in Grafana
    Given the Grafana recycle-bin folder is off
    And a trashed sync dashboard file whose dashboard is already deleted
    When I purge it from the trash
    Then no Grafana call is made by the purge

  # The safety rule that makes bin-off survivable: the dashboard is deleted FIRST and
  # the uid is stripped only on success. Strip-then-delete would, on a failed call,
  # leave a live dashboard nothing in Nextcloud can still name — unreachable and
  # invisible to every future reconcile. Unit-tested (failed-delete-never-strips).
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: A failed Grafana delete never strips the file's identity (bin off)
    Given the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file
    And the Grafana delete will fail
    When I move it to the trash
    Then the file still carries its "grafana_uid"
    And the file is still reconcilable with its dashboard

  # ══ BIN ON (opt-in): the trash gesture is a MOVE, and purge is the delete ══════
  # The dashboard is parked in the designated folder with its uid intact, so a
  # restore is a move back rather than a re-creation — id and version history
  # survive the whole round trip.

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Trashing a sync file parks its dashboard in the bin, keeping the id (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file
    When I move it to the trash
    Then the dashboard is moved into the "nextcloud-trash" Grafana folder and not deleted
    And the file is recoverable from the Nextcloud trash
    # That the file KEEPS its uid is asserted in restore-dashboard.feature, where it
    # is observable: the parked dashboard comes back with the same id. See the bin-off
    # scenario above for why it cannot be read off the trashed file directly.

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Emptying the trash permanently deletes only the cleared file's dashboard from the bin (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    And another dashboard in "nextcloud-trash" that Nextcloud does not manage
    When I purge the trashed file from the trash
    Then that file's dashboard is permanently deleted from Grafana
    And the unmanaged dashboard in "nextcloud-trash" is left untouched

  # Purging one of several parked dashboards must not sweep the rest. Same rule as
  # above, stated for the case people actually hit — a trash holding several of OUR
  # files, only one of which is being cleared.
  # ── THE RESCUE: the shim is a folder, so people take things back out of it ───────
  # A colleague browsing Grafana sees a dashboard they still need sitting in
  # "nextcloud-trash" and drags it back where it belongs. Weeks later the original user
  # empties their Nextcloud trash. The purge must NOT chase the rescued dashboard down.
  # Deleting by uid alone would destroy a live, in-use dashboard, and Grafana has no undo.

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Purging never deletes a dashboard someone rescued out of the bin
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    And someone moves that dashboard back to its mapped folder in Grafana
    When I purge it from the trash
    Then the dashboard still exists in Grafana
    And it is still in its mapped folder
    And the file is gone from the Nextcloud trash

  # …and the same rule from the other direction: if the dashboard is simply GONE — a
  # Grafana admin deleted it out of the bin by hand — the purge is a local matter. No
  # error, nothing to chase; the Nextcloud file just goes.
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Purging a parked dashboard that has already been deleted in Grafana just clears the file
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    And that parked dashboard has since been permanently deleted in Grafana
    When I purge it from the trash
    Then the purge succeeds
    And the file is gone from the Nextcloud trash

  # Cannot prove it is still parked → do not delete. Leaving a dashboard alive that
  # could have gone is a recoverable leak; deleting one that should have lived is not.
  @user @in-nextcloud @gesture @ui @recycle-bin @blocked
  Scenario: A purge that cannot reach Grafana clears the file without deleting anything
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    And Grafana is unreachable
    When I purge it from the trash
    Then no dashboard is deleted in Grafana
    And the file is gone from the Nextcloud trash

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Purging one parked file leaves the other parked dashboards alone (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And two trashed sync dashboard files whose dashboards are both parked in "nextcloud-trash"
    When I purge only the first from the trash
    Then the first file's dashboard is permanently deleted from Grafana
    And the second file's dashboard is still parked in "nextcloud-trash"
    And the second file is still restorable

  # Bin mode is a promise the admin opted into, so an unusable bin must FAIL LOUD
  # rather than fall back. Silently doing a true delete because the folder was
  # renamed in Grafana would destroy exactly the id preservation they asked for —
  # and Grafana has no undo. RecycleBin::activeFolderUid throws; the delete aborts.
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Bin mode with an unusable bin folder aborts the delete rather than deleting
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And no Grafana folder named "nextcloud-trash" exists
    And a managed "sync" dashboard file
    When I move it to the trash
    Then the delete is aborted and the file stays in Nextcloud
    And the dashboard still exists in its mapped Grafana folder

  # The shim folder is shared space. Nothing in this app may ever treat "empty the
  # Nextcloud trash" as "empty the bin folder" — it holds dashboards we never managed,
  # and dashboards belonging to other users' trashes.
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: A purge never clears the bin folder wholesale
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    And two dashboards in "nextcloud-trash" that Nextcloud never managed
    When I purge it from the trash
    Then both unmanaged dashboards are still in "nextcloud-trash"

  # ══ MODE: what a link and an untracked file are owed ═══════════════════════════
  # A link is a pointer, so trashing it severs the tie and nothing else. Neither
  # model applies, which is why these carry no @recycle-bin tag.

  @user @in-nextcloud @gesture @ui
  Scenario: Trashing a link never deletes the dashboard
    Given a folder mapped as "link" to the Grafana folder "links"
    And a managed "link" dashboard file in that folder
    When I move it to the trash
    Then the dashboard in Grafana is not deleted
    And the link is recoverable from the Nextcloud trash

  @user @in-nextcloud @gesture @ui
  Scenario: Purging a link never deletes the dashboard
    Given a folder mapped as "link" to the Grafana folder "links"
    And a trashed "link" dashboard file
    When I purge it from the trash
    Then the dashboard in Grafana is not deleted

  @user @in-nextcloud @gesture @ui
  Scenario: Deleting an untracked dashboard file touches nothing in Grafana
    Given an untracked ".grafana.json" file
    When I delete it
    Then Grafana is not contacted

  # An ignored file is one the control plane excluded (see reserved-tags.feature).
  # It is not ours to delete on either side, in either bin model.
  @user @in-nextcloud @gesture @ui @todo
  Scenario: Trashing an ignored file touches nothing in Grafana
    Given a managed "ignored" dashboard file
    When I move it to the trash
    Then Grafana is not contacted
    And the dashboard still exists in Grafana

  # ══ RESTORE EDGE CASES: the world moved while the file sat in the trash ════════

  # ══ CHANGES MADE ON THE GRAFANA SIDE ═══════════════════════════════════════════
  # Everything above starts in Nextcloud. These start in Grafana, and they are where
  # the pull's blindness to the Nextcloud trash actually bites.

  # This one the app gets RIGHT — but for a weak reason, which is why it is worth
  # pinning. Nothing DECIDES to leave the file alone: the pull simply cannot see
  # into the trash. A trash-aware reconcile must keep this behaviour deliberately
  # rather than lose it, because Nextcloud's trash is the user's undo history and a
  # Grafana-side delete is not permission to empty it.
  @grafana @in-grafana @ui @occ @todo
  Scenario: Deleting a dashboard in Grafana leaves an already-trashed file where it is
    Given a trashed sync dashboard file
    And its dashboard has been permanently deleted in Grafana
    When the "alpha" mapping is pulled
    Then the file is still in the Nextcloud trash
    And nothing is restored or pruned because of it

  # ══ THE GESTURE THAT SKIPS THE TRASH ═══════════════════════════════════════════

  # With the trashbin app disabled (or an `X-NC-Skip-Trashbin` header) only the soft
  # step ever fires — there is no trash for a purge to empty. Under bin OFF that is
  # harmless: the soft step already IS the true delete, so the outcome is correct.
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: A trash-bypassed delete still deletes the dashboard (bin off)
    Given the Nextcloud trashbin is disabled
    And the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file
    When I delete it
    Then the dashboard is deleted in Grafana

  # …and under bin ON it is a LEAK, which is the point of writing both down together.
  # The soft step parks the dashboard in the bin folder expecting a later purge to
  # finish the job, but no trash entry was ever created, so no purge can ever fire.
  # The dashboard sits in the bin forever with no file anywhere naming it.
  #
  # @unbuilt, not @todo: nothing in the code notices. The fix has to be a decision
  # first — either the soft step detects the bypass and does a true delete (losing
  # the id preservation the admin opted into, with no undo), or bin mode declines to
  # park when there will be no trash entry. Not a bug to fix quietly.
  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: A trash-bypassed delete leaves the dashboard parked forever (bin on)
    Given the Nextcloud trashbin is disabled
    And the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file
    When I delete it
    Then the dashboard is not left orphaned in "nextcloud-trash"

  # ══ FAILURE HANDLING ═══════════════════════════════════════════════════════════

  # @blocked, not @todo: the code exists (the exception propagates and aborts the NC
  # delete), but this harness has no way to make Grafana unreachable for the
  # duration of one request — that is the missing capability, and naming it is what
  # keeps this out of the @todo work queue. The unit suite covers the rule against a
  # mocked GrafanaClient (testSoftDeleteBinOffFailedDeleteNeverStrips).
  @user @in-nextcloud @gesture @ui @blocked
  Scenario: The Grafana delete is aborted if Grafana is unreachable
    Given a managed "sync" dashboard file
    And Grafana is unreachable
    When I move it to the trash
    Then the delete is aborted and the file stays recoverable in Nextcloud
