# How the app reacts to every move a Nextcloud user can make on a dashboard file.
# A MOVE mirrors as the SAME dashboard moving in Grafana — never a duplicate. The
# stable link is the dashboard id, so a move out and back in is an archive then a
# restore, not a delete then a create. (COPY is the opposite — always a new
# instance; see copy.feature.)
#
# Model (saga Chapter 3 §14): modes are sync / link / unmapped. "unmapped" is the
# state a sync file enters when moved OUT of its mapped folder: NC keeps the full
# JSON + the dashboard id + versionId, clears the mapping, and the dashboard is
# archived in Grafana. Moving it back into any mapping restores (unarchives) it.
#
# LIVE (saga §14.2, Phase 2): the sync move-out → unmapped + archive, the
# unmapped move-in → restore, within-mapping moves, link move-out refusal, and
# unmapped relocation are wired (MoveGuardListener + MotionListener +
# MotionService) and asserted here over WebDAV (MOVE) + the Grafana REST API. The
# hard-deleted restore-fallback and brand-new move-in create are now live too;
# the lone remaining edge is merge-on-collision (an unmapped copy moved in over an
# already-synced file with the same id), which still needs a metadata-by-id lookup.

@todo
Feature: Moving a dashboard file is the same dashboard leaving and returning
  As a Nextcloud user
  I want moves to mirror as the same dashboard in Grafana
  So that relocating a file never duplicates or silently desyncs a dashboard

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana tag "nextcloud:alpha"
    And a folder mapped as "sync" to the Grafana tag "nextcloud:beta"
    And a folder mapped as "link" to the Grafana tag "nextcloud:links"

  # ── within the same mapping: no Grafana change ───────────────────────────────────

  Scenario: Move within the same mapping (rename) keeps it managed
    Given a managed "sync" dashboard file in the "nextcloud:alpha" folder
    When I rename the file within the "nextcloud:alpha" folder
    Then the file stays in "sync" mode in the "nextcloud:alpha" mapping
    And nothing changes in Grafana except the name

  Scenario: Move into a subfolder of the same mapping keeps it managed
    Given a managed "sync" dashboard file in the "nextcloud:alpha" folder
    When I move the file into a subfolder of the "nextcloud:alpha" folder
    Then the file stays in "sync" mode in the "nextcloud:alpha" mapping
    And nothing changes in Grafana

  # ── sync move-out → unmapped + archived ──────────────────────────────────────

  Scenario: Moving a sync file out of its mapping unmaps it and archives in Grafana
    Given a managed "sync" dashboard file in the "nextcloud:alpha" folder
    When I move the file to a folder that is not mapped
    Then the file's mode becomes "unmapped"
    And the file keeps its "n8n_id" and "n8n_versionId"
    And the file's "n8n_mapping" is cleared
    And the dashboard is archived (hidden, preserved) in Grafana
    And the full dashboard JSON is still in the Nextcloud file

  # ── move back in → restore (same dashboard, not a new one) ────────────────────

  Scenario: Moving an unmapped file back into a mapping restores the dashboard
    Given an unmapped dashboard file that still carries its "n8n_id"
    When I move the file into the "nextcloud:beta" folder
    Then the dashboard is unarchived in Grafana
    And the file's mode becomes "sync" in the "nextcloud:beta" mapping
    And the "n8n_id" is unchanged

  # Restore-fallback: the unmapped file kept its id, but the dashboard was hard-
  # deleted in Grafana in the meantime. moveIn catches the unarchive 404 and recreates
  # from the file we still hold (a fresh id), then re-stamps sync in the target.
  Scenario: Restoring when the Grafana dashboard was hard-deleted falls back to create
    Given an unmapped dashboard file that still carries its "n8n_id"
    And that dashboard no longer exists in Grafana
    When I move the file into the "nextcloud:beta" folder
    Then a new dashboard is created in Grafana from the file
    And the file's mode becomes "sync" in the "nextcloud:beta" mapping

  # Move-in duplicate (saga §14.19). A file carrying an id is moved into a mapping
  # where that dashboard is ALREADY synced — e.g. an admin restored it in Grafana and it
  # synced back into the folder while an unmapped copy still existed. This is not the
  # same file relocating; it's a duplicate. Nextcloud's own rules lead the behaviour:
  #   • same name → the move is refused (WebDAV Overwrite:F → 412), exactly like any
  #                 NC same-name move. The existing synced file is the source of truth.
  #   • diff name → the incoming is minted as a BRAND-NEW dashboard (copy semantics,
  #                 §14.5): MotionService::moveIn sees a sibling already carrying the
  #                 id and hands the file to CreateService, which strips the carried id
  #                 and creates a fresh dashboard — the existing file is left untouched.
  Scenario: Moving a duplicate in under the same name is refused (the dashboard is already synced here)
    Given a managed "sync" dashboard file in the "nextcloud:alpha" folder
    And an unmapped copy of that same dashboard with the same "n8n_id" outside any mapping
    When I try to move the unmapped copy into the "nextcloud:alpha" folder under the same name
    Then the move is refused with a message
    And the original synced file is unchanged

  Scenario: Moving a duplicate in under a different name mints a brand-new dashboard
    Given a managed "sync" dashboard file in the "nextcloud:alpha" folder
    And an unmapped copy of that same dashboard with the same "n8n_id" outside any mapping
    When I move the unmapped copy into the "nextcloud:alpha" folder under a different name
    Then the moved-in file becomes a brand-new dashboard in Grafana
    And the original synced file is unchanged

  # Move-in create: an untracked file (no id) dragged into a mapping is create-on-
  # land — CreateInN8nListener fires on the NodeRenamedEvent (NC doesn't fire
  # NodeWrittenEvent for a move) and mints the dashboard, stamping sync + the mapping.
  Scenario: Moving a brand-new dashboard file into a mapping creates it
    Given a ".grafana.json" file that was never tracked in Grafana
    When I move the file into the "nextcloud:alpha" folder
    Then a matching dashboard is created in Grafana
    And the file's mode becomes "sync" in the "nextcloud:alpha" mapping

  # ── link move-out is refused ─────────────────────────────────────────────────

  Scenario: Moving a link out of its mapping is blocked
    Given a managed "link" dashboard file in the "nextcloud:links" folder
    When I try to move the file to a folder that is not mapped
    Then the move is refused with a message
    And the file stays in the "nextcloud:links" folder

  # ── relocating an already-unmapped file: pure relocation ─────────────────────

  Scenario: Moving an unmapped file between unmapped locations changes nothing
    Given an unmapped dashboard file that still carries its "n8n_id"
    When I move the file to another folder that is not mapped
    Then the file stays "unmapped"
    And its "n8n_id" and "n8n_versionId" are unchanged
    And nothing changes in Grafana

  # ── decision cases (saga Chapter 3 §14.2 a–d): documented, not yet designed ─────────
  # These need a design decision before they get concrete Then-steps:
  #   a. sync moved directly mapping→mapping (different tag): re-tag in place vs
  #      eject+reattach vs block. (Currently blocked by MoveGuardListener.)
  #   b. moving into a nested subfolder owned by a different mapping (nearest
  #      enclosing wins) — interaction with case a.
  #   c. link rename within its mapping — does the filename matter, or is the Grafana
  #      name authoritative?
  #   d. deleting an unmapped file (it has an id + an archived dashboard) — see
  #      delete.feature.
