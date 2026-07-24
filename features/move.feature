# How the app reacts to every move a Nextcloud user can make on a dashboard file.
# The stable thread is the dashboard UID **plus the full JSON we hold in the file**.
# (COPY is the opposite — always a new instance; see copy.feature.)
#
# THE CORE NUANCE (resolved with Dr K) — MOVE and DELETE are NOT the same, and where
# a file moves decides everything. Grafana has NO soft-delete/archive (proven live: a
# DELETE is permanent), and — unlike n8n — we CANNOT keep an id "parked" for free,
# because there is nothing to un-archive. So the id-preservation story depends on where
# the file lands and on ONE optional setting:
#
#   1. MOVE WITHIN THE SAME MAPPING (rename) — nothing in Grafana but the name.
#
#   2. MOVE FROM ONE MAPPED FOLDER TO ANOTHER — a genuine Grafana **folder move**: the
#      dashboard's folderUid updates to the destination mapping's folder, the **UID is
#      always preserved** (both sides are real Grafana folders — no delete involved),
#      and the file re-stamps grafana_mapping + grafana_folderUid. Independent of the
#      recycle-bin setting below.
#
#   3. MOVE OUT OF EVERY MAPPED FOLDER (to an unmapped location) — this is where the two
#      strategies diverge, selected by the optional **Grafana recycle-bin folder**:
#        • BIN OFF (default, aggressive): the content is already safe in the Nextcloud
#          file, so we **DELETE the dashboard in Grafana** and **strip the file's Grafana
#          identity** (uid/mapping/folderUid/version/hash). The file becomes a plain,
#          untracked .grafana.json that still holds the full JSON. Moving it back into a
#          mapping is then just **create-on-land** — a brand-new dashboard, same content,
#          a **NEW uid** (the old one is gone forever). "It just works", id not preserved.
#        • BIN ON: we **MOVE the Grafana dashboard into the designated recycle-bin folder**
#          (uid **preserved**) — the analogue of n8n's archive, done with a real folder.
#          Moving the file back into a mapping **moves the dashboard back** out of the bin
#          to the destination folder, **same uid**. (See delete.feature — trash uses the
#          exact same bin machinery.)
#
# THE ID-STRIP RULE (precise): we strip the file's grafana_uid **only when we are about
# to do a TRUE delete in Grafana while the file survives in Nextcloud** — because the
# dashboard is gone, so its uid is dead. With the recycle-bin folder ON, a "delete/move-out"
# is a **move into the bin, not a true delete**, so the dashboard still exists and the file
# **keeps its uid** (it becomes an `unmapped` file that can restore to the SAME dashboard —
# the n8n-style parked state, which Grafana can only offer via the bin).
#
# THE SAFETY RULE (never lose data): a sync file always holds the full dashboard JSON, so
# the content is safe in Nextcloud BEFORE we touch Grafana. We only ever DELETE from
# Grafana once we have confirmed the file holds what it needs to rebuild.
#
# STATUS: the move engine IS cooked (Course 4 · Slice 2b) — MotionService + MoveGuardListener,
# unit-tested for the invariants (uid kept on a re-parent, a failed delete never strips, a link
# move-out refused) and verified live on the pod (create → re-parent uid-kept → move-out delete
# → move-back new-uid, all confirmed against Grafana's API).
#   SCOPE — same-storage moves only: Nextcloud fires NodeRenamedEvent for a move within one
#   storage (regular folder ↔ regular folder, rename, subfolder). A move into/out of a TEAM
#   FOLDER crosses a storage boundary and is a copy+delete under the hood (NodeDeletedEvent, not
#   NodeRenamedEvent), so team-folder re-homing rides the delete/create lifecycle — a fast-follow
#   — NOT this engine. The bin-ON parking rows here also stay design-only (fast-follow).
# The whole feature stays @todo — CI skips it — until the occ+WebDAV step definitions for a move
# are written; until then the unit suite + the live smoke carry the proof, matching how the app
# was built so far.

