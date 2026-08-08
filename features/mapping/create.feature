# Notes, decisions and history for this feature: ../AGENTS.md#mappingcreate

Feature: Admin configures folder mappings
  As a Nextcloud admin
  I want to map Grafana folders to Nextcloud folders with a mode
  So that I can automate the connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled
    # What an unset field becomes is a fact about the form, so it is declared once.
    # notes: ../AGENTS.md#the-preconditions
    And an unset field on the mapping form defaults to:
      | nc folder  | the Grafana folder's name |
      | mode       | link                      |
      | format     | json                      |
      | groups     |                           |
      | storage    | admin folder              |
      | subfolders | off                       |

    # A mapping is one fact, so it is one sentence plus a table of what is in it.
    # notes: ../AGENTS.md#the-preconditions

  @admin @occ @ui
  Scenario Outline: Creating a mapping saves the form
    Given no Grafana folders are mapped
    When the admin maps the Grafana folder "<uid>" with:
      | nc folder  | <nc folder>  |
      | mode       | <mode>       |
      | format     | <format>     |
      | groups     | <groups>     |
      | storage    | <storage>    |
      | subfolders | <subfolders> |
    Then the mapping matches the form, unset fields at their defaults

    # The mode x format matrix, one row per combination.

    Examples: every mode, and every serialization format
      | uid     | nc folder | mode | format | groups | storage      | subfolders |
      | observe | observe   | sync | json   |        | team folder  |            |
      | secrets | secrets   | link | json   |        | team folder  |            |
      | network | network   | sync | yaml   |        | admin folder |            |
      | build   | build     | link | yaml   |        | admin folder |            |

    Examples: and the fields that have a default
      | uid       | nc folder | mode | format | groups | storage | subfolders |
      | defaulted |           |      |        |        |         |            |
      | grouped   | grouped   | sync |        | admin  |         |            |
      | nested    | nested    | sync |        |        |         | on         |

    # notes: ../AGENTS.md#creating-a-mapping-saves-the-form

  @admin @occ @ui
  Scenario Outline: A mapping the app cannot honour is refused, and says why
    Given no Grafana folders are mapped
    When the admin maps the Grafana folder "<uid>" with:
      | nc folder | <nc folder> |
      | mode      | <mode>      |
      | format    | <format>    |
    Then the mapping is rejected
    And the refusal explains "<reason>"
    And there are exactly 0 configured mappings

    Examples: every field that carries a rule of its own
      | uid     | nc folder | mode  | format | reason             |
      |         | observe   | sync  | json   | grafana_folder_uid |
      | observe | observe   | bogus | json   | mode must be       |
      | observe | observe   | sync  | toml   | format must be     |

    # notes: ../AGENTS.md#a-mapping-the-app-cannot-honour-is-refused-and-says-why

  @admin @occ @ui
  Scenario: A Grafana folder may only be mapped once
    Given a mapping with the following values:
      | grafana folder | observe |
      | nc folder      | observe |
    When the admin maps the Grafana folder "observe" with:
      | nc folder | elsewhere |
    Then the mapping is rejected
    And the refusal explains "already uses the Grafana folder"
    And there is exactly 1 configured mapping
    # A Grafana folder is what a mapping IS, so mapping it twice would make two
    # mappings mean the same thing and every dashboard in it would belong to both.
    # notes: ../AGENTS.md#a-grafana-folder-may-only-be-mapped-once

  @admin @occ @ui @unbuilt
  Scenario: Two mappings may not target the same Nextcloud folder
    Given a mapping with the following values:
      | grafana folder | observe |
      | nc folder      | shared  |
    When the admin maps the Grafana folder "secrets" with:
      | nc folder | shared |
    Then the mapping is rejected
    And the refusal explains "already"
    And there is exactly 1 configured mapping
    # notes: ../AGENTS.md#two-mappings-may-not-target-the-same-nextcloud-folder

  @admin @occ @ui
  Scenario: The Grafana root can be mapped via the reserved "/" folder
    Given no Grafana folders are mapped
    When the admin maps the Grafana folder "/" with:
      | nc folder | dashboards |
      | mode      | sync       |
    Then the mapping matches the form, unset fields at their defaults
    # The Grafana root ("General") holds dashboards with no folder and has no real
    # uid, so the picker offers a reserved "/" entry for it.
    # notes: ../AGENTS.md#the-grafana-root-can-be-mapped-via-the-reserved-folder

  @admin @occ @ui
  Scenario Outline: The groups a mapped folder is shared with can be changed
    Given the Nextcloud groups "design,sales" exist
    And a mapping with the following values:
      | grafana folder | observe   |
      | nc folder      | <folder>  |
      | groups         | design,admin |
      | storage        | <storage> |
    When the admin changes that mapping's groups to "<groups>"
    Then the mapping's groups are "<groups>"

    # notes: ../AGENTS.md#the-groups-a-mapped-folder-is-shared-with-can-be-changed

    Examples: on a Team Folder
      | folder                  | storage      | groups             |
      | Groups On A Team Folder | team folder  | design,admin,sales |
      | Groups On A Team Folder | team folder  | design             |
      | Groups On A Team Folder | team folder  | sales              |
      | Groups On A Team Folder | team folder  |                    |

    Examples: and on an admin-owned folder
      | folder                   | storage      | groups             |
      | Groups On A Plain Folder | admin folder | design,admin,sales |
      | Groups On A Plain Folder | admin folder | design             |
      | Groups On A Plain Folder | admin folder | sales              |
      | Groups On A Plain Folder | admin folder |                    |

    # notes: ../AGENTS.md#the-groups-a-mapped-folder-is-shared-with-can-be-changed

  @admin @occ @ui @team-folder
  Scenario: Groups are read from the folder, not from the mapping
    Given the Nextcloud groups "design,sales" exist
    And a mapping with the following values:
      | grafana folder | observe          |
      | nc folder      | Shared Elsewhere |
      | groups         | design           |
      | storage        | team folder      |
    When the Team Folder "Shared Elsewhere" is shared with the group "sales" outside this app
    Then the mapping's groups are "design,sales"
    # notes: ../AGENTS.md#groups-are-read-from-the-folder-not-from-the-mapping

  # ── the optional Grafana recycle-bin folder ────────────────────────────────
  # notes: ../AGENTS.md#the-recycle-bin-folder

  @recycle-bin @todo
  Scenario: The recycle-bin folder is off by default and can be enabled with a folder name
    Given the app is enabled
    Then the Grafana recycle-bin folder is off
    When the admin enables the Grafana recycle-bin folder and sets it to "nextcloud-trash"
    Then the Grafana recycle-bin folder is on and set to "nextcloud-trash"

  @recycle-bin @unbuilt
  Scenario: The recycle-bin folder cannot also be a mapped folder
    Given the Grafana recycle-bin folder is on and set to "nextcloud-trash"
    When the admin maps the Grafana folder "nextcloud-trash" with:
      | nc folder | trash |
      | mode      | sync  |
    Then the mapping is rejected
    And there are exactly 0 configured mappings
