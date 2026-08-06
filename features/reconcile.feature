# Notes, decisions and history for this feature: AGENTS.md#reconcile

Feature: Manual per-mapping sync (Sync from / Sync to Grafana)
  As a Nextcloud admin
  I want the per-mapping sync buttons to reconcile just that mapping
  So that a folder matches its Grafana folder on demand, ignoring everything else

  Background:
    Given the app is connected to Grafana

  # ── ONE BEHAVIOUR, THREE WAYS TO START IT ──────────────────────────────────
  #
  #   actor        | scope
  #   -------------+---------------------
  #   the admin    | one mapping        the card's "Sync now"
  #   the admin    | every mapping      the section's "Sync from Grafana"
  #   the schedule | every mapping      time as the actor
  #
  # Same pre-state, same post-state. The actor and the scope are the only things
  # that differ, so they are COLUMNS rather than three near-identical scenarios.
  # Whether a run is synchronous or queued is a mechanism, and is asserted nowhere.
  # notes: AGENTS.md#a-sync-fills-the-mapped-folder-however-it-was-started

  @admin @in-grafana @occ @ui
  Scenario Outline: A sync fills the mapped folder, however it was started
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "<folder>"
    When <actor> syncs <scope>
    Then a file named "Alpha Demo.grafana.json" appears in "<folder>"
    And the file "<folder>/Alpha Demo.grafana.json" is a "sync" dashboard for uid "nc-alpha-demo"
    And "<folder>" holds exactly 1 dashboard file
    And the file "<folder>/Alpha Demo.grafana.json" carries its Grafana dates

    Examples: every way a sync starts
      | actor        | scope         | folder       |
      | the admin    | one mapping   | one-mapping  |
      | the admin    | every mapping | all-mappings |
      | the schedule | every mapping | on-schedule  |

    # A FOLDER PER ROW, on purpose. All three map the same Grafana folder, and a
    # mapping is unique on the Grafana uid, so each row clears the store first
    # anyway — distinct Nextcloud folders keep one row's leftovers from being read
    # as the next row's result.
    #
    # THE DATES ARE AN END STATE, not a feature of their own: a mirror carries the
    # dashboard's clocks rather than the sync's, and that is true however the sync
    # started. So it is one reusable sentence rather than two `Then`s spelled out
    # here, and any later behaviour that produces a mirror can assert it the same
    # way.
    # notes: AGENTS.md#carries-its-grafana-dates

  @admin @in-grafana @occ @ui
  Scenario: A second sync updates in place, never duplicating
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "alpha-again"
    And the admin pulls from Grafana
    When the admin pulls from Grafana
    Then "alpha-again" holds exactly 1 dashboard file
    # Matched by uid, so no duplicate and no "(2)" collision file. Kept as its own
    # scenario rather than a second `When` inside the outline: it is a different
    # question (identity across runs, not "did the sync work"), and running it
    # three times over would prove the same thing three times.
    # notes: AGENTS.md#a-second-sync-updates-in-place-never-duplicating

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
