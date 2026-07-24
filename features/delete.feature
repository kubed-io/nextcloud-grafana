# Deletion semantics differ by mode — and this is the highest-stakes surface in the
# whole app, because our ingredient has NO undo.
#
# THE FINDING THAT SHAPES THIS FILE (saga Ch2 Round 2): we proved on live Grafana
# that our service account cannot reach any soft-delete/trash. A
# `DELETE /api/dashboards/uid/{uid}` is PERMANENT — instant 404, nothing in the
# app-platform trash, `?deleted=true` returns 401. So the master's (n8n) recipe —
# trash=archive, purge=delete, restore=unarchive — DOES NOT translate: there is no
# archive to fall back to.
#
# THE RE-PLATED MODEL — reversibility lives in Nextcloud, not Grafana:
#   • The file IS the backup. A sync/unmapped/link file carries what it needs to
#     rebuild (full JSON for sync/unmapped; the pointer for link).
#   • The Nextcloud trashbin is the SINGLE reversible gate:
#       - move-to-trash    → recoverable. Grafana is NOT deleted at this step.
#       - restore          → the file comes back; re-upsert/re-link as needed.
#       - purge-from-trash → the ONE moment we issue the irreversible Grafana DELETE.
#   NC's own two-step trash becomes the soft-delete Grafana never gave us.
#
# Modes (saga Ch2 Round 2): sync / link / unmapped. A file with NO Grafana metadata
# is "untracked" (a plain document) — distinct from "unmapped" (a sync file moved
# out of its mapping that still carries its UID + full JSON).
#
# OPEN FORK E (saga Ch2 Round 2): the exact trash-gate mechanics depend on the
# trashbin-DAV listener firing BeforeNodeDeletedEvent on the pod — unproven here (the
# master's purge leg is @todo for the same reason). Until proven, the purge→Grafana-
# DELETE legs stay @todo.
#
# SUBFOLDER SAFETY VALVE (saga Ch2 Round 2): deletes INSIDE a subfolder of a mapped
# folder are BLOCKED for now — we don't open the cascade delete door until the
# subfolder model is cooked.
#
# DESIGN, NOT WIRED: the whole feature is @todo — CI skips it — until the delete
# engine is cooked and the forks are called by Dr K.

@todo
Feature: Deleting a dashboard file
  As a Nextcloud user
  I want delete/trash/restore to do the right thing per mode
  So that removing a file never silently desyncs the two systems — and never loses data

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ── sync: trash is recoverable, only PURGE touches Grafana ────────────────────

  Scenario: Trashing a sync-mode file does NOT delete the dashboard (it stays live and recoverable)
    Given a managed "sync" dashboard file
    When I move it to the trash
    Then the dashboard still exists in Grafana
    And the file is recoverable from the Nextcloud trash

  # Purge is the ONE irreversible moment — the file leaves NC trash, so we delete in
  # Grafana. @todo until the trashbin-DAV listener is proven on the pod (fork E).
  @todo
  Scenario: Purging a sync-mode file permanently deletes the dashboard
    Given a trashed "sync" dashboard file
    When I purge it from the trash
    Then the dashboard is permanently deleted in Grafana

  Scenario: Restoring a sync-mode file brings the dashboard back from the file's JSON
    Given a trashed "sync" dashboard file
    When I restore it from the trash
    Then the dashboard exists in Grafana with the same "grafana_uid"

  # ── link: trash only severs the tie; the dashboard is never deleted ───────────

  Scenario: Trashing a link only unlinks it — the dashboard is untouched
    Given a managed "link" dashboard file
    When I move it to the trash
    Then the dashboard in Grafana is not deleted
    And the link is recoverable from the Nextcloud trash

  # ── untracked: never our business ─────────────────────────────────────────────

  Scenario: Deleting an untracked dashboard file touches nothing in Grafana
    Given an untracked ".grafana.json" file
    When I delete it
    Then Grafana is not contacted

  # ── unmapped (a moved-out sync file: keeps its UID + full JSON) ───────────────
  # An unmapped file has no live mapping, so trash and restore are Grafana no-ops —
  # the dashboard's fate was already settled by fork D at move-out. The file is still
  # the backup, so trashing it is safe and recoverable.
  Scenario: Trashing an unmapped file is recoverable and does not touch Grafana
    Given an unmapped dashboard file that still carries its "grafana_uid"
    When I move it to the trash
    Then the trash move succeeds
    And Grafana is not contacted
    And the file is recoverable from the Nextcloud trash

  # Purging an unmapped file: if fork D left the dashboard live, THIS is where it is
  # deleted; if fork D already removed it, this is a no-op. @todo until fork D + the
  # trashbin listener are called.
  @todo
  Scenario: Purging an unmapped file permanently deletes the dashboard (if still live)
    Given a trashed unmapped dashboard file that still carries its "grafana_uid"
    When I purge it from the trash
    Then the dashboard is permanently deleted in Grafana (if fork D left it live)

  Scenario: Restoring an unmapped file from trash touches nothing in Grafana
    Given a trashed unmapped dashboard file that still carries its "grafana_uid"
    When I restore it from the trash
    Then Grafana is not contacted
    And the file returns as an unmapped file

  # ── subfolder safety valve — blocked for now ─────────────────────────────────

  Scenario: Deleting a file inside a subfolder of a mapped folder is blocked
    Given a managed "sync" dashboard file in a subfolder of the "alpha" folder
    When I try to delete the file
    Then the delete is refused with a message
    And nothing changes in Grafana

  # ── error path — the irreversible step must never half-happen ────────────────
  # If the Grafana DELETE can't be confirmed on purge, we abort so the file stays in
  # NC trash (still recoverable). Forcing a real transport failure mid-DELETE is
  # brittle in integration; the cleaner home is a unit test against a mocked
  # GrafanaClient. Left @todo.
  @todo
  Scenario: A purge is aborted if Grafana is unreachable
    Given a trashed "sync" dashboard file
    And Grafana is unreachable
    When I purge it from the trash
    Then the purge is aborted and the file stays in the Nextcloud trash
