# Notes, decisions and history for this feature: ../AGENTS.md#foldersdelete

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

  # notes: ../AGENTS.md#trashing-the-mapped-folder-itself-deletes-its-dashboards-but-keeps-the-mapping

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Trashing the mapped folder itself deletes its dashboards but keeps the mapping
    Given the Grafana recycle-bin folder is off
    And the "alpha" folder holds three managed "sync" dashboard files
    When I move the mapped folder to the trash
    Then all three dashboards are deleted in Grafana
    And the mapping still exists in the admin settings

  # notes: ../AGENTS.md#a-pull-after-the-mapped-folder-was-trashed-re-creates-it-empty
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
