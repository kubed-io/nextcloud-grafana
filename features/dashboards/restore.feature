# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsrestore

Feature: Restoring a dashboard file from the trash
  As a Nextcloud user
  I want a restore to undo exactly what the trashing did
  So that changing my mind costs nothing on either side

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Demo         |
      | nc folder      | Demo         |
      | mode           | sync         |
      | storage        | admin folder |
    And a mapping with the following values:
      | grafana folder | Shared      |
      | nc folder      | Shared      |
      | mode           | sync        |
      | storage        | team folder |
      | groups         | admin       |
    And a folder "Scratch" that is not mapped
    And the Grafana recycle-bin folder is named "nextcloud-trash"

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: a restore is the trashing, undone ──────────────────────────────
    # notes: ../AGENTS.md#the-recycle-bin-folder

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Restore a file whose dashboard is parked
    Given the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    And its dashboard is parked in "nextcloud-trash"
    When I restore it from the trash
    Then the dashboard is in the "Demo" Grafana folder
    And the file holds:
      | grafana_uid     | the uid it had before it was trashed |
      | grafana_mapping | the mapping's id                     |
      | grafana_mode    | the mapping's mode                   |

  # notes: ../AGENTS.md#restoring-a-sync-file-re-creates-the-dashboard-with-a-new-id-bin-off
  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Restore a file whose dashboard was deleted at trash time
    Given the Grafana recycle bin is off
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    When I restore it from the trash
    Then a matching dashboard is created in Grafana
    And the file holds:
      | grafana_uid     | its own, not the one it arrived with |
      | grafana_mapping | the mapping's id                     |
      | grafana_mode    | the mapping's mode                   |

  # notes: ../AGENTS.md#a-purge-has-to-work-on-both-trashes
  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Restore a file from a Team Folder's trash
    Given the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Shared"
    And the file is in the Nextcloud trash
    And its dashboard is parked in "nextcloud-trash"
    When I restore it from the trash
    Then the file is back in "Shared"
    And the dashboard is in the "Shared" Grafana folder

    # ── RULE: the world may have moved while the file sat in the trash ───────

  # notes: ../AGENTS.md#restoring-a-parked-file-whose-dashboard-was-deleted-in-grafana-re-creates-it
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restore a file whose parked dashboard has since been deleted
    Given the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    And its dashboard no longer exists in Grafana
    When I restore it from the trash
    Then a matching dashboard is created in Grafana
    And the file holds:
      | grafana_uid     | its own, not the one it arrived with |
      | grafana_mapping | the mapping's id                     |

  # notes: ../AGENTS.md#restoring-a-file-whose-dashboard-is-already-back-in-place-is-not-a-conflict
  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restore a file whose dashboard is already back in place
    Given the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    And its dashboard is back in the "Demo" Grafana folder
    When I restore it from the trash
    Then the dashboard is in the "Demo" Grafana folder
    And there is exactly one file for that dashboard

  # notes: ../AGENTS.md#restoring-into-a-mapping-that-no-longer-exists-leaves-a-plain-file
  @user @in-nextcloud @gesture @ui @todo
  Scenario: Restore a file whose mapping has since been removed
    Given a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    And the mapping has since been removed
    When I restore it from the trash
    Then the file is back in "Demo"
    And the file holds no Grafana metadata at all

    # ── RULE: a dashboard coming back in Grafana brings its file with it ──────

  # notes: ../AGENTS.md#moving-a-dashboard-out-of-the-bin-in-grafana-brings-its-file-back-out-of-the-trash
  @grafana @in-grafana @gesture @ui @recycle-bin @unbuilt
  Scenario: Move a dashboard out of the bin in Grafana
    Given the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    And its dashboard is parked in "nextcloud-trash"
    When someone moves the dashboard into the "Demo" Grafana folder
    Then the file is back in "Demo"
    And there is exactly one file for that dashboard
    And the file holds:
      | grafana_uid     | the uid it had before it was trashed |
      | grafana_mapping | the mapping's id                     |

    # The trashed file is restored rather than a second one being written, because
    # the uid names the file that already exists.

  # notes: ../AGENTS.md#a-bin-off-restore-cannot-preserve-the-old-dashboards-url-or-history
  @user @in-nextcloud @gesture @ui @recycle-bin @decision
  Scenario: A bin-off restore gives the dashboard a new URL and an empty history
    Given the Grafana recycle bin is off
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    When I restore it from the trash
    Then the dashboard's URL in Grafana is the new uid's
    And its version history starts at the restore

    # Grafana has no undelete. With the bin off the dashboard was destroyed at trash
    # time, so a restore can only build a new one from the file's body.
