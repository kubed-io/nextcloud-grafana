# Notes, decisions and history for this feature: AGENTS.md#reconcile

Feature: Manual per-mapping sync (Sync from / Sync to Grafana)
  As a Nextcloud admin
  I want the per-mapping sync buttons to reconcile just that mapping
  So that a folder matches its Grafana folder on demand, ignoring everything else

  Background:
    Given the app is connected to Grafana

  @admin @in-grafana @occ @ui
  Scenario: Sync from Grafana fills the mapped folder, matched by dashboard uid
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "alpha-dash"
    When the admin pulls from Grafana
    Then a file named "Alpha Demo.grafana.json" appears in "alpha-dash"
    And the file "alpha-dash/Alpha Demo.grafana.json" is a "sync" dashboard for uid "nc-alpha-demo"
    And "alpha-dash" holds exactly 1 dashboard file
    # The mirror's two clocks are END STATES of this behaviour, not features of their
    # own — a modification time is the shared result of edit/move/copy/rename, each
    # already owned elsewhere. Here the mirror comes into existence AND takes its
    # content from Grafana, so both clocks are answerable in one place.
    And the file "alpha-dash/Alpha Demo.grafana.json" is dated when its dashboard changed in Grafana
    And the file "alpha-dash/Alpha Demo.grafana.json" was created when its dashboard was created in Grafana
    # A second pull updates in place by uid — no duplicate, no "(2)" collision file.
    When the admin pulls from Grafana
    Then "alpha-dash" holds exactly 1 dashboard file

  @admin @in-grafana @occ @ui
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

  # notes: AGENTS.md#sync-from-grafana-with-nothing-changed-rewrites-nothing-and-says-so
  @admin @occ @ui
  Scenario: Sync from Grafana with nothing changed rewrites nothing and says so
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "alpha-quiet"
    And the admin pulls from Grafana
    And the mirrors in "alpha-quiet" are noted
    When the admin pulls from Grafana
    Then the run reports every dashboard as unchanged
    And no file in "alpha-quiet" was rewritten

  # Push (Course 3, writeback): a local edit to a synced file goes back up to its
  # Grafana dashboard on the stable uid — same dashboard, not a new one. Uses the
  # bravo folder so it doesn't mutate the alpha fixture the pull scenarios assert on.
  @admin @in-nextcloud @occ @ui
  Scenario: Sync to Grafana pushes a local edit up to its dashboard
    Given an admin-owned mapping from Grafana folder "nc-bravo" to Nextcloud folder "bravo-dash"
    And the admin pulls from Grafana
    When the dashboard file "bravo-dash/Bravo Demo.grafana.json" is edited to title "Bravo Demo (edited by NC)"
    And the admin pushes to Grafana
    Then the Grafana dashboard "nc-bravo-demo" has title "Bravo Demo (edited by NC)"

  # The whole-instance mirror (saga Ch2): the Grafana root "/" mapped to a Nextcloud folder
  # with "Sync subfolders" on. The root encloses every folder, so the pull walks the entire
  # Grafana folder tree — a perfect one-to-one mirror. Lands with the subfolder course.
  @admin @in-grafana @occ @ui @unbuilt
  Scenario: Sync from Grafana on a root mapping with subfolder sync mirrors the whole instance
    Given a folder mapped as "sync" to the Grafana root "/" with "Sync subfolders" on
    And Grafana has dashboards at the root and inside nested folders
    When the admin clicks "Sync from Grafana" for the root mapping
    Then every Grafana folder that holds dashboards appears as a nested Nextcloud subfolder
    And every dashboard appears as a ".grafana.json" file in the matching subfolder
    And the Nextcloud tree is a one-to-one mirror of the Grafana folder structure
