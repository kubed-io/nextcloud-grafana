# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsdelete

Feature: Trashing a dashboard file
  As a Nextcloud user
  I want the trash to mean the same thing on both sides
  So that removing a file never loses a dashboard and never silently desyncs

  Background:
    Given the app is connected to Grafana
    And a mapping with the following values:
      | grafana folder | Demo         |
      | nc folder      | Demo         |
      | mode           | sync         |
      | storage        | admin folder |
    And a mapping with the following values:
      | grafana folder | links        |
      | nc folder      | Pointers     |
      | mode           | link         |
      | storage        | admin folder |
    And a folder "Scratch" that is not mapped
    And the Grafana recycle-bin folder is named "nextcloud-trash"

  # notes: ../AGENTS.md#the-mappings-in-the-background

    # ── RULE: the recycle bin decides what trashing does to the dashboard ──────
    # notes: ../AGENTS.md#the-recycle-bin-folder

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Trash a dashboard with the recycle bin off
    Given the Grafana recycle bin is off
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    When I move it to the trash
    Then the dashboard no longer exists in Grafana
    And the file is recoverable from the Nextcloud trash

  @user @in-nextcloud @gesture @ui @recycle-bin
  Scenario: Trash a dashboard with the recycle bin on
    Given the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    When I move it to the trash
    Then the dashboard is in the "nextcloud-trash" Grafana folder
    And the file is recoverable from the Nextcloud trash

    # ── RULE: a link is read-only, so it is not deleted from this side ─────────

  # notes: ../AGENTS.md#a-link-cannot-be-deleted-from-nextcloud
  @user @in-nextcloud @gesture @ui
  Scenario: Trash a link
    Given a dashboard file named "Fleet Health.grafana" in "Pointers"
    When I try to move it to the trash
    Then the trash is refused with a message
    And the file stays in "Pointers"
    And the dashboard still exists in Grafana

    # @unbuilt — THIS IS THE SPEC, AND THE APP DOES THE OPPOSITE TODAY: it trashes
    # the file and severs the tie. A link is Grafana's copy to remove, and deleting
    # the pointer only makes the folder disagree with the Grafana folder it mirrors.

  @user @in-nextcloud @gesture @ui
  Scenario: Trash an unmapped dashboard file
    Given a dashboard file named "Fleet Health.grafana" in "Scratch"
    When I move it to the trash
    Then the file is recoverable from the Nextcloud trash
    And it still holds no Grafana metadata

    # ── RULE: a Grafana folder outlives its last dashboard ────────────────────
    # notes: ../AGENTS.md#a-grafana-folder-outlives-its-last-dashboard

  @user @in-nextcloud @gesture @ui
  Scenario: Trash the only dashboard in a folder
    Given the folder "Demo/Team" holding one dashboard "CPU Load"
    When I move "Demo/Team/CPU Load.grafana" to the trash
    Then the Grafana folder "Team" is still under "Demo"
    And it holds no dashboards
    And "Demo/Team" holds:
      | grafana_folder_uid | the uid it had before the delete |

    # Emptying is not deleting. A dashboard made here later lands in the folder both
    # sides already agree on, instead of minting a second one beside it.

    # ── RULE: the Grafana bin only works while the Nextcloud trash does ────────

  # notes: ../AGENTS.md#the-grafana-bin-needs-the-nextcloud-trash
  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Delete a dashboard with the Nextcloud trash disabled
    Given the Nextcloud trash is disabled
    And the Grafana recycle bin is on
    And a dashboard file named "Fleet Health.grafana" in "Demo"
    When I delete it
    Then the dashboard no longer exists in Grafana

    # ── RULE: a dashboard deleted in Grafana takes its file with it ────────────

  # notes: ../AGENTS.md#a-dashboard-deleted-in-grafana-loses-its-mirror-in-nextcloud
  @grafana @in-grafana @gesture @ui
  Scenario: Delete a dashboard in Grafana
    Given a dashboard file named "Fleet Health.grafana" in "Demo"
    When someone deletes the dashboard in Grafana
    Then the file is gone from "Demo"
    And the file is recoverable from the Nextcloud trash

    # The Grafana bin setting has no say here: it governs what Nextcloud does to
    # Grafana, and this comes the other way.

  # notes: ../AGENTS.md#a-link-leaves-when-its-dashboard-does
  @grafana @in-grafana @gesture @ui @unbuilt
  Scenario: Delete a link's dashboard in Grafana
    Given a dashboard file named "Fleet Health.grafana" in "Pointers"
    When someone deletes the dashboard in Grafana
    Then the file is gone from "Pointers"
    And the file is not in the Nextcloud trash

    # A pointer restored from the trash would reconnect to nothing.

    # ── RULE: a trash that cannot finish leaves the file where it was ──────────

  # notes: ../AGENTS.md#the-grafana-delete-is-aborted-if-grafana-is-unreachable
  @user @in-nextcloud @gesture @ui @blocked
  Scenario: Trash a dashboard while Grafana is unreachable
    Given a dashboard file named "Fleet Health.grafana" in "Demo"
    And Grafana is unreachable
    When I try to move it to the trash
    Then the trash is aborted and the file stays in "Demo"
    And the file keeps its Grafana metadata
