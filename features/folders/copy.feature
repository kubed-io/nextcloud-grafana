# Notes, decisions and history for this feature: ../AGENTS.md#folderscopy

Feature: Copying a folder
  As a Nextcloud user
  I want a copied folder to be a fresh thing, never a second claim on the original
  So that duplicating a folder cannot leave two files fighting over one dashboard

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"

  # ── the invariant that must hold however the rest is decided ─────────────────────

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Dashboards in a copied folder never inherit the originals' uids
    Given a subfolder of "alpha" holding three managed "sync" dashboard files
    When I copy the subfolder within the "alpha" folder
    Then no file in the copy carries an inherited "grafana_uid"
    And the original three files and their dashboards are unchanged

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Copying a folder of dashboards creates new dashboards for the copies
    Given a subfolder of "alpha" holding three managed "sync" dashboard files
    When I copy the subfolder within the "alpha" folder
    Then three new dashboards exist in Grafana with their own uids
    And there are now six dashboards in the "alpha" Grafana folder

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Copying a folder to outside every mapping creates nothing in Grafana
    Given a subfolder of "alpha" holding three managed "sync" dashboard files
    When I copy the subfolder to a folder that is not mapped
    Then Grafana is not contacted
    And no file in the copy carries Grafana metadata

  @user @in-nextcloud @gesture @ui @todo
  Scenario: Copying an ordinary folder inside a mapping is unaffected
    Given a folder "Notes" inside the "alpha" folder holding no dashboard files
    When I copy it within the "alpha" folder
    Then Grafana is not contacted

  # ── the folder's own identity ────────────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A copied Grafana folder does not inherit the original's folder uid
    Given a mirrored Grafana folder "Team A" under the "alpha" folder
    When I copy it within the "alpha" folder
    Then the copy carries no "grafana_folderUid"
    And the original still carries its own

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A copied Grafana folder does not inherit the "grafana" tag
    Given a mirrored Grafana folder "Team A" under the "alpha" folder
    When I copy it within the "alpha" folder
    Then the copy does not carry the "grafana" tag
    And no second Grafana folder is created

  # Having landed as a plain folder, the copy becomes a Grafana folder the same way
  # any folder does — by being tagged. One gesture, no special case.
  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: Tagging the copy afterwards makes it a Grafana folder of its own
    Given a plain copy of a mirrored Grafana folder, holding dashboard files
    When I assign the "grafana" tag to the copy
    Then a new Grafana folder is created for it
    And the dashboards inside it are re-parented into that new folder

  # ── the alternative reading: refuse ──────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Copying a Grafana folder is refused rather than bulk-duplicating dashboards
    Given a mirrored Grafana folder "Team A" holding forty dashboards
    When I try to copy it
    Then the copy is refused with a message explaining what it would create
    And nothing is created in Grafana

  # ── partial failure ──────────────────────────────────────────────────────────────
  # Same shape as move-folder.feature: one gesture, N far-side writes, no transaction.

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A folder copy that fails part-way reports what did and did not happen
    Given a subfolder of "alpha" holding three managed "sync" dashboard files
    And Grafana will reject the creation of one of the copies
    When I copy the subfolder within the "alpha" folder
    Then the user is told which dashboards were created and which were not
    And the copied files that failed carry no "grafana_uid"

  # ── the pull must never look like a copy ─────────────────────────────────────────
  # notes: ../AGENTS.md#the-pulls-own-folder-writes-are-never-treated-as-a-copy
  @grafana @in-grafana @occ @unbuilt
  Scenario: The pull's own folder writes are never treated as a copy
    Given a Grafana folder "Bubbles" holding two dashboards under the "alpha" folder
    When the "alpha" mapping is pulled twice
    Then Grafana still holds exactly one folder named "Bubbles"
    And it still holds exactly two dashboards
