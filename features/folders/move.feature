# Notes, decisions and history for this feature: ../AGENTS.md#foldersmove

Feature: Moving a folder
  As a Nextcloud user
  I want moving a folder to have a predictable effect on the dashboards inside it
  So that reorganising my files cannot silently delete or orphan them

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "alpha"
    And a folder mapped as "sync" to the Grafana folder "beta"

  # ── what happens today ───────────────────────────────────────────────────────────

  # Recorded as a known defect. The files' paths change, so their mapping membership
  # changes, but no listener acts on a folder move — Grafana hears nothing.
  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Moving a folder of dashboards out of a mapping desyncs them silently
    Given a subfolder of "alpha" holding three managed "sync" dashboard files
    When I move the subfolder to a folder that is not mapped
    Then the three files no longer resolve to any mapping
    And their dashboards are untouched in Grafana
    And nothing tells the user the two sides no longer agree

  # ── moves inside the mapped set ──────────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Moving a subfolder within its own mapping changes nothing
    Given a subfolder of "alpha" holding three managed "sync" dashboard files
    When I move the subfolder elsewhere inside the "alpha" folder
    Then the three files stay managed "sync" under the "alpha" mapping
    And Grafana is not contacted

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Moving a subfolder into another mapping re-parents its dashboards, keeping uids
    Given a subfolder of "alpha" holding three managed "sync" dashboard files
    When I move the subfolder into the "beta" folder
    Then all three dashboards move to the "beta" Grafana folder
    And each keeps its "grafana_uid"
    And each file re-stamps its mapping to "beta"

  # ── leaving the mapped set: the decision ─────────────────────────────────────────
  # These two are alternatives, not both. Whichever is chosen, the other should be
  # deleted from this file rather than left as an unresolved pair.

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Moving a folder out of every mapping applies the per-file rule to each dashboard
    Given the Grafana recycle-bin folder is off
    And a subfolder of "alpha" holding three managed "sync" dashboard files
    When I move the subfolder to a folder that is not mapped
    Then all three dashboards are deleted in Grafana
    And all three files keep their full JSON and lose their Grafana identity

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: Moving a folder out of every mapping is refused rather than deleting many dashboards
    Given the Grafana recycle-bin folder is off
    And a subfolder of "alpha" holding three managed "sync" dashboard files
    When I try to move the subfolder to a folder that is not mapped
    Then the move is refused with a message naming how many dashboards it would delete
    And the subfolder stays where it was

  @user @in-nextcloud @gesture @ui @recycle-bin @unbuilt
  Scenario: With the recycle bin on, moving a folder out parks its dashboards instead
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    And a subfolder of "alpha" holding three managed "sync" dashboard files
    When I move the subfolder to a folder that is not mapped
    Then all three dashboards are parked in "nextcloud-trash"
    And all three files KEEP their "grafana_uid"

  # ── arriving from outside ────────────────────────────────────────────────────────

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: Moving a folder of untracked dashboard files into a mapping creates them all
    Given a folder outside every mapping holding three untracked ".grafana.json" files
    When I move the folder into the "alpha" folder
    Then three dashboards are created in the "alpha" Grafana folder
    And each file is stamped with its new uid

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A folder move that fails part-way reports what did and did not happen
    Given a folder outside every mapping holding three untracked ".grafana.json" files
    And Grafana will reject the creation of one of them
    When I move the folder into the "alpha" folder
    Then the user is told which dashboards were created and which were not
    And the files that failed carry no "grafana_uid"

  # ── link mappings ────────────────────────────────────────────────────────────────
  # A link is a read-only pointer, and move-dashboard.feature blocks moving one out
  # of its mapping. A folder move must not become the way around that guard.

  @user @in-nextcloud @gesture @ui @unbuilt
  Scenario: A folder move cannot smuggle a link out of its mapping
    Given a folder mapped as "link" to the Grafana folder "links"
    And a subfolder of it holding a managed "link" dashboard file
    When I try to move the subfolder to a folder that is not mapped
    Then the move is refused
    And the dashboard in Grafana is untouched

  # ── the mapped folder itself ─────────────────────────────────────────────────────
  # Same root cause as rename-folder.feature: the mapping is a path string, so moving
  # the folder it names orphans it.

  @admin @in-nextcloud @gesture @ui @unbuilt
  Scenario: Moving a mapped folder orphans its mapping
    Given a managed "sync" dashboard file in the "alpha" folder
    When I move the mapped Nextcloud folder somewhere else
    Then the mapping no longer matches any folder
    And nothing warns the admin that the connection is broken
