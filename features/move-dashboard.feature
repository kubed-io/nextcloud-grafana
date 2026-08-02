# How the app reacts to every move a Nextcloud user can make on a dashboard file.
# The stable thread is the dashboard UID **plus the full JSON we hold in the file**.
# (COPY is the opposite — always a new instance; see copy-dashboard.feature.)
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
#          to the destination folder, **same uid**. (See delete-dashboard.feature — trash uses the
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
# Scenarios are tagged per-scenario, not per-file: @todo where the engine is built and only the
# occ+WebDAV move steps are missing (the unit suite + the live smoke carry the proof meanwhile),
# @unbuilt where there is genuinely no code. A @todo above `Feature:` used to hide that split.
#
# MOVE AND DELETE ARE THE SAME GRAFANA OPERATION, chosen by the same setting — moving a file out
# of every mapping and trashing it both run through the bin decision. They live in separate files
# because the gestures differ, so whenever either changes, check the rules still agree.
# Every scenario whose outcome depends on that setting carries @recycle-bin.

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

  # An UNTAGGED subfolder is ordinary local NC organisation, invisible to Grafana — the
  # dashboard stays bound to the PARENT mapped folder and keeps all its metadata. A file
  # only leaves the mapping when it leaves every mapped folder. A subfolder becomes a
  # Grafana folder by carrying the `grafana` tag; see create-folder.feature.
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
  # Unit-tested (testALinkMoveIntoADifferentMappingOnlyRehomesThePointer) and never
  # written down. A link owns no dashboard, so a mapped→mapped move re-stamps which
  # mapping the pointer belongs to and stops there — Grafana is not called at all.

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Moving a link from one mapped folder to another only re-homes the pointer
    Given a managed "link" dashboard file in the "links" folder
    When I move the file into the "beta" folder
    Then the file's mapping becomes "beta"
    And the dashboard's Grafana folder is unchanged
    And Grafana is not contacted

  # ── the safety rules: never delete on a guess ────────────────────────────────────
  # Both of these are unit-tested invariants that no scenario stated. They are the
  # rules that make "move out = delete" survivable, and Grafana has no undo, so they
  # are worth reading as specification rather than as implementation detail.

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: A failed Grafana delete on move-out never strips the file's identity
    Given the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file in the "alpha" folder
    And the Grafana delete will fail
    When I move the file to a folder that is not mapped
    Then the file still carries its "grafana_uid"
    And the file is still reconcilable with its dashboard

  # A destination the app cannot classify — outside the user's file tree, or a path
  # it cannot resolve to "mapped" or "unmapped" — must NOT be read as "left every
  # mapping". Treating unknown as unmapped would turn an unreadable path into a
  # permanent Grafana delete. Unknown means do nothing.
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
  # Everything above starts in Nextcloud. A dashboard can also change folder in
  # Grafana, and the pull is what makes that observable — which is what @in-grafana
  # means. These are the mirror image of the Nextcloud-side moves above.

  # Both folders are mapped, so the dashboard should end up in the other mapping's
  # Nextcloud folder. Today the pull prunes it from the source folder
  # (reconcile.feature covers that leg) and writes it fresh into the destination —
  # correct end state, reached as a delete-and-recreate rather than as a move.
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

  # The rule that makes subfolders usable: a pull must never yank a file back to the
  # mapping root because that is where it would have created it. Where the user filed
  # it is theirs to decide. SyncService renames in place within the file's own folder;
  # this is that promise, stated as behaviour.
  @grafana @in-grafana @occ @ui @todo
  Scenario: A pull never relocates a file the user filed into a subfolder
    Given a managed "sync" dashboard file the user moved into a subfolder of "alpha"
    When the dashboard is renamed in Grafana
    And the "alpha" mapping is pulled
    Then the file is still in that subfolder
    And its name reflects the new title

  # ── into a TAGGED subfolder — a real Grafana folder ───────────────────────────────
  # A subfolder that carries the `grafana` tag is a Grafana folder in its own right
  # (create-folder.feature owns how it gets there). Moving a dashboard into one is
  # therefore a re-parent, not local organisation. @unbuilt: GrafanaClient has no folder
  # write operations, so there is nothing to re-parent into yet.

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

