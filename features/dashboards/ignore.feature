# Notes, decisions and history for this feature: ../AGENTS.md#dashboardsignore

Feature: Reserved tags exclude individual dashboards — from either side
  As an admin
  I want a Grafana-side and a Nextcloud-side exclude tag
  So that one dashboard can be left out from whichever side owns the decision

  Background:
    Given the app is connected to Grafana
    And a folder mapped as "sync" to the Grafana folder "flows"

  @grafana @in-grafana @occ @unbuilt
  Scenario: With no reserved tag, a dashboard takes the mapping's mode
    Given Grafana has a dashboard in the "flows" folder with no reserved tag
    When the "flows" mapping is pulled
    Then that dashboard's file is in "sync" mode, the mapping mode

  # Grafana-origin exclude: the tag lives on the DASHBOARD in Grafana.
  @grafana @in-grafana @occ @unbuilt
  Scenario: nextcloud:ignore on a Grafana dashboard is never pulled
    Given Grafana has a dashboard in the "flows" folder tagged "nextcloud:ignore" in Grafana
    When the "flows" mapping is pulled
    Then that dashboard is not pulled into Nextcloud
    And no file is created for it

  # Nextcloud-origin exclude: the tag lives on the FILE in Nextcloud.
  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: grafana:ignore on a file already in a mapped folder gives it "ignored" mode
    Given a managed "sync" dashboard file in the "flows" folder
    When the admin adds the Nextcloud tag "grafana:ignore" to the file
    Then the file's mode becomes "ignored"
    And the file stays in the mapped folder and keeps its "grafana_uid"
    And the dashboard is left fully live in Grafana
    And subsequent pulls/pushes for "flows" skip it

  @user @in-nextcloud @gesture @ui @occ @unbuilt
  Scenario: Removing grafana:ignore returns the file to the mapping's mode
    Given a managed "sync" dashboard file in the "flows" folder
    And the file has the Nextcloud tag "grafana:ignore"
    When I remove the "grafana:ignore" tag
    Then the file's mode becomes "sync"

  # The two origins are independent — neither is written across the boundary.
  @admin @in-nextcloud @occ @unbuilt
  Scenario: The app never writes reserved tags onto Grafana dashboards
    Given Grafana has a dashboard in the "flows" folder with no reserved tag
    When the "flows" mapping is pulled
    Then the dashboard in Grafana still carries only its original tags
    And the app has not added any "grafana:sync", "grafana:link", "grafana:ignore", or "nextcloud:ignore" tag to it

  # notes: ../AGENTS.md#a-file-already-marked-ignored-is-left-alone-by-the-pull
  @grafana @in-grafana @occ @todo
  Scenario: A file already marked ignored is left alone by the pull
    Given a managed dashboard file in a mapped folder whose mode is "ignored"
    When the mapping is pulled
    Then the file is unchanged
    And no second file is created for its dashboard
