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
# ── SAY IT PLAINLY: WITH THE SHIM OFF, A RESTORE MAKES A NEW DASHBOARD ───────────
#
# It is not the old dashboard coming back. Grafana deleted that one permanently at
# trash time and cannot return it. What a restore does is take the JSON out of the
# Nextcloud file and CREATE a dashboard from it — the same panels, the same queries,
# the same title, and a brand-new uid.
#
# For most people that is indistinguishable from a restore, which is why it is a
# reasonable default. What it costs is everything keyed to the old uid, and none of
# it is recoverable:
#
#   - the **URL**. `/d/<uid>/…` is the uid. Bookmarks, wiki links, runbooks, Slack
#     messages and screenshots pointing at that dashboard all 404.
#   - **version history.** Grafana's per-save revisions belonged to the deleted
#     dashboard. The restored one starts at version 1.
#   - **anything referencing it inside Grafana** — a panel link, a playlist entry, an
#     annotation query, an alert's dashboardUID.
#
# That is the trade the default makes: content is always safe, identity is not. An
# admin who cannot afford to lose the identity turns the shim on, and that is the
# whole reason the setting exists.
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

  # THE DEFAULT PATH. The dashboard was destroyed at trash time; this creates one
  # from the file's JSON. Same content, new object.
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restoring a sync file re-creates the dashboard with a new id (bin off)
    Given the Grafana recycle-bin folder is off
    And a trashed sync dashboard file whose dashboard is already deleted
    When I restore it from the trash
    Then a dashboard is re-created in Grafana from the file's JSON body
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
    And its uid is not "uid-A"
    And the file is managed "sync" again

  # The round-trip that bin-on exists for: the move happens in BOTH systems and both
  # come back together, id preserved. This is the whole payoff of the setting.
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Round-trip — delete moves to the bin in both systems, restore moves both back (bin on)
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a managed "sync" dashboard file for a dashboard "uid-B"
    When I move it to the trash
    Then the file is in the Nextcloud trash
    And dashboard "uid-B" is in the "nextcloud-trash" Grafana folder and still exists
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

  # The consequence of "a new dashboard", written down because it is the part users
  # discover the hard way and the part no amount of care on our side can prevent.
  # @decision, not @unbuilt: preserving the uid across a true Grafana delete is not a
  # feature we have declined to build, it is not possible — Grafana destroyed the
  # object. The recycle-bin shim exists precisely so an admin can opt out of this.
  @user @in-nextcloud @gesture @ui @recycle-bin @decision
  Scenario: A bin-off restore cannot preserve the old dashboard's URL or history
    Given the Grafana recycle-bin folder is off
    And a trashed sync dashboard file whose dashboard is already deleted
    When I restore it from the trash
    Then the dashboard's previous URL no longer resolves in Grafana
    And the restored dashboard has no version history from before the delete
    And nothing in Grafana that referenced the old uid points at it

  # ══ RESTORED IN GRAFANA ════════════════════════════════════════════════════════
  #
  # Because the bin is a SHIM — an ordinary Grafana folder anyone can browse — a
  # restore can legitimately start on the Grafana side: someone drags a dashboard out
  # of "nextcloud-trash" and back where it belongs. That IS a restore, performed by a
  # person who never touched Nextcloud, and the mirror should follow.
  #
  # THE DETECTION IS THE UID, NOT THE FILENAME. The reconcile already knows the uid of
  # every dashboard it sees. What it does not currently do is look in the Nextcloud
  # TRASH for a file carrying that uid — it indexes `$folder->getDirectoryListing()`,
  # and a trashed file is not in the folder. So today it writes a brand-new file and
  # leaves the trashed one orphaned; restore that copy later and two files claim one
  # dashboard, the exact duplicate the reconcile is otherwise careful to avoid.
  #
  # The fix is a trash-aware reconcile: before creating a file for an unseen uid, look
  # for a trashed mirror carrying it and RESTORE that instead. The penpot sibling built
  # exactly this (penpot saga §6.37); neither n8n nor Grafana has it.

  @grafana @in-grafana @occ @ui @recycle-bin @unbuilt
  Scenario: Moving a dashboard out of the bin in Grafana brings its file back out of the trash
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    When someone moves that dashboard back to its mapped folder in Grafana
    And the "alpha" mapping is pulled
    Then the trashed file is restored rather than a second file being created
    And exactly one file carries that dashboard's uid
    And it holds the dashboard's current content

  # The matching rule, stated on its own because it is the part that is missing: the
  # reconcile must consult the trash BY UID before it decides a dashboard is new.
  @grafana @in-grafana @occ @recycle-bin @unbuilt
  Scenario: A reconcile finds a trashed mirror by uid before creating a new file
    Given a trashed sync dashboard file
    When its dashboard is seen again by a pull
    Then the reconcile matches it to the trashed file by uid
    And no second file is created for that dashboard

  # ══ WHAT STILL DOES NOT RESTORE FROM THE GRAFANA SIDE ══════════════════════════
  #
  # A dashboard merely EXISTING again is not a restore. Nextcloud's trash is the
  # user's own undo history, and a dashboard being re-created in Grafana by unrelated
  # means is not permission to empty it. The scenario above is narrow on purpose: it
  # is about a dashboard we ourselves parked, being taken back out of the bin we
  # ourselves put it in. Anything wider is the user's call.

  @user @in-nextcloud @gesture @ui @decision
  Scenario: A dashboard reappearing in Grafana never empties the Nextcloud trash
    Given a trashed sync dashboard file
    When the dashboard exists in Grafana again
    And the "alpha" mapping is pulled
    Then the file is still in the Nextcloud trash
    And the user is the only one who can restore it
