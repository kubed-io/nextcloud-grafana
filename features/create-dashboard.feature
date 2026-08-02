# Creating dashboards from Nextcloud. These scenarios are the human-readable spec
# for the "author in NC, live in Grafana" flow: a .grafana.json written over WebDAV
# into a mapped folder fires NodeWrittenEvent → the create listener → the dashboard
# appears in Grafana. The Grafana side is asserted over its REST API; the NC stamp over
# DAV PROPFIND of nc:metadata-grafana_uid.
#
# CREATING A FOLDER is the other half, and it works the opposite way round: a new
# folder is inert until it is TAGGED, because a mapped folder must stay usable for
# ordinary things. See create-folder.feature — the asymmetry is deliberate and
# explained there.
#
# STATUS: create-on-land is built (CreateService + CreateInGrafanaListener,
# unit-tested and live-verified). @todo means the WebDAV step definitions are
# missing, not the code.

Feature: Create a dashboard from Nextcloud
  As a Nextcloud user
  I want to create Grafana dashboards by making files
  So that I can author dashboards without opening the Grafana UI

  Background:
    Given the app is connected to Grafana

  # ══ CREATED IN NEXTCLOUD ═══════════════════════════════════════════════════════

  @user @in-nextcloud @gesture @ui
  Scenario: New file in a mapped sync folder becomes a real dashboard
    Given a folder mapped as "sync" to the Grafana folder "demo"
    When I create a new ".grafana.json" file in that folder via the Files "New" menu
    Then a matching dashboard is created in Grafana
    And the dashboard is created in the "demo" folder
    And the file is stamped with the dashboard's "grafana_uid"

  @user @in-nextcloud @gesture @ui @todo
  Scenario: A dashboard file created outside any mapped folder stays unmanaged
    Given a folder that is not mapped
    When I create a ".grafana.json" file in that folder
    Then no dashboard is created in Grafana
    And the file has no "grafana_uid" metadata
    And the file is treated as a plain, untracked document, not "unmapped"

  # ── what lands, and what it carries ──────────────────────────────────────────────

  # A file arriving with a uid already in its JSON is a re-adoption, not a create —
  # a dashboard exported from Grafana and dropped back in, or a file restored from a
  # backup. Minting a second dashboard for it would fork the two copies apart.
  @user @in-nextcloud @gesture @ui @todo
  Scenario: A file that already carries a uid re-adopts its dashboard instead of creating one
    Given a folder mapped as "sync" to the Grafana folder "demo"
    And a dashboard "uid-A" exists in the "demo" Grafana folder
    When I place a ".grafana.json" file whose JSON carries uid "uid-A" in that folder
    Then no second dashboard is created in Grafana
    And the file is stamped with "uid-A"

  # …and one carrying a uid that names nothing is a create, not a failure. The uid is
  # stale — from a deleted dashboard or another instance — and the file's content is
  # the thing worth keeping.
  @user @in-nextcloud @gesture @ui @todo
  Scenario: A file carrying a uid that no longer exists is created fresh
    Given a folder mapped as "sync" to the Grafana folder "demo"
    When I place a ".grafana.json" file whose JSON carries a uid no dashboard uses in that folder
    Then a dashboard is created in Grafana from the file's JSON
    And the file is stamped with the uid it was given

  # The dashboard's name comes from the filename, because that is what the user just
  # typed. A body with no title must not produce an untitled dashboard.
  @user @in-nextcloud @gesture @ui
  Scenario: A new dashboard is named after the file
    Given a folder mapped as "sync" to the Grafana folder "demo"
    When I create "CPU Load.grafana.json" in that folder
    Then a dashboard named "CPU Load" is created in Grafana

  @user @in-nextcloud @gesture @ui @todo
  Scenario: A file that is not valid JSON creates nothing and says so
    Given a folder mapped as "sync" to the Grafana folder "demo"
    When I create a ".grafana.json" file in that folder whose contents are not valid JSON
    Then no dashboard is created in Grafana
    And the failure is reported to the user
    And the file is left where the user put it

  # ── a link mapping authors nothing ───────────────────────────────────────────────
  # A link folder is a read-only projection of Grafana. A file appearing in one is a
  # local file, not an instruction to create a dashboard.

  @user @in-nextcloud @gesture @ui
  Scenario: A new file in a link-mapped folder creates no dashboard
    Given a folder mapped as "link" to the Grafana folder "links"
    When I create a ".grafana.json" file in that folder
    Then no dashboard is created in Grafana
    And the file has no "grafana_uid" metadata

  # ── failure ──────────────────────────────────────────────────────────────────────

  # The file exists in Nextcloud before Grafana is called, so a failed create cannot
  # be rolled back into "nothing happened". Leaving it unstamped is what lets a later
  # save or pull retry it, rather than leaving a file that claims a dashboard it does
  # not have.
  @user @in-nextcloud @gesture @ui @todo
  Scenario: A failed creation leaves an unstamped file, not a half-managed one
    Given a folder mapped as "sync" to the Grafana folder "demo"
    And Grafana will reject the creation
    When I create a ".grafana.json" file in that folder
    Then the file has no "grafana_uid" metadata
    And the failure is reported to the user

  # ══ CREATED IN GRAFANA ═════════════════════════════════════════════════════════
  # The other direction, and the one that runs first in practice: dashboards already
  # exist in Grafana when a mapping is made. A pull is how they arrive.

  @grafana @in-grafana @occ @ui @todo
  Scenario: A dashboard created in Grafana arrives as a file
    Given a folder mapped as "sync" to the Grafana folder "demo"
    When a dashboard is created in the "demo" Grafana folder
    And the "demo" mapping is pulled
    Then a matching ".grafana.json" file appears in the mapped folder
    And it is stamped with the dashboard's uid

  @grafana @in-grafana @occ @ui @todo
  Scenario: A dashboard created in a link-mapped Grafana folder arrives as a pointer
    Given a folder mapped as "link" to the Grafana folder "links"
    When a dashboard is created in the "links" Grafana folder
    And the "links" mapping is pulled
    Then a pointer file appears in the mapped folder
    And it holds no dashboard JSON

  # Two dashboards can share a title; a filename cannot be shared. The collision
  # suffix is what keeps one dashboard to one file when that happens.
  @grafana @in-grafana @occ @ui @todo
  Scenario: Two dashboards with the same title arrive as two distinct files
    Given a folder mapped as "sync" to the Grafana folder "demo"
    When two dashboards both named "CPU Load" are created in the "demo" Grafana folder
    And the "demo" mapping is pulled
    Then two files exist in the mapped folder
    And each carries a different uid
