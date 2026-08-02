# Copying a dashboard file. Where a MOVE is "the same dashboard" (see move-dashboard.feature),
# a COPY is ALWAYS a brand-new instance. A copy never inherits the original's Grafana
# identity — its metadata (grafana_uid, version, mapping, mode) is stripped the moment
# it is copied. Copy is therefore the single safest point to strip metadata:
# whatever the source was (sync, link, unmapped), the copy starts clean.
#
# Nextcloud distinguishes copy from move at the event layer (NodeCopiedEvent vs
# NodeRenamedEvent), which is what lets us treat them oppositely.
#
# COPYING A FOLDER is a different question with a different blast radius — one
# gesture, N far-side creates, and a folder identity of its own to strip. It lives
# in copy-folder.feature.
#
# STATUS: the copy path is built (CopyListener + CopyService, unit-tested). These are
# @todo — the code exists, the WebDAV COPY step definitions do not — except where
# noted. The file-level @todo this used to carry could not say that.

Feature: Copying a dashboard file always makes a new instance
  As a Nextcloud user
  I want a copy to be a fresh dashboard, never a hijack of the original
  So that duplicating a file is safe and predictable

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ══ COPIED IN NEXTCLOUD ════════════════════════════════════════════════════════

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Copy within a mapped sync folder becomes a new dashboard in Grafana
    Given a managed "sync" dashboard file in the "alpha" folder
    When I copy the file within the "alpha" folder
    Then the copy carries no inherited "grafana_uid"
    And the copy is registered as a NEW dashboard in Grafana with its own uid
    And the original file and dashboard are unchanged
    And there are now two distinct dashboards in Grafana

  @user @in-nextcloud @gesture @ui
  Scenario: Copy to outside any mapping is a plain untracked file
    Given a managed "sync" dashboard file in the "alpha" folder
    When I copy the file to a folder that is not mapped
    Then the copy has no Grafana metadata
    And no dashboard is created in Grafana for the copy
    And the copy is treated as a plain document

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Copy of an unmapped file strips its metadata wherever it lands
    Given an unmapped dashboard file that still carries its "grafana_uid"
    When I copy the file to a folder that is not mapped
    Then the copy has no Grafana metadata
    And the original unmapped file keeps its "grafana_uid"

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Copy of an unmapped file into a mapping becomes a new dashboard
    Given an unmapped dashboard file that still carries its "grafana_uid"
    When I copy the file into the "alpha" folder
    Then the copy carries no inherited "grafana_uid"
    And the copy is registered as a NEW dashboard in Grafana with its own uid
    And the original unmapped file's dashboard is not restored or duplicated

  # ── a link copies like anything else: the copy is not a link ─────────────────────
  # A link is a pointer body, so a copy of one holds a pointer and no dashboard JSON.
  # It must not inherit the pointer's identity, and it cannot become a sync file by
  # accident — there are no bytes to create a dashboard from.

  @user @in-nextcloud @gesture @ui
  Scenario: Copying a link never creates a second dashboard
    Given a folder mapped as "link" to the Grafana folder "links"
    And a managed "link" dashboard file in that folder
    When I copy the file within the "links" folder
    Then no dashboard is created in Grafana
    And the copy carries no inherited "grafana_uid"

  # ══ COPIED IN GRAFANA ══════════════════════════════════════════════════════════
  # The mirror image: someone duplicates a dashboard in Grafana. The pull sees a new
  # uid in the mapped folder and mirrors it like any other new dashboard — the copy
  # has no special status on the way in, which is the point.

  @grafana @in-grafana @occ @ui @todo
  Scenario: A dashboard duplicated in Grafana arrives as a new file
    Given a managed "sync" dashboard file in the "alpha" folder
    When the dashboard is duplicated in Grafana
    And the "alpha" mapping is pulled
    Then a second file appears in the mapped folder
    And the two files carry different uids
    And the original file is unchanged

  # A duplicate made in Grafana belongs to the mapping it landed in, so it takes THAT
  # mapping's mode — not whatever mode the dashboard it was copied from happened to
  # have. Mode is a property of the mapping, never of the dashboard.
  @grafana @in-grafana @occ @ui @todo
  Scenario: A duplicate made in Grafana takes the mapping's mode, not the original's
    Given a folder mapped as "link" to the Grafana folder "links"
    And a dashboard in the "links" Grafana folder
    When the dashboard is duplicated in Grafana
    And the "links" mapping is pulled
    Then the new file is a "link" pointer

  # ── the pull must never look like a copy ─────────────────────────────────────────
  # The reconciler writes files into mapped folders, which at the event layer is
  # indistinguishable from a user copying one in. If the pull's own writes took the
  # copy path, every pull would mint a duplicate dashboard for every file it wrote —
  # the single worst failure this listener could have.

  @grafana @in-grafana @occ @todo
  Scenario: The pull's own writes are never treated as a copy
    Given a Grafana dashboard in the "alpha" folder
    When the "alpha" mapping is pulled twice
    Then Grafana holds exactly one dashboard for it
    And exactly one file carries its uid

  # ── failure ──────────────────────────────────────────────────────────────────────

  # The copy already exists in Nextcloud by the time we call Grafana, so a failure
  # cannot un-copy it. It must be left as a plain untracked file rather than one
  # carrying a uid that names nothing — and the user has to be told, or they are
  # holding a file that looks managed and is not.
  @user @in-nextcloud @gesture @ui @todo
  Scenario: A copy whose dashboard cannot be created stays a plain file and says so
    Given a managed "sync" dashboard file in the "alpha" folder
    And Grafana will reject the creation
    When I copy the file within the "alpha" folder
    Then the copy carries no "grafana_uid"
    And the failure is reported to the user
    And the original file and its dashboard are unchanged
