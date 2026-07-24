# How the app reacts to every move a Nextcloud user can make on a dashboard file.
# A MOVE mirrors as the SAME dashboard moving in Grafana — never a duplicate. The
# stable thread is the dashboard UID **plus the full JSON we hold in the file**.
# (COPY is the opposite — always a new instance; see copy.feature.)
#
# Model (saga Ch2 Round 2): modes are sync / link / unmapped. "unmapped" is the
# state a sync file enters when moved OUT of its mapped folder: Nextcloud keeps the
# full dashboard JSON + the UID + the last-seen version, and clears the mapping.
# Moving it back into any mapping RE-CREATES / upserts the dashboard from the JSON
# we still hold, re-using the same UID.
#
# WHY NOT "archive" — the master (n8n) archives on move-out and unarchives on
# move-in. We proved on live Grafana that our service account has NO reachable
# soft-delete/trash: a delete is permanent. So the reversibility net is NOT a
# Grafana-side archived object — it is **the file itself** (the JSON is the backup)
# and, for delete, the **Nextcloud trashbin** (see delete.feature). The UID +
# upsert-by-uid is what makes move-back-in "the same dashboard, not a new one."
#
# OPEN FORK D (saga Ch2 Round 2): on move-OUT of a mapping, what happens to the
# still-LIVE Grafana dashboard? (i) leave it live and just unmap the file, or
# (ii) remove it from Grafana and lean on the file's JSON to restore on move-back-in.
# Until Dr K calls it, the move-out scenarios below assert only the FILE-side
# contract (keeps UID + JSON, clears mapping); the Grafana-side consequence is @todo.
#
# DESIGN, NOT WIRED: this file is driving-truth for the file-lifecycle Course. The
# whole feature is @todo — CI skips it — until the destructive/move engine is cooked.

