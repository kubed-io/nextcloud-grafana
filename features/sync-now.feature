# Notes, decisions and history for this feature: AGENTS.md#sync-now

Feature: Syncing a mapped Grafana folder into Nextcloud
  As an admin who has just mapped a folder
  I want the dashboards already in Grafana to appear in Nextcloud
  So that the mirror starts out true, however the sync was started

  Background:
    Given the app is connected to Grafana

  # ── one behaviour, three ways to start it ──────────────────────────────────
  #
  #   actor        | scope
  #   -------------+---------------------
  #   the admin    | one mapping        the card's "Sync now"
  #   the admin    | every mapping      the section's "Sync from Grafana"
  #   the schedule | every mapping      time as the actor
  #
  # Same pre-state, same post-state. The actor and the scope are the only things
  # that differ, so they are COLUMNS rather than three scenarios. Whether a run is
  # synchronous or queued is a mechanism, and is asserted nowhere.
  #
  # THIS FILE IS THE FIRST SYNC, AND ONLY THAT. Nothing is tracked yet, so whatever
  # is in Grafana is simply a Given. A LATER run only has work to do because
  # something changed in Grafana — and every one of those is a scenario about the
  # change, not about the sync: a dashboard renamed upstream belongs to
  # rename-dashboard.feature, one deleted upstream to delete-dashboard.feature, one
  # moved to another folder upstream to move-dashboard.feature. The sync is how
  # those arrive, not what they are.
  # notes: AGENTS.md#sync-now-scope

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

  # ── what a first sync does with a folder that already holds mirrors ────────

  @admin @in-grafana @occ @ui
  Scenario: A folder that already holds a mirror is filled in place, not doubled
    Given an admin-owned mapping from Grafana folder "nc-alpha" to Nextcloud folder "alpha-again"
    And the admin pulls from Grafana
    When the admin pulls from Grafana
    Then "alpha-again" holds exactly 1 dashboard file
    # THE UID IS WHAT IDENTIFIES A DASHBOARD, not the filename — so a sync over a
    # folder that already has the file updates it rather than leaving an
    # "Alpha Demo (2).grafana.json" beside it. That matters on a first sync over a
    # tree someone already had (a restored backup, a re-mapped folder, a
    # re-enabled app), which is the only reason this is a scenario and not a claim
    # about the reconciler running twice.
    # notes: AGENTS.md#a-folder-that-already-holds-a-mirror-is-filled-in-place-not-doubled

  # ── the whole-instance mirror ──────────────────────────────────────────────

  # The Grafana root "/" mapped to a Nextcloud folder with "Sync subfolders" on. The
  # root encloses every folder, so the sync walks the entire Grafana folder tree — a
  # one-to-one mirror. Lands with the subfolder course.
  @admin @in-grafana @occ @ui @unbuilt
  Scenario: A root mapping with subfolder sync mirrors the whole instance
    Given a folder mapped as "sync" to the Grafana root "/" with "Sync subfolders" on
    And Grafana has dashboards at the root and inside nested folders
    When the admin syncs one mapping
    Then every Grafana folder that holds dashboards appears as a nested Nextcloud subfolder
    And every dashboard appears as a ".grafana.json" file in the matching subfolder
    And the Nextcloud tree is a one-to-one mirror of the Grafana folder structure
