# Notes, decisions and history for this feature: AGENTS.md#restore-dashboard

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

  # notes: AGENTS.md#restoring-into-a-mapping-that-no-longer-exists-leaves-a-plain-file
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

  # notes: AGENTS.md#a-bin-off-restore-cannot-preserve-the-old-dashboards-url-or-history
  @user @in-nextcloud @gesture @ui @recycle-bin @decision
  Scenario: A bin-off restore cannot preserve the old dashboard's URL or history
    Given the Grafana recycle-bin folder is off
    And a trashed sync dashboard file whose dashboard is already deleted
    When I restore it from the trash
    Then the dashboard's previous URL no longer resolves in Grafana
    And the restored dashboard has no version history from before the delete
    And nothing in Grafana that referenced the old uid points at it

  # notes: AGENTS.md#moving-a-dashboard-out-of-the-bin-in-grafana-brings-its-file-back-out-of-the-trash

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

  # notes: AGENTS.md#a-dashboard-reappearing-in-grafana-never-empties-the-nextcloud-trash

  @user @in-nextcloud @gesture @ui @decision
  Scenario: A dashboard reappearing in Grafana never empties the Nextcloud trash
    Given a trashed sync dashboard file
    When the dashboard exists in Grafana again
    And the "alpha" mapping is pulled
    Then the file is still in the Nextcloud trash
    And the user is the only one who can restore it
