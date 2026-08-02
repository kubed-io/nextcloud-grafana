# Reserved tags — the optional, per-dashboard EXCLUDE switches. TWO ORIGINS, and
# conflating them is a trap (saga Ch2 Fork H). "Tag" means two entirely different
# systems here:
#
#   • a NEXTCLOUD tag — Nextcloud's own collaborative/system tag, on a *file*; and
#   • a GRAFANA tag — a string in a dashboard's own `tags` array, on a *dashboard*.
#
# The rule: **you tag with the name of the system you're talking TO.**
#
#   grafana:ignore    — origin NEXTCLOUD. A Nextcloud tag the admin hand-sets on a
#                       `.grafana.json` FILE (the app's own `grafana:*` namespace,
#                       alongside the automatic `grafana:sync`/`grafana:link` mode
#                       pills). Read on NC tag events → the file's mode becomes
#                       `ignored`: it stays put, keeps its uid, sync skips it, and the
#                       live Grafana dashboard is untouched. Never written to Grafana.
#
#   nextcloud:ignore  — origin GRAFANA. A tag the Grafana admin sets on the DASHBOARD
#                       in Grafana (`nextcloud:` = "addressed to Nextcloud"). Read at
#                       PULL time → that dashboard is never brought into Nextcloud, no
#                       file is created, even inside a mapped folder. Never written by
#                       the app.
#
# One is Nextcloud saying "don't sync this file"; the other is Grafana saying "don't
# pull this dashboard." Both are optional escape hatches — the mapping does everything
# on its own. (Symmetric with the n8n master: `n8n:ignore` on the NC file,
# `nextcloud:ignore` on the workflow — so the shared base gets one two-axis model.)
#
# NO ARCHIVE (saga Ch2 Round 2): the master archives an ignored resource. Our
# ingredient has no reachable archive, so `ignored` just means "skip it in sync" — the
# dashboard is left fully LIVE in Grafana (fork F, leaning).
#
# DESIGN, NOT WIRED: this feature is @todo — CI skips it — until the pull engine +
# reserved-tag resolver are cooked.

# ── STATUS: THE SEAM IS LIVE, THE ORIGIN IS NOT ──────────────────────────────────
#
# `SyncService::pullOne` already skips any file whose mode is `ignored`, and the
# comment there is explicit: *"No origin sets `ignored` in this course yet; the seam
# is here for the reserved-tag course."* So the app can HONOUR the exclusion and has
# no way to ACQUIRE it — nothing reads a reserved tag, on either side.
#
# That split is why these are @unbuilt rather than @todo. The one behaviour that is
# genuinely built (the pull leaving an already-ignored file alone) is stated as its
# own scenario at the bottom, tagged @todo, so the work queue tells the truth.
#
# NOTE ON THE TWO TAG NAMESPACES. `grafana:sync`/`grafana:link`/`grafana:unmapped`
# are MODE pills the app writes and owns (OwnershipTags) — they are built. The
# `grafana:ignore` marker here is user-set and read-only from the app's side, and
# `nextcloud:ignore` is its Grafana-side counterpart. Content tags are a third thing
# again, and none of that is built either (tag-sync.feature).

Feature: Reserved tags exclude individual dashboards — from either side
  As an admin
  I want a Grafana-side and a Nextcloud-side exclude tag
  So that one dashboard can be left out from whichever side owns the decision

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "flows"

  @grafana @in-grafana @occ @unbuilt
  Scenario: With no reserved tag, a dashboard takes the mapping's mode
    Given Grafana has a dashboard in the "flows" folder with no reserved tag
    When the "flows" mapping is pulled
    Then that dashboard's file is in "sync" mode, the mapping mode

  # Grafana-origin exclude: the tag lives on the DASHBOARD in Grafana.
  @grafana @in-grafana @occ @unbuilt
  Scenario: nextcloud:ignore on a Grafana dashboard is never pulled
    Given Grafana has a dashboard in the "flows" folder tagged "nextcloud:ignore" in Grafana
    When the "flows" mapping is pulled
    Then that dashboard is not pulled into Nextcloud
    And no file is created for it

  # Nextcloud-origin exclude: the tag lives on the FILE in Nextcloud.
  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: grafana:ignore on a file already in a mapped folder gives it "ignored" mode
    Given a managed "sync" dashboard file in the "flows" folder
    When the admin adds the Nextcloud tag "grafana:ignore" to the file
    Then the file's mode becomes "ignored"
    And the file stays in the mapped folder and keeps its "grafana_uid"
    And the dashboard is left fully live in Grafana
    And subsequent pulls/pushes for "flows" skip it

  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: Removing grafana:ignore returns the file to the mapping's mode
    Given a managed "sync" dashboard file in the "flows" folder
    And the file has the Nextcloud tag "grafana:ignore"
    When I remove the "grafana:ignore" tag
    Then the file's mode becomes "sync"

  # The two origins are independent — neither is written across the boundary.
  @admin @in-nextcloud @occ @unbuilt
  Scenario: The app never writes reserved tags onto Grafana dashboards
    Given Grafana has a dashboard in the "flows" folder with no reserved tag
    When the "flows" mapping is pulled
    Then the dashboard in Grafana still carries only its original tags
    And the app has not added any "grafana:sync", "grafana:link", "grafana:ignore", or "nextcloud:ignore" tag to it

  # The one leg that IS built: the pull leaves an already-ignored file strictly
  # alone rather than writing a second, collision-suffixed copy beside it. Nothing
  # sets the mode yet, so the arrangement has to stamp it directly.
  @grafana @in-grafana @occ @todo
  Scenario: A file already marked ignored is left alone by the pull
    Given a managed dashboard file in a mapped folder whose mode is "ignored"
    When the mapping is pulled
    Then the file is unchanged
    And no second file is created for its dashboard
