# Deleting a FOLDER — the folder half of delete-dashboard.feature, and the highest
# blast radius in the app.
#
# ── WHY THIS IS NOT JUST "DELETE, N TIMES" ───────────────────────────────────────
#
# Deleting one dashboard file is a decision the user makes about one dashboard.
# Deleting a folder is one gesture that reaches every dashboard inside it — and
# under the DEFAULT setting (recycle bin OFF), every one of those is a **permanent
# Grafana delete**, because Grafana has no undo. A user dragging a folder to the
# trash is unlikely to have priced that in.
#
# The Nextcloud side stays honest either way: the files land in the trash with their
# JSON intact, so nothing is *lost*. What is gone is the dashboards' uids and their
# Grafana version history, for as many dashboards as the folder held.
#
#   | folder holds N sync files | bin OFF                    | bin ON                     |
#   |---------------------------|----------------------------|----------------------------|
#   | trash the folder          | N permanent Grafana deletes | N dashboards parked        |
#   | restore the folder        | N NEW uids (create-on-land) | N restored, uids preserved |
#   | empty the trash           | nothing left to do          | N permanent deletes        |
#
# ── THE ORDERING QUESTION NOBODY HAS ANSWERED ────────────────────────────────────
#
# Nextcloud fires `BeforeNodeDeletedEvent` per node, so the app sees N file deletes
# rather than one folder delete. That mostly works — each file takes the normal path
# — but it means there is no transaction and no summary: a folder delete that fails
# on dashboard 7 of 12 leaves five dashboards deleted, one delete aborted, and six
# untouched, with nothing telling the user which is which. Every individual file
# behaved correctly and the aggregate is still a mess.
#
# ── STATUS ───────────────────────────────────────────────────────────────────────
#
# The per-file path is built (DeleteService, unit-tested), so the scenarios that are
# only "does the per-file rule hold when a folder is the gesture" are @todo. The
# aggregate behaviour — confirmation, partial-failure reporting, restoring a folder
# as a unit — is @unbuilt: nothing in `lib/` treats a folder delete as one event.

Feature: Deleting a folder
  As a Nextcloud user
  I want deleting a folder to be as safe and as legible as deleting one dashboard
  So that one gesture cannot quietly destroy many dashboards

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ── a subfolder holding dashboards ───────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Trashing a subfolder of a mapping deletes every dashboard it held (bin off)
    Given the Grafana recycle-bin folder is off
    And a subfolder of "alpha" holding three managed "sync" dashboard files
    When I move the subfolder to the trash
    Then all three dashboards are deleted in Grafana
    And all three files are recoverable from the Nextcloud trash with their JSON intact
    And none of the trashed files carries a "grafana_uid"

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Trashing a subfolder parks every dashboard it held (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a subfolder of "alpha" holding three managed "sync" dashboard files
    When I move the subfolder to the trash
    Then all three dashboards are in the "nextcloud-trash" Grafana folder
    And all three files KEEP their "grafana_uid"

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restoring a trashed subfolder brings its dashboards back with the same ids (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed subfolder whose three dashboards are parked in "nextcloud-trash"
    When I restore the subfolder from the trash
    Then all three dashboards are back in the "alpha" Grafana folder
    And each keeps the uid it had before

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restoring a trashed subfolder re-creates its dashboards with new ids (bin off)
    Given the Grafana recycle-bin folder is off
    And a trashed subfolder holding three dashboard files whose dashboards were deleted
    When I restore the subfolder from the trash
    Then three dashboards exist in Grafana holding the files' content
    And each has a new uid

  # ── the mapped folder itself ─────────────────────────────────────────────────────
  # Deleting the folder a mapping points at is the widest gesture available to a
  # non-admin. The mapping survives — it is configuration, not a file — so the next
  # pull re-creates the folder and everything in it. Whether the dashboards should
  # have been deleted in the meantime is the whole question.

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Trashing the mapped folder itself deletes its dashboards but keeps the mapping
    Given the Grafana recycle-bin folder is off
    And the "alpha" folder holds three managed "sync" dashboard files
    When I move the mapped folder to the trash
    Then all three dashboards are deleted in Grafana
    And the mapping still exists in the admin settings

  # The consequence, stated separately because it is the part that surprises people:
  # the mapping outlives the folder, so a pull rebuilds an empty folder rather than
  # restoring what was there.
  @admin @in-grafana @occ @ui @todo
  Scenario: A pull after the mapped folder was trashed re-creates it empty
    Given the "alpha" folder has been moved to the trash with its dashboards deleted
    When the "alpha" mapping is pulled
    Then the mapped folder exists again in Nextcloud
    And it holds no dashboard files

  # ── folders that hold nothing of ours ────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Trashing a folder of untracked files touches nothing in Grafana
    Given a folder outside every mapping holding untracked ".grafana.json" files
    When I move the folder to the trash
    Then Grafana is not contacted

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Trashing a subfolder of a link mapping never deletes any dashboard
    Given a folder mapped as "link" to the Grafana folder "links"
    And a subfolder of it holding three managed "link" dashboard files
    When I move the subfolder to the trash
    Then no dashboard is deleted in Grafana

  # ── the aggregate behaviour: one gesture, many outcomes ──────────────────────────

  # The gap that matters. Each file's delete is correct in isolation; the user is
  # never told what the gesture cost in total, and a mid-way failure is invisible.
  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: A folder delete that fails part-way reports what did and did not happen
    Given the Grafana recycle-bin folder is off
    And a subfolder of "alpha" holding twelve managed "sync" dashboard files
    And the Grafana delete will fail for one of them
    When I move the subfolder to the trash
    Then the user is told which dashboards were deleted and which were not
    And the files whose dashboards survived still carry their uids

  # Deleting one dashboard is a small decision; deleting forty is not, and under the
  # default setting it is irreversible. The app knows the count before it acts.
  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Trashing a folder of many dashboards warns before permanently deleting them
    Given the Grafana recycle-bin folder is off
    And a subfolder of "alpha" holding forty managed "sync" dashboard files
    When I move the subfolder to the trash
    Then I am warned that forty dashboards will be permanently deleted in Grafana

  # ── the mirrored Grafana subfolder ───────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Deleting a mirrored subfolder removes the empty Grafana subfolder too
    Given a mirrored Grafana folder under the "alpha" folder, holding a dashboard
    When I move the subfolder to the trash and empty the trash
    Then the Grafana subfolder is removed once it holds no dashboards

  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: Deleting a mirrored subfolder in Grafana removes the Nextcloud subfolder
    Given a mirrored Grafana folder "Team A" under the "alpha" folder, holding a dashboard
    When the Grafana subfolder is deleted
    And the "alpha" mapping is pulled
    Then the Nextcloud subfolder is gone
    And its dashboard file has been pruned
