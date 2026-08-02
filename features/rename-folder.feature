# Renaming a FOLDER — the folder half of rename-dashboard.feature.
#
# Two very different folders can be renamed, and conflating them is the trap this
# file exists to prevent:
#
#   1. A MAPPED folder — the Nextcloud folder an admin bound to a Grafana folder.
#   2. A SUBFOLDER inside one — an ordinary folder, or a mirrored Grafana folder
#      if it carries the `grafana` tag (create-folder.feature).
#
# ── THE FINDING: A MAPPING IS STORED AS A PATH STRING ────────────────────────────
#
# `Mapping::$ncFolder` is a path (`nc_folder`), and `MappingService::resolveForPath`
# decides membership with `str_starts_with($relative, $folder . '/')`. It is NOT a
# Nextcloud file id.
#
# So renaming a mapped folder does not "move" the mapping — it silently ORPHANS it.
# The mapping still names a path that no longer exists, every file inside the
# renamed folder resolves to no mapping, and nothing anywhere says so. The dashboards
# are untouched in Grafana, so nothing is destroyed; the connection simply stops,
# quietly, and the next pull re-creates the whole folder at the old path.
#
# That is the opposite of the promise the rest of the app makes. A dashboard file
# survives renaming, moving and restoring because it is tracked by a stable **uid**
# in its metadata rather than by its name — and the mapping it belongs to is tracked
# by a **string**. The one identifier that is not stable is the one the whole mapping
# rests on.
#
# `admin-mapping.feature` compounds it: a mapping's folders cannot be edited after
# creation, so an admin who renames the folder cannot repoint the mapping — they
# must remove it and add it back.
#
# ── STATUS ───────────────────────────────────────────────────────────────────────
#
# @unbuilt throughout. There is no folder-rename handling of any kind: no listener
# watches folder renames, and `GrafanaClient` has no renameFolder. The first
# scenario documents what happens TODAY (the orphaning) so the gap is written down
# rather than rediscovered; the rest specify what should happen instead.

Feature: Renaming a folder
  As a Nextcloud user or admin
  I want renaming a folder to either follow through or refuse
  So that a rename never silently disconnects dashboards from their mapping

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ── the mapped folder itself ─────────────────────────────────────────────────────

  # WHAT HAPPENS TODAY, recorded so it is a known defect rather than a surprise.
  # Nothing is lost — no dashboard is deleted — but the mapping stops matching and
  # the user is told nothing.
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Renaming a mapped folder silently orphans its mapping
    Given a managed "sync" dashboard file in the "alpha" folder
    When I rename the mapped Nextcloud folder
    Then the mapping no longer matches any folder
    And the file inside it resolves to no mapping
    And nothing warns the user that the connection is broken

  # What it should do instead. Following the rename keeps the promise the file-level
  # metadata already makes, and needs the mapping to be keyed by something stable —
  # the folder's Nextcloud file id — rather than by its path.
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Renaming a mapped folder keeps the mapping pointing at it
    Given a managed "sync" dashboard file in the "alpha" folder
    When I rename the mapped Nextcloud folder
    Then the mapping still owns that folder under its new name
    And the file inside it is still managed "sync" under the mapping
    And nothing changes in Grafana

  # The alternative, if following is not wanted: refuse the gesture rather than let
  # it half-happen. Either answer is defensible; silence is not.
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A mapped folder that cannot follow a rename refuses it with a reason
    Given a managed "sync" dashboard file in the "alpha" folder
    When I try to rename the mapped Nextcloud folder
    Then the rename is refused with a message naming the mapping
    And the folder keeps its name

  # ── a mirrored subfolder ─────────────────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Renaming a mirrored subfolder renames the Grafana subfolder
    Given a mirrored Grafana folder "Team A" under the "alpha" folder, holding a dashboard
    When I rename the subfolder to "Team B"
    Then the Grafana subfolder is renamed to "Team B"
    And the dashboards inside keep their uids

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Renaming an untagged subfolder never reaches Grafana
    Given an untagged subfolder of "alpha" holding a managed "sync" dashboard file
    When I rename the subfolder
    Then Grafana is not contacted
    And the dashboard stays bound to the "alpha" mapping

  # A failed far-side rename must not roll back the local one. The user's gesture in
  # their own file tree is theirs; the app reports the divergence and lets the next
  # reconcile settle it. Same rule as rename-dashboard.feature.
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A failed subfolder rename leaves the local rename standing
    Given a mirrored Grafana folder under the "alpha" folder, holding a dashboard
    And Grafana will reject the folder rename
    When I rename the subfolder
    Then the Nextcloud folder keeps its new name
    And the failure is reported to the user

  # ── renamed on the Grafana side ──────────────────────────────────────────────────

  # A mapping names a Grafana folder by **uid**, not by title, so a title change in
  # Grafana does not break it. Whether the Nextcloud folder should follow is the open
  # question — it is the user's own file tree, and the mapping was created against a
  # folder the admin named.
  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: Renaming the mapped folder in Grafana does not break the mapping
    Given a managed "sync" dashboard file in the "alpha" folder
    When the "alpha" folder is renamed in Grafana
    And the mapping is pulled
    Then the mapping still resolves to that Grafana folder by uid
    And the dashboards inside are unaffected

  @grafana @in-grafana @occ @ui @unbuilt
  Scenario: Renaming a mirrored Grafana subfolder renames the Nextcloud subfolder
    Given a mirrored Grafana folder "Team A" under the "alpha" folder, holding a dashboard
    When the Grafana subfolder is renamed to "Team B"
    And the mapping is pulled
    Then the Nextcloud subfolder is renamed to "Team B"
    And the dashboard file inside keeps its uid and its place
