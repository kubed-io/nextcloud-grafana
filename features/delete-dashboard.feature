# Notes, decisions and history for this feature: AGENTS.md#delete-dashboard

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
    # notes: AGENTS.md#emptying-the-trash-for-a-bin-off-file-touches-nothing-in-grafana

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

  # notes: AGENTS.md#purging-never-deletes-a-dashboard-someone-rescued-out-of-the-bin

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

  # notes: AGENTS.md#deleting-a-dashboard-in-grafana-leaves-an-already-trashed-file-where-it-is
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

  # notes: AGENTS.md#a-trash-bypassed-delete-leaves-the-dashboard-parked-forever-bin-on
  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: A trash-bypassed delete leaves the dashboard parked forever (bin on)
    Given the Nextcloud trashbin is disabled
    And the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file
    When I delete it
    Then the dashboard is not left orphaned in "nextcloud-trash"

  # ══ FAILURE HANDLING ═══════════════════════════════════════════════════════════

  # notes: AGENTS.md#the-grafana-delete-is-aborted-if-grafana-is-unreachable
  @user @in-nextcloud @gesture @ui @blocked
  Scenario: The Grafana delete is aborted if Grafana is unreachable
    Given a managed "sync" dashboard file
    And Grafana is unreachable
    When I move it to the trash
    Then the delete is aborted and the file stays recoverable in Nextcloud
