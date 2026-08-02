# Restoring a dashboard file from the Nextcloud trash — the other half of
# delete-dashboard.feature, and a behaviour in its own right rather than an appendix
# to deleting.
#
# ── WHY THIS IS A SEPARATE FILE ──────────────────────────────────────────────────
#
# A restore does not need to re-perform a delete to be specified. It starts from a
# state — *"a trashed sync dashboard file whose dashboard is parked in the bin"* —
# and the question it answers is what comes back. Folding that into the delete file
# meant every restore scenario carried a delete it was not testing, and the reader
# had to hold both halves in mind to find the one being asserted.
#
# Delete owns "what leaves". Restore owns "what comes back, and as what".
#
# ── RESTORE IS WHERE THE TWO BIN MODELS DIVERGE MOST ─────────────────────────────
#
# Trashing looks superficially similar either way — the file goes to the trash, and
# something happens in Grafana. Restoring is where the difference becomes visible to
# the user, because it decides whether they get their dashboard BACK or merely get
# a new dashboard with the same content:
#
#   | bin OFF (default)                    | bin ON (opt-in)                       |
#   |--------------------------------------|---------------------------------------|
#   | the uid was stripped at trash time   | the uid was kept                      |
#   | create-on-land makes a NEW dashboard | the parked dashboard is MOVED back    |
#   | **new uid**, history gone            | **same uid**, history intact          |
#   | content preserved (it was in the file) | content never left Grafana          |
#
# Nothing is ever lost either way — a sync file holds the whole dashboard JSON, which
# is the invariant that makes bin-off survivable at all. What bin-on buys is
# **identity**: the uid, the version history, and every Grafana-side reference to it.
#
# ── THE HARD PART IS THAT THE WORLD MOVED ────────────────────────────────────────
#
# A file can sit in the trash for weeks. By the time it comes back, its mapping may
# be gone, its parked dashboard may have been deleted in Grafana by hand, or someone
# may already have moved that dashboard back. A restore must succeed in all three
# cases: Nextcloud's trash is the user's own undo history, and this app does not get
# to veto it. The bottom section is those cases.
#
# ── STATUS ───────────────────────────────────────────────────────────────────────
#
# RestoreFromTrashListener + DeleteService::restore are built and unit-tested
# (bin-ON-moves-back-keeping-id, bin-OFF-recreates, no-recreate-outside-a-sync-
# mapping, link-is-a-noop). The bin-on restore now runs in CI; the rest are @todo
# for want of their step definitions, not for want of code.

Feature: Restoring a dashboard file from the trash
  As a Nextcloud user
  I want a restore to bring back my dashboard, with its identity where possible
  So that the Nextcloud trash is a real undo for a system that has none of its own

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ══ RESTORED IN NEXTCLOUD ══════════════════════════════════════════════════════

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restoring a sync file re-creates the dashboard with a new id (bin off)
    Given the Grafana recycle-bin folder is off
    And a trashed sync dashboard file (its Grafana dashboard already deleted)
    When I restore it from the trash
    Then a dashboard is re-created in Grafana from the file's JSON
    And it has a NEW "grafana_uid"
    And the file's mode becomes "sync" under its original mapping

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Restoring a parked file moves its dashboard back with the same id (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    When I restore it from the trash
    Then the dashboard is moved out of "nextcloud-trash" back into its mapped folder
    And the dashboard keeps the same "grafana_uid"

  # The full round-trip, stated end to end: the content never leaves Nextcloud, the
  # Grafana dashboard blips out on trash and comes back fresh on restore. No data
  # loss; only the Grafana id and its version history are not preserved.
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Round-trip — delete then restore re-creates the dashboard (bin off)
    Given the Grafana recycle-bin folder is off
    And a managed "sync" dashboard file for a dashboard "uid-A"
    When I move it to the trash
    Then dashboard "uid-A" no longer exists in Grafana
    And the trashed file carries no Grafana metadata
    When I restore it from the trash
    Then a dashboard exists in Grafana again with the same content
    And its uid is new (not "uid-A")
    And the file is managed "sync" again

  # The round-trip that bin-on exists for: the move happens in BOTH systems and both
  # come back together, id preserved. This is the whole payoff of the setting.
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Round-trip — delete moves to the bin in both systems, restore moves both back (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file for a dashboard "uid-B"
    When I move it to the trash
    Then the file is in the Nextcloud trash
    And dashboard "uid-B" is in the "nextcloud-trash" Grafana folder (still exists)
    When I restore it from the trash
    Then the file is back in its mapped folder
    And dashboard "uid-B" is back in its mapped Grafana folder with the same uid

  # The mapping was torn down while the file waited in the trash, so there is no
  # longer anywhere for it to belong. Restoring must still succeed as a Nextcloud
  # operation — the user's undo is not this app's to veto — and simply leave the
  # file unmanaged. RestoreFromTrashListener already handles the unresolvable
  # mapping; what is untested is that the file comes back at all.
  @user @in-nextcloud @gesture @ui @todo
  Scenario: Restoring into a mapping that no longer exists leaves a plain file
    Given a trashed sync dashboard file
    And its mapping has since been removed
    When I restore it from the trash
    Then the file is back in Nextcloud
    And it is not managed by any mapping
    And Grafana is not contacted

  # Bin ON, but someone deleted the parked dashboard in Grafana directly. The kept
  # uid now names nothing. The restore is an idempotent upsert on that uid, so it
  # re-creates rather than failing — the file's JSON is the surviving copy.
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restoring a parked file whose dashboard was deleted in Grafana re-creates it
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    And that parked dashboard has since been permanently deleted in Grafana
    When I restore it from the trash
    Then a dashboard exists in Grafana again holding the file's content
    And the file points at that dashboard

  # The race the scheduled pull makes easy to hit: someone moves the dashboard back
  # out of the bin in Grafana, then the user restores in Nextcloud. Moving an
  # already-in-place dashboard must be a no-op, not a conflict — the same
  # idempotency every other write in this app relies on.
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restoring a file whose dashboard is already back in place is not a conflict
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard has already been moved back to its mapped folder
    When I restore it from the trash
    Then the file is back in its mapped folder
    And the dashboard exists exactly once, in its mapped Grafana folder

  # ══ NOTHING RESTORES FROM THE GRAFANA SIDE ═════════════════════════════════════
  #
  # There is no Grafana-side counterpart to a restore, and that is a decision rather
  # than a gap. Nextcloud's trash is the user's own undo history; this app does not
  # reach into it in either direction. A dashboard reappearing in Grafana does not
  # pull a file back out of someone's trash.
  #
  # The one case where that blindness arguably costs something — a parked dashboard
  # moved back in Grafana while its file sits in the trash — is specified as
  # @unbuilt in delete-dashboard.feature, next to the case where the same blindness
  # is exactly right. They belong together, so they stay there.

  @user @in-nextcloud @gesture @ui @decision
  Scenario: A dashboard reappearing in Grafana never empties the Nextcloud trash
    Given a trashed sync dashboard file
    When the dashboard exists in Grafana again
    And the "alpha" mapping is pulled
    Then the file is still in the Nextcloud trash
    And the user is the only one who can restore it
