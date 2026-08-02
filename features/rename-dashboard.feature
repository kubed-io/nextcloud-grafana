# Three-way name agreement in sync mode: filename stem ⇄ JSON "title" ⇄ Grafana title.
#
# ── THE THREE SURFACES, AND WHY THERE ARE THREE ──────────────────────────────────
#
#   1. the FILENAME       — what the user sees and types in the Files app
#   2. the JSON `title`   — what is inside the file, and what a push sends
#   3. the Grafana title  — what the dashboard is called on the far side
#
# Any one of them can be changed first, and the other two must follow. Two of the
# three are in Nextcloud, which is why a rename is not simply "push the new name":
# editing the JSON has to rename the FILE too, and renaming the file has to rewrite
# the JSON, before either reaches Grafana.
#
# ── THE UID IS THE THREAD, NOT THE NAME ──────────────────────────────────────────
#
# The link between a file and its dashboard is `grafana_uid` in the file's metadata.
# No rename, on any surface, can break it — which is the whole reason names are free
# to change at all. Every scenario here is really a restatement of that.
#
# ── RENAMING A FOLDER IS A DIFFERENT PROBLEM ─────────────────────────────────────
#
# It has its own file (rename-folder.feature) and its own defect: a mapping is
# stored as a PATH string, so renaming a mapped folder silently orphans it. Nothing
# on this page has that problem, because nothing on this page is identified by name.
#
# ── STATUS ───────────────────────────────────────────────────────────────────────
#
# The Nextcloud-side legs are cooked (Course 5) and now RUN IN CI — NameSyncListener
# enqueues, ReconcileNameJob does the file-locked write/rename, and the writeback
# carries the title to Grafana.
#
# The deferral is not an optimisation: during a rename the file is LOCKED, so a
# synchronous putContent throws. That is why every rename step drains
# PushDashboardJob and then ReconcileNameJob before asserting — a test that checked
# immediately after the MOVE would be racing a job that had not started.
#
# The Grafana-side legs ride the ordinary pull and are still @todo. The refusals are
# @unbuilt: NameSyncListener bails on an empty stem rather than reporting anything,
# so nothing tells the user their rename went nowhere.

Feature: Renaming keeps file, JSON, and Grafana in agreement
  As a Nextcloud user
  I want renames to propagate everywhere
  So that the file name, its JSON name, and the Grafana dashboard name never drift

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ══ RENAMED IN NEXTCLOUD ═══════════════════════════════════════════════════════

  @user @in-nextcloud @gesture @ui
  Scenario: Renaming the file updates the backend JSON name and Grafana
    Given a managed "sync" dashboard file named "Old Name.grafana.json"
    When I rename the file to "New Name.grafana.json"
    Then the JSON "title" field inside the file becomes "New Name"
    And the dashboard is renamed to "New Name" in Grafana

  @user @in-nextcloud @gesture @ui
  Scenario: Editing the JSON name renames the file and updates Grafana
    Given a managed "sync" dashboard file
    When I edit the file and change the JSON "title" field to "Renamed In JSON"
    Then the file is renamed to "Renamed In JSON.grafana.json"
    And the dashboard is renamed to "Renamed In JSON" in Grafana

  @user @in-nextcloud @gesture @ui
  Scenario: Renaming never breaks the link
    Given a managed "sync" dashboard file with a known "grafana_uid"
    When the file is renamed by any of the above means
    Then the "grafana_uid" metadata is unchanged

  # A rename must not become a move. The file stays exactly where the user filed it,
  # including in a subfolder the reconciler would never have chosen.
  @user @in-nextcloud @gesture @ui @todo
  Scenario: A rename never relocates the file
    Given a managed "sync" dashboard file the user moved into a subfolder of "alpha"
    When I rename the file
    Then the file is still in that subfolder

  # A link is a read-only pointer with no dashboard JSON to rewrite and nothing to
  # push. Renaming the pointer file is a local act; the dashboard keeps its name and
  # the next pull re-derives the filename from Grafana.
  @user @in-nextcloud @gesture @ui
  Scenario: Renaming a link never renames the dashboard
    Given a folder mapped as "link" to the Grafana folder "links"
    And a managed "link" dashboard file in that folder
    When I rename the file
    Then the dashboard keeps its name in Grafana

  @user @in-nextcloud @gesture @ui
  Scenario: Renaming an untracked ".grafana.json" file is not a failure
    Given an untracked ".grafana.json" file outside any mapping
    When I rename the file
    Then Grafana is not contacted
    And the rename succeeds

  # ══ RENAMED IN GRAFANA ═════════════════════════════════════════════════════════
  # The mirror image: the title changes on the far side and the pull carries it back.
  # Mode-agnostic — a link's filename follows a title change exactly as a sync file's
  # does, because in both cases the name is derived from Grafana, not pushed to it.

  @grafana @in-grafana @occ @ui @todo
  Scenario: Renaming a dashboard in Grafana renames the mirrored file
    Given a managed "sync" dashboard file named "Old Name.grafana.json"
    When the dashboard is renamed to "New Name" in Grafana
    And the "alpha" mapping is pulled
    Then the file is renamed to "New Name.grafana.json"
    And the file's "grafana_uid" is unchanged

  @grafana @in-grafana @occ @ui @todo
  Scenario: A rename in Grafana reaches a link the same way
    Given a folder mapped as "link" to the Grafana folder "links"
    And a managed "link" dashboard file in that folder
    When the dashboard is renamed in Grafana
    And the "links" mapping is pulled
    Then the pointer file is renamed to match
    And the pointer's title reflects the new name

  # Two dashboards can share a title in Grafana; two files in one folder cannot share
  # a name. The collision suffix is what keeps one dashboard to one file.
  @grafana @in-grafana @occ @ui @todo
  Scenario: A rename that collides with an existing filename is suffixed, not overwritten
    Given two managed "sync" dashboard files in the "alpha" folder
    When one dashboard is renamed in Grafana to the other's title
    And the "alpha" mapping is pulled
    Then both files still exist
    And each still carries its own uid

  # A dashboard with no usable title must not produce ".grafana.json" with an empty
  # stem. FilenameCodec falls back to the uid — an ugly name is recoverable, a file
  # the app cannot round-trip is not.
  @grafana @in-grafana @occ @ui @todo
  Scenario: The app never invents a substitute name
    Given a dashboard in the "alpha" Grafana folder whose title is empty
    When the "alpha" mapping is pulled
    Then the file is named after the dashboard's uid
    And the file is a valid ".grafana.json"

  # ══ REFUSALS AND FAILURES ══════════════════════════════════════════════════════

  # NameSyncListener bails on an empty stem, so the JSON and Grafana keep the old
  # name while the file carries the new one — a silent three-way disagreement, which
  # is the one outcome this whole feature exists to prevent. @unbuilt: bailing is not
  # refusing, and nothing tells the user.
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A rename to an empty or whitespace-only name is refused
    Given a managed "sync" dashboard file
    When I try to rename the file to a whitespace-only name
    Then the rename is refused with a message
    And the file, its JSON title, and the dashboard still agree

  # The local rename is the user's own gesture in their own file tree. A far-side
  # failure must not reach back and undo it — report the divergence and let the next
  # reconcile settle it. This is the deliberate asymmetry with delete, where a failed
  # far-side call DOES abort the local gesture: a rename is recoverable and a delete
  # is not.
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A failed propagation never reverts the local rename
    Given a managed "sync" dashboard file
    And Grafana will reject the rename
    When I rename the file
    Then the file keeps its new name
    And the failure is reported to the user
