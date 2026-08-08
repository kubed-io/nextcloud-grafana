# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsmove

Feature: Moving a dashboard file mirrors the move in Grafana
  As a Nextcloud user
  I want moves to mirror correctly in Grafana without ever losing a dashboard's content
  So that relocating a file behaves predictably and safely

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"
    And a folder mapped as "sync" to the Grafana folder "beta"
    And a folder mapped as "link" to the Grafana folder "links"

  # ── 1. within the same mapping: only the name ────────────────────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Moving/renaming within the same mapping keeps it managed
    Given a managed "sync" dashboard file in the "alpha" folder
    When I rename the file within the "alpha" folder
    Then the file stays in "sync" mode under the "alpha" mapping
    And nothing changes in Grafana except the name

  # notes: ../AGENTS.md#moving-into-an-untagged-subfolder-is-local-only-stays-bound-to-the-parent
  @user @in-nextcloud @gesture @ui @todo
  Scenario: Moving into an untagged subfolder is local-only (stays bound to the parent)
    Given an untagged subfolder of the "alpha" folder
    And a managed "sync" dashboard file in the "alpha" folder
    When I move the file into a Nextcloud subfolder of the "alpha" folder
    Then the file stays fully managed in "sync" mode under the "alpha" mapping
    And it keeps its "grafana_uid", "grafana_mapping", and "grafana_folderUid" pointing at the "alpha" folder
    And nothing changes in Grafana — the subfolder is local Nextcloud organization only

  # ── 2. mapped → mapped: a real Grafana folder move, UID preserved ─────────────────

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Moving a sync file from one mapped folder to another moves the dashboard's Grafana folder
    Given a managed "sync" dashboard file in the "alpha" folder
    When I move the file into the "beta" folder
    Then the dashboard's Grafana folder becomes the "beta" folder
    And the dashboard keeps the same "grafana_uid"
    And the file re-stamps "grafana_mapping" to "beta" and "grafana_folderUid" to the "beta" folder
    And the dashboard is not deleted or recreated

  # ── 3. mapped → unmapped: DELETE in Grafana, strip identity (BIN OFF, default) ────

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Moving a sync file out of every mapping deletes it in Grafana and strips the file's identity
    Given the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file in the "alpha" folder
    When I move the file to a folder that is not mapped
    Then the dashboard is deleted in Grafana
    And the file's Grafana identity is stripped of "grafana_uid" and "grafana_mapping"
    And the full dashboard JSON is still in the Nextcloud file
    And the file is now a plain, untracked ".grafana.json"

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Moving that stripped file back into a mapping creates a brand-new dashboard
    Given the Grafana recycle-bin folder is off
    And a plain ".grafana.json" file whose Grafana identity was stripped, outside any mapping
    When I move the file into the "beta" folder
    Then a brand-new dashboard is created in Grafana from the file's JSON body
    And it is created in the "beta" folder with a NEW "grafana_uid"
    And the file's mode becomes "sync" under the "beta" mapping

  # ── 3'. mapped → unmapped WITH the recycle-bin folder on: MOVE to bin, UID kept ───

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: With the recycle-bin folder on, moving a sync file out parks the dashboard in the bin (UID kept)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file in the "alpha" folder
    When I move the file to a folder that is not mapped
    Then the dashboard is moved into the "nextcloud-trash" Grafana folder and not deleted
    And the file KEEPS its "grafana_uid", because it was not a true delete
    And the file's mode becomes "unmapped"
    And the full dashboard JSON is still in the Nextcloud file

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: With the recycle-bin folder on, moving a parked file back into a mapping restores it (same UID)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And an unmapped dashboard file whose Grafana dashboard is parked in the "nextcloud-trash" folder
    When I move the file into the "beta" folder
    Then the dashboard is moved out of "nextcloud-trash" into the "beta" folder
    And the dashboard keeps the same "grafana_uid"
    And the file's mode becomes "sync" under the "beta" mapping

  # ── move a brand-new (untracked) file into a mapping → create-on-land ─────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Moving a brand-new dashboard file into a mapping creates it
    Given a ".grafana.json" file that was never tracked in Grafana
    When I move the file into the "alpha" folder
    Then a matching dashboard is created in Grafana in the "alpha" folder
    And the file's mode becomes "sync" under the "alpha" mapping

  # ── link move-out is refused (a link is a read-only pointer) ─────────────────────

  @user @in-nextcloud @gesture @ui
  Scenario: Moving a link out of its mapping is blocked
    Given a managed "link" dashboard file in the "links" folder
    When I try to move the file to a folder that is not mapped
    Then the move is refused with a message
    And the file stays in the "links" folder

  # ── a link re-homed between two mappings: the pointer follows, nothing moves ─────
  # notes: ../AGENTS.md#moving-a-link-from-one-mapped-folder-to-another-only-re-homes-the-pointer

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Moving a link from one mapped folder to another only re-homes the pointer
    Given a managed "link" dashboard file in the "links" folder
    When I move the file into the "beta" folder
    Then the file's mapping becomes "beta"
    And the dashboard's Grafana folder is unchanged
    And Grafana is not contacted

  # ── the safety rules: never delete on a guess ────────────────────────────────────
  # notes: ../AGENTS.md#a-failed-grafana-delete-on-move-out-never-strips-the-files-identity

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: A failed Grafana delete on move-out never strips the file's identity
    Given the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file in the "alpha" folder
    And the Grafana delete will fail
    When I move the file to a folder that is not mapped
    Then the file still carries its "grafana_uid"
    And the file is still reconcilable with its dashboard

  # notes: ../AGENTS.md#a-move-to-a-destination-the-app-cannot-classify-never-deletes-anything
  @user @in-nextcloud @gesture @ui @todo
  Scenario: A move to a destination the app cannot classify never deletes anything
    Given a managed "sync" dashboard file in the "alpha" folder
    When the file is moved to a path outside the user's file tree
    Then the dashboard is not deleted in Grafana
    And the file keeps its "grafana_uid"

  # ── moves that are none of our business ──────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Moving an untracked file between two unmapped folders changes nothing
    Given an untracked ".grafana.json" file outside any mapping
    When I move the file to another folder that is not mapped
    Then Grafana is not contacted
    And the file is still untracked

  # ══ MOVED IN GRAFANA ═════════════════════════════════════════════════════════════
  # The mirror image of the Nextcloud-side moves above.

  # notes: ../AGENTS.md#a-dashboard-moved-to-another-mapped-folder-in-grafana-relocates-its-mirror
  @grafana @in-grafana @occ @ui @todo
  Scenario: A dashboard moved to another mapped folder in Grafana relocates its mirror
    Given a managed "sync" dashboard file in the "alpha" folder
    When the dashboard is moved to the "beta" folder in Grafana
    And both mappings are pulled
    Then the file is in the Nextcloud folder mapped to "beta"
    And no file for that dashboard remains in the "alpha" folder
    And only one file carries that dashboard's uid

  # Moved somewhere Nextcloud does not mirror. The pull prunes the local file — the
  # dashboard is not deleted, it simply left the mapped set, and the mirror follows.
  @grafana @in-grafana @occ @ui @todo
  Scenario: A dashboard moved to an unmapped Grafana folder loses its mirror
    Given a managed "sync" dashboard file in the "alpha" folder
    When the dashboard is moved to an unmapped Grafana folder
    And the "alpha" mapping is pulled
    Then no file for that dashboard remains in the "alpha" folder
    And the dashboard still exists in Grafana

  # notes: ../AGENTS.md#a-pull-never-relocates-a-file-the-user-filed-into-a-subfolder
  @grafana @in-grafana @occ @ui @todo
  Scenario: A pull never relocates a file the user filed into a subfolder
    Given a managed "sync" dashboard file the user moved into a subfolder of "alpha"
    When the dashboard is renamed in Grafana
    And the "alpha" mapping is pulled
    Then the file is still in that subfolder
    And its name reflects the new title

  # notes: ../AGENTS.md#moving-a-dashboard-into-a-tagged-subfolder-re-parents-it-in-grafana

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Moving a dashboard into a tagged subfolder re-parents it in Grafana
    Given a subfolder of "alpha" carrying the "grafana" tag
    And a managed "sync" dashboard file in the "alpha" folder
    When I move the file into a Nextcloud subfolder of the "alpha" folder
    Then a matching Grafana subfolder is created under the "alpha" folder
    And the dashboard is re-parented into that Grafana subfolder
    And the dashboard keeps the same "grafana_uid"
    And the file's "grafana_folderUid" updates to the new subfolder
    And the file stays under the "alpha" mapping

