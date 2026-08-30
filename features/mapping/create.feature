# Notes, decisions and history for this feature: ../AGENTS.md#mappingcreate

Feature: Mapping a Grafana folder to a Nextcloud folder
  As a Nextcloud admin
  I want to point a Grafana folder at a Nextcloud folder with a mode
  So that its dashboards mirror into Nextcloud, scriptably (e.g. from a k8s job)

  rules:
  - creating a mapping does not trigger a sync
  - creating a mapping creates its nextcloud folder if it doesn't exist at the moment of creation
  - if the folder is a team folder, the folder is created with the team folder api
  - a link mapping cannot hold dashboard files, so unmapped ones already in the folder are purged on accept

  Background:
    Given the app is enabled
    And the Grafana base URL points at the test instance
    And the admin has configured the service-account token

    # ── one fact, one table — the same shape as pre-state or as the action ─────
    # notes: ../AGENTS.md#the-preconditions

  @admin @occ @ui
  Scenario Outline: Creating a new mapping to a Grafana folder
    Given a Grafana folder named "observe" exists
    And the Nextcloud groups "ops" exists
    And an unset field on the mapping form defaults to:
      | nc folder | observe      |
      | mode      | link         |
      | groups    |              |
      | storage   | admin folder |
    When the admin submits this mapping:
      | grafana folder | observe     |
      | nc folder      | <nc folder> |
      | mode           | <mode>      |
      | groups         | <groups>    |
      | storage        | <storage>   |
    Then the mapping matches the form, unset fields at their defaults

    Examples: one field at a time, and nothing at all
      | nc folder  | mode | groups    | storage     |
      |            |      |           |             |
      | Dashboards |      |           |             |
      |            | link |           |             |
      |            | sync |           |             |
      |            |      | admin     |             |
      |            |      | admin,ops |             |
      |            |      |           | team folder |

    Examples: and in combination
      | nc folder  | mode | groups    | storage     |
      | Dashboards | sync | admin,ops | team folder |
      | observe    | link | admin     | team folder |

    # notes: ../AGENTS.md#creating-a-mapping-saves-the-form

  # notes: ../AGENTS.md#a-link-mapping-may-not-be-made-over-dashboards-that-already-exist
  @admin @occ @ui
  Scenario: Mapping in link mode over a folder that already holds dashboards
    Given a Grafana folder named "observe" exists
    And a folder "Dashboards" already exists
    And an unmapped dashboard file at "Dashboards/Fleet/Keeper.grafana"
    When the admin submits this mapping:
      | grafana folder | observe    |
      | nc folder      | Dashboards |
      | mode           | link       |
    And allows the existing unmapped dashboards to be purged
    Then the mapping matches the form, unset fields at their defaults
    And no ".grafana" dashboards exist under "/Dashboards" in Nextcloud
    And "Dashboards/Fleet/Keeper.grafana" left no trash entry

  # notes: ../AGENTS.md#a-grafana-folder-may-only-be-mapped-once
  @admin @occ @ui
  Scenario: A Grafana folder may only be mapped once
    Given a mapping with the following values:
      | grafana folder | observe |
      | nc folder      | observe |
    When the admin submits this mapping:
      | grafana folder | observe   |
      | nc folder      | elsewhere |
      | mode           | sync      |
    Then the mapping is rejected, explaining "already uses the Grafana folder"

  # notes: ../AGENTS.md#a-nextcloud-folder-may-only-be-mapped-once
  @admin @occ @ui
  Scenario: A Nextcloud folder may only be mapped once
    Given a mapping with the following values:
      | grafana folder | observe |
      | nc folder      | shared  |
    And a Grafana folder named "secrets" exists
    When the admin submits this mapping:
      | grafana folder | secrets |
      | nc folder      | shared  |
      | mode           | link    |
    Then the mapping is rejected, explaining "already uses the Nextcloud folder"

  # The Grafana root ("General") holds dashboards in no folder and has no real uid,
  # so the picker offers a reserved "/" entry for it.
  # notes: ../AGENTS.md#the-grafana-root-can-be-mapped-via-the-reserved-folder
  @admin @occ @ui
  Scenario: The Grafana root can be mapped via the reserved "/" folder
    When the admin submits this mapping:
      | grafana folder | /          |
      | nc folder      | dashboards |
      | mode           | sync       |
    Then the mapping matches the form, unset fields at their defaults

  # The bin holds dashboards this app parks and dashboards it has never managed,
  # so nothing may ever sync into it.
  # notes: ../AGENTS.md#the-recycle-bin-folder-cannot-also-be-a-mapped-folder
  @admin @occ @ui @recycle-bin
  Scenario: The recycle-bin folder cannot also be a mapped folder
    Given the Grafana recycle-bin folder is named "nextcloud-trash"
    And the Grafana recycle bin is on
    When the admin submits this mapping:
      | grafana folder | nextcloud-trash |
      | nc folder      | trash           |
      | mode           | sync            |
    Then the mapping is rejected, explaining "cannot be mapped because it is the recycle bin"
