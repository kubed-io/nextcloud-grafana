# Notes, decisions and history for this feature: ../AGENTS.md#foldersrestore

Feature: Restoring a folder from the trash
  As a Nextcloud user
  I want restoring a folder to bring its dashboards back predictably
  So that one gesture cannot silently re-mint every dashboard it held

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | demo         |
      | nc folder      | Demo         |
      | mode           | sync         |
      | storage        | admin folder |
    And the Grafana recycle-bin folder is named "nextcloud-trash"

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: what the bin kept decides what a restore can give back ──────────
    # notes: ../AGENTS.md#the-recycle-bin-folder

  @user @in-nextcloud @gesture @ui @recycle-bin @todo
  Scenario: Restore a folder with the recycle bin on
    Given the Grafana recycle bin is on
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" is in the Nextcloud trash
    And those three dashboards are in the "nextcloud-trash" Grafana folder
    When I restore "Demo/Team" from the Nextcloud trash
    Then "Demo/Team" holds the same files it held before
    And the Grafana folder "Team" is under "demo", holding three dashboards
    And each of them kept the uid it had before

    # Nothing was destroyed, so nothing has to be rebuilt: the dashboards come back
    # with the ids, URLs and history they always had.

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Restore a folder with the recycle bin off
    Given the Grafana recycle bin is off
    And the folder "Demo/Team" holding three dashboards
    And "Demo/Team" is in the Nextcloud trash
    And those three dashboards no longer exist in Grafana
    When I restore "Demo/Team" from the Nextcloud trash
    Then "Demo/Team" holds the same files it held before
    And the Grafana folder "Team" is under "demo", holding three dashboards
    And the user is told that three dashboards came back under new uids

    # The dashboards went at trash time, so a restore can only build new ones from
    # the files — and re-minting three identities is worth saying out loud.