@todo
Feature: Moving a dashboard file is the same dashboard leaving and returning
  As a Nextcloud user
  I want moves to mirror as the same dashboard in Grafana
  So that relocating a file never duplicates or silently desyncs a dashboard

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"
    And a folder mapped as "sync" to the Grafana folder "beta"
    And a folder mapped as "link" to the Grafana folder "links"

  # ── within the same mapping: no Grafana change ───────────────────────────────────

  Scenario: Move within the same mapping (rename) keeps it managed
    Given a managed "sync" dashboard file in the "alpha" folder
    When I rename the file within the "alpha" folder
    Then the file stays in "sync" mode in the "alpha" mapping
    And nothing changes in Grafana except the name

  # With "Sync subfolders" OFF (the default), a subfolder is an ordinary local NC folder,
  # invisible to Grafana. This is the n8n-like flat model: the nesting is cosmetic, the
  # dashboard stays bound to the PARENT mapped folder, and it keeps ALL its metadata. A
  # file only becomes "unmapped" when it leaves every mapped folder — a subfolder is still
  # inside the mapping.
  Scenario: With subfolder-sync off, moving into a subfolder is local-only (stays bound to the parent)
    Given "alpha" has "Sync subfolders" off
    And a managed "sync" dashboard file in the "alpha" folder
    When I move the file into a Nextcloud subfolder of the "alpha" folder
    Then the file stays fully managed in "sync" mode under the "alpha" mapping
    And it keeps its "grafana_uid", "grafana_mapping", and "grafana_folderUid" (still the "alpha" folder)
    And nothing changes in Grafana — the subfolder is local Nextcloud organization only

  # ── sync move-out → unmapped (the file is the backup) ─────────────────────────

  Scenario: Moving a sync file out of its mapping unmaps it but keeps the full JSON
    Given a managed "sync" dashboard file in the "alpha" folder
    When I move the file to a folder that is not mapped
    Then the file's mode becomes "unmapped"
    And the file keeps its "grafana_uid" and "grafana_version"
    And the file's "grafana_mapping" is cleared
    And the full dashboard JSON is still in the Nextcloud file

  # OPEN FORK D — what happens to the LIVE Grafana dashboard on move-out is undecided
  # (leave-live vs remove-and-rely-on-JSON). Kept @todo until Dr K calls it.
  @todo
  Scenario: Moving a sync file out of its mapping — the live Grafana dashboard (fork D)
    Given a managed "sync" dashboard file in the "alpha" folder
    When I move the file to a folder that is not mapped
    Then the Grafana dashboard is handled per fork D (leave-live or remove-and-restore-from-JSON)

  # ── move back in → re-create/upsert from the JSON we hold (same UID) ──────────

  Scenario: Moving an unmapped file back into a mapping restores the dashboard from its JSON
    Given an unmapped dashboard file that still carries its "grafana_uid"
    When I move the file into the "beta" folder
    Then the dashboard is upserted into Grafana from the file's JSON
    And the dashboard keeps the same "grafana_uid"
    And the file's mode becomes "sync" in the "beta" mapping

  # Because our restore is always "upsert from the JSON we hold", a Grafana-side
  # hard-delete in the meantime is NOT a special case — the upsert simply re-creates
  # the dashboard at the same UID (or a fresh one if Grafana refuses a dead UID).
  Scenario: Restoring when the Grafana dashboard was deleted re-creates it from the file
    Given an unmapped dashboard file that still carries its "grafana_uid"
    And that dashboard no longer exists in Grafana
    When I move the file into the "beta" folder
    Then the dashboard is re-created in Grafana from the file's JSON
    And the file's mode becomes "sync" in the "beta" mapping

  # Move-in duplicate (saga §14.19). A file carrying a UID is moved into a mapping
  # where that dashboard is ALREADY synced. This is not the same file relocating;
  # it's a duplicate. Nextcloud's own rules lead the behaviour:
  #   • same name → the move is refused (WebDAV Overwrite:F → 412), like any NC
  #                 same-name move. The existing synced file is the source of truth.
  #   • diff name → the incoming is minted as a BRAND-NEW dashboard (copy semantics):
  #                 MotionService sees a sibling already carrying the UID and hands the
  #                 file to CreateService, which strips the carried UID and creates a
  #                 fresh dashboard — the existing file is left untouched.
  Scenario: Moving a duplicate in under the same name is refused (the dashboard is already synced here)
    Given a managed "sync" dashboard file in the "alpha" folder
    And an unmapped copy of that same dashboard with the same "grafana_uid" outside any mapping
    When I try to move the unmapped copy into the "alpha" folder under the same name
    Then the move is refused with a message
    And the original synced file is unchanged

  Scenario: Moving a duplicate in under a different name mints a brand-new dashboard
    Given a managed "sync" dashboard file in the "alpha" folder
    And an unmapped copy of that same dashboard with the same "grafana_uid" outside any mapping
    When I move the unmapped copy into the "alpha" folder under a different name
    Then the moved-in file becomes a brand-new dashboard in Grafana
    And the original synced file is unchanged

  # Move-in create: an untracked file (no UID) dragged into a mapping is create-on-
  # land — the create listener fires on the NodeRenamedEvent (NC doesn't fire
  # NodeWrittenEvent for a move) and mints the dashboard, stamping sync + the mapping.
  Scenario: Moving a brand-new dashboard file into a mapping creates it
    Given a ".grafana.json" file that was never tracked in Grafana
    When I move the file into the "alpha" folder
    Then a matching dashboard is created in Grafana
    And the file's mode becomes "sync" in the "alpha" mapping

  # ── subfolders — the lazy, presence-driven mirror (saga Ch2, revised) ─────────
  # ONE rule: a subfolder exists on the far side exactly when it holds a dashboard.
  # Gated by a per-mapping "Sync subfolders" checkbox (off by default). No hidden
  # child mappings, no "two kinds" — a Nextcloud subfolder mirrors to a Grafana
  # subfolder, created LAZILY the moment a dashboard lands in it; the dashboard
  # re-parents and the file stamps grafana_folderUid. The subtree stays under the
  # top-level mapping. These are @todo — designed here, wired in a later Course.

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
  Scenario: With subfolder-sync on, creating a dashboard in a subfolder creates the Grafana subfolder
    Given "alpha" has "Sync subfolders" on
    When I create a ".grafana.json" dashboard in a Nextcloud subfolder of the "alpha" folder
    Then a matching Grafana subfolder is created under the "alpha" folder
    And the dashboard is created inside that Grafana subfolder
    And the file's "grafana_folderUid" is the new subfolder

  @todo
  Scenario: An empty subfolder mirrors nothing
    Given "alpha" has "Sync subfolders" on
    When I create an empty Nextcloud subfolder of the "alpha" folder
    Then no folder is created in Grafana
    And the Grafana subfolder appears only once a dashboard is placed in it

  # Deleting inside a subfolder is NOT special-cased — a dashboard in a subfolder
  # deletes through the same Nextcloud-trashbin gate as any other (see delete.feature).
  # The retired "block subfolder deletes" scaffold is gone with the hidden-mapping model.

  # ── link move-out is refused ─────────────────────────────────────────────────

  Scenario: Moving a link out of its mapping is blocked
    Given a managed "link" dashboard file in the "links" folder
    When I try to move the file to a folder that is not mapped
    Then the move is refused with a message
    And the file stays in the "links" folder

  # ── relocating an already-unmapped file: pure relocation ─────────────────────

  Scenario: Moving an unmapped file between unmapped locations changes nothing
    Given an unmapped dashboard file that still carries its "grafana_uid"
    When I move the file to another folder that is not mapped
    Then the file stays "unmapped"
    And its "grafana_uid" and "grafana_version" are unchanged
    And nothing changes in Grafana

  # ── decision cases (saga Ch2 Round 2 forks): documented, not yet designed ─────
  # These need a design decision before they get concrete Then-steps:
  #   a. sync moved directly mapping→mapping (different folder): re-tag in place vs
  #      eject+reattach vs block. (Currently blocked by MoveGuardListener.)
  #   b. moving into a nested subfolder owned by a different mapping (nearest
  #      enclosing wins) — interaction with case a and the cascade model.
  #   c. link rename within its mapping — does the filename matter, or is the Grafana
  #      name authoritative?
  #   d. deleting an unmapped file (it has a UID + JSON but no live mapping) — see
  #      delete.feature.