@todo
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

  Scenario: Moving/renaming within the same mapping keeps it managed
    Given a managed "sync" dashboard file in the "alpha" folder
    When I rename the file within the "alpha" folder
    Then the file stays in "sync" mode under the "alpha" mapping
    And nothing changes in Grafana except the name

  # With "Sync subfolders" OFF (the default), a subfolder is ordinary local NC organization,
  # invisible to Grafana — the dashboard stays bound to the PARENT mapped folder and keeps
  # all its metadata. A file only leaves the mapping when it leaves every mapped folder.
  Scenario: With subfolder-sync off, moving into a subfolder is local-only (stays bound to the parent)
    Given "alpha" has "Sync subfolders" off
    And a managed "sync" dashboard file in the "alpha" folder
    When I move the file into a Nextcloud subfolder of the "alpha" folder
    Then the file stays fully managed in "sync" mode under the "alpha" mapping
    And it keeps its "grafana_uid", "grafana_mapping", and "grafana_folderUid" (still the "alpha" folder)
    And nothing changes in Grafana — the subfolder is local Nextcloud organization only

  # ── 2. mapped → mapped: a real Grafana folder move, UID preserved ─────────────────

  Scenario: Moving a sync file from one mapped folder to another moves the dashboard's Grafana folder
    Given a managed "sync" dashboard file in the "alpha" folder
    When I move the file into the "beta" folder
    Then the dashboard's Grafana folder becomes the "beta" folder
    And the dashboard keeps the same "grafana_uid"
    And the file re-stamps "grafana_mapping" to "beta" and "grafana_folderUid" to the "beta" folder
    And the dashboard is not deleted or recreated

  # ── 3. mapped → unmapped: DELETE in Grafana, strip identity (BIN OFF, default) ────

  Scenario: Moving a sync file out of every mapping deletes it in Grafana and strips the file's identity
    Given the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file in the "alpha" folder
    When I move the file to a folder that is not mapped
    Then the dashboard is deleted in Grafana
    And the file's Grafana identity is stripped (no "grafana_uid", no "grafana_mapping")
    And the full dashboard JSON is still in the Nextcloud file
    And the file is now a plain, untracked ".grafana.json"

  Scenario: Moving that stripped file back into a mapping creates a brand-new dashboard
    Given the Grafana recycle-bin folder is off
    And a plain ".grafana.json" file (once a dashboard, identity stripped) outside any mapping
    When I move the file into the "beta" folder
    Then a brand-new dashboard is created in Grafana from the file's JSON
    And it is created in the "beta" folder with a NEW "grafana_uid"
    And the file's mode becomes "sync" under the "beta" mapping

  # ── 3'. mapped → unmapped WITH the recycle-bin folder on: MOVE to bin, UID kept ───

  Scenario: With the recycle-bin folder on, moving a sync file out parks the dashboard in the bin (UID kept)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file in the "alpha" folder
    When I move the file to a folder that is not mapped
    Then the dashboard is moved into the "nextcloud-trash" Grafana folder (not deleted)
    And the file KEEPS its "grafana_uid" (the id is not stripped — it was not a true delete)
    And the file's mode becomes "unmapped"
    And the full dashboard JSON is still in the Nextcloud file

  Scenario: With the recycle-bin folder on, moving a parked file back into a mapping restores it (same UID)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And an unmapped dashboard file whose Grafana dashboard is parked in the "nextcloud-trash" folder
    When I move the file into the "beta" folder
    Then the dashboard is moved out of "nextcloud-trash" into the "beta" folder
    And the dashboard keeps the same "grafana_uid"
    And the file's mode becomes "sync" under the "beta" mapping

  # ── move a brand-new (untracked) file into a mapping → create-on-land ─────────────

  Scenario: Moving a brand-new dashboard file into a mapping creates it
    Given a ".grafana.json" file that was never tracked in Grafana
    When I move the file into the "alpha" folder
    Then a matching dashboard is created in Grafana in the "alpha" folder
    And the file's mode becomes "sync" under the "alpha" mapping

  # ── link move-out is refused (a link is a read-only pointer) ─────────────────────

  Scenario: Moving a link out of its mapping is blocked
    Given a managed "link" dashboard file in the "links" folder
    When I try to move the file to a folder that is not mapped
    Then the move is refused with a message
    And the file stays in the "links" folder

  # ── subfolders — the lazy, presence-driven mirror (saga Ch2, revised) ─────────────
  # ONE rule: a subfolder exists on the far side exactly when it holds a dashboard. Gated
  # by a per-mapping "Sync subfolders" checkbox (off by default). Designed here, wired in a
  # later Course, so these stay @todo.

  @todo
  Scenario: With subfolder-sync on, moving a dashboard into a subfolder mirrors it to Grafana
    Given "alpha" has "Sync subfolders" on
    And a managed "sync" dashboard file in the "alpha" folder
    When I move the file into a Nextcloud subfolder of the "alpha" folder
    Then a matching Grafana subfolder is created under the "alpha" folder
    And the dashboard is re-parented into that Grafana subfolder
    And the dashboard keeps the same "grafana_uid"
    And the file's "grafana_folderUid" updates to the new subfolder
    And the file stays under the "alpha" mapping

  @todo
  Scenario: An empty subfolder mirrors nothing
    Given "alpha" has "Sync subfolders" on
    When I create an empty Nextcloud subfolder of the "alpha" folder
    Then no folder is created in Grafana
    And the Grafana subfolder appears only once a dashboard is placed in it
