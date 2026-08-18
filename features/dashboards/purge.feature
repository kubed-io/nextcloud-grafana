# Notes, decisions and history for this feature: ../AGENTS.md#dashboardspurge

Feature: Emptying the trash
  As a Nextcloud user
  I want emptying the trash to finish the delete on both sides
  So that a purged file leaves nothing behind, and takes nothing else with it

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

    # ── RULE: the purge finishes whatever the trash gesture started ────────────
    # notes: ../AGENTS.md#the-recycle-bin-folder

  # notes: ../AGENTS.md#emptying-the-trash-for-a-bin-off-file-touches-nothing-in-grafana
  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Empty the trash with the recycle bin off
    Given the Grafana recycle bin is off
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    When I purge it from the trash
    Then the file is gone from the Nextcloud trash
    And the dashboard is still absent from Grafana

    # The dashboard went when the file was trashed, so there is nothing left to do.

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Empty the trash with the recycle bin on
    Given the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    And its dashboard is parked in "nextcloud-trash"
    When I purge it from the trash
    Then that file's dashboard is permanently deleted from Grafana
    And the file is gone from the Nextcloud trash

  # notes: ../AGENTS.md#a-purge-has-to-work-on-both-trashes
  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Empty a Team Folder's trash with the recycle bin on
    Given the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Shared"
    And the file is in the Nextcloud trash
    And its dashboard is parked in "nextcloud-trash"
    When I purge it from the trash
    Then that file's dashboard is permanently deleted from Grafana
    And the file is gone from the Nextcloud trash

    # The leg that reached Grafana never: a Team Folder's trash emits no purge signal,
    # so TeamFolderPurgeListener rides the cache-entry removal instead.

    # ── RULE: a purge reaches exactly one dashboard — the purged file's ───────

  # notes: ../AGENTS.md#a-purge-never-clears-the-bin-folder-wholesale
  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Empty the trash for one file while others are parked
    Given the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    And its dashboard is parked in "nextcloud-trash"
    And "nextcloud-trash" also holds dashboards Nextcloud never managed
    When I purge it from the trash
    Then that file's dashboard is permanently deleted from Grafana
    And the dashboards Nextcloud never managed are still in "nextcloud-trash"

    # ── RULE: the purge deletes what is in the bin, and only that ─────────────
    # notes: ../AGENTS.md#the-purge-deletes-what-is-in-the-bin-and-only-that

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario Outline: Empty the trash when the dashboard is not in the bin
    Given the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    And its dashboard is <where>
    When I purge it from the trash
    Then the file is gone from the Nextcloud trash
    And the dashboard is as it was before the purge

    Examples: two ways to get here, and the purge cannot tell them apart
      | where                                |
      | back in the "Demo" Grafana folder    |
      | gone from Grafana entirely           |

    # ── RULE: emptying the bin in Grafana finishes the delete from that side ──
    # notes: ../AGENTS.md#emptying-the-bin-in-grafana-finishes-the-delete

  @grafana @in-grafana @gesture @ui @recycle-bin
  Scenario: Empty the bin folder in Grafana
    Given the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    And the file is in the Nextcloud trash
    And its dashboard is parked in "nextcloud-trash"
    When someone empties the "nextcloud-trash" folder in Grafana
    Then the file is gone from the Nextcloud trash

    # The purge came from the other side, and it is the same purge: the dashboard is
    # gone for good, so the trashed mirror has nothing left to be restored to.

  # notes: ../AGENTS.md#a-purge-that-cannot-reach-grafana-clears-the-file-without-deleting-anything
  @user @in-nextcloud @gesture @ui @recycle-bin @blocked
  Scenario: Empty the trash while Grafana is unreachable
    Given the Grafana recycle bin is on
    And a trashed sync dashboard file whose dashboard is parked in "nextcloud-trash"
    And Grafana is unreachable
    When I purge it from the trash
    Then no dashboard is deleted in Grafana
    And the file is gone from the Nextcloud trash

    # Cannot prove it is still parked, so do not delete — the reasoning is in the
    # notes above.
