# The manual per-mapping sync controls in admin settings:
#   - "Sync from Grafana" (pull): bring the mapping's folder dashboards into its folder.
#   - "Sync to Grafana"   (push): send the mapping's sync files up to Grafana.
# Both reconcile the mapped folder against the dashboards in that mapping's Grafana
# folder, and both FULLY IGNORE "unmapped" files — those live outside any mapping, so
# a mapping-scoped sync never sees them. Pruning here is therefore mapping-scoped:
# it only ever concerns files/dashboards inside the mapping.
#
# The pull scenarios below are LIVE (Course 2): they drive `occ grafana_sync:pull`
# (the headless twin of the admin button) against the preloaded Grafana folders and
# assert the result over WebDAV. The mapping is admin-owned so CI needs no
# groupfolders app. Push (Course 3, writeback) and the whole-instance root mirror
# (the subfolder course) are still @todo — their engines land later.

Feature: Manual per-mapping sync (Sync from / Sync to Grafana)
  As a Nextcloud admin
  I want the per-mapping sync buttons to reconcile just that mapping
  So that a folder matches its Grafana folder on demand, ignoring everything else

  Background:
    Given the app is connected to Grafana

  Scenario: Sync from Grafana fills the mapped folder, matched by dashboard uid
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "alpha-dash"
    When the admin pulls from Grafana
    Then a file named "Alpha Demo.grafana.json" appears in "alpha-dash"
    And the file "alpha-dash/Alpha Demo.grafana.json" is a "sync" dashboard for uid "nc-alpha-demo"
    And "alpha-dash" holds exactly 1 dashboard file
    # A second pull updates in place by uid — no duplicate, no "(2)" collision file.
    When the admin pulls from Grafana
    Then "alpha-dash" holds exactly 1 dashboard file

  Scenario: Sync from Grafana prunes a file whose dashboard left the folder
    Given an admin-owned mapping from Grafana folder "nc-delta" to Nextcloud folder "delta-dash"
    And a throwaway Grafana dashboard "Ephemeral" with uid "nc-ephemeral" in folder "nc-delta"
    When the admin pulls from Grafana
    Then a file named "Delta Demo.grafana.json" appears in "delta-dash"
    And a file named "Ephemeral.grafana.json" appears in "delta-dash"
    When the Grafana dashboard with uid "nc-ephemeral" is deleted
    And the admin pulls from Grafana
    Then no file named "Ephemeral.grafana.json" remains in "delta-dash"
    And a file named "Delta Demo.grafana.json" appears in "delta-dash"

  # Push (Course 3, writeback): a local edit to a synced file goes back up to its
  # Grafana dashboard on the stable uid — same dashboard, not a new one. Uses the
  # bravo folder so it doesn't mutate the alpha fixture the pull scenarios assert on.
  Scenario: Sync to Grafana pushes a local edit up to its dashboard
    Given an admin-owned mapping from Grafana folder "nc-bravo" to Nextcloud folder "bravo-dash"
    And the admin pulls from Grafana
    When the dashboard file "bravo-dash/Bravo Demo.grafana.json" is edited to title "Bravo Demo (edited by NC)"
    And the admin pushes to Grafana
    Then the Grafana dashboard "nc-bravo-demo" has title "Bravo Demo (edited by NC)"

  # The whole-instance mirror (saga Ch2): the Grafana root "/" mapped to a Nextcloud folder
  # with "Sync subfolders" on. The root encloses every folder, so the pull walks the entire
  # Grafana folder tree — a perfect one-to-one mirror. Lands with the subfolder course.
  @todo
  Scenario: Sync from Grafana on a root mapping with subfolder sync mirrors the whole instance
    Given a folder mapped as "sync" to the Grafana root "/" with "Sync subfolders" on
    And Grafana has dashboards at the root and inside nested folders
    When the admin clicks "Sync from Grafana" for the root mapping
    Then every Grafana folder that holds dashboards appears as a nested Nextcloud subfolder
    And every dashboard appears as a ".grafana.json" file in the matching subfolder
    And the Nextcloud tree is a one-to-one mirror of the Grafana folder structure
