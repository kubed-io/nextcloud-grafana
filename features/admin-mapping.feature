# Notes, decisions and history for this feature: AGENTS.md#admin-mapping

Feature: Admin configures folder mappings
  As a Nextcloud admin
  I want to map Grafana folders to Nextcloud folders with a mode
  So that I can automate the connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled

    # A mapping is one fact, so it is one sentence plus a table of what is in it —
    # the same table whether it is pre-state or the action. A blank cell means the
    # admin left that field alone, so the app's own default applies.
    # notes: AGENTS.md#the-preconditions

  Scenario Outline: Creating a mapping saves the form
    Given no Grafana folders are mapped
    And an unset field on the mapping form defaults to:
      | nc folder  | the Grafana folder's name |
      | mode       | link                      |
      | format     | json                      |
      | groups     |                           |
      | storage    | team folder               |
      | subfolders | off                       |
    # The Grafana folder is the only required field — everything else has a
    # default, and the outline below proves the one refusal.
    #
    # The Nextcloud folder's default is prose rather than a value: left blank it is
    # materialised from the Grafana folder's TITLE at create and stored, so the
    # saved mapping shows both fields populated and you can see at a glance that
    # they match because the name was left blank.
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

    # notes: AGENTS.md#creating-a-mapping-saves-the-form

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

    # notes: AGENTS.md#a-mapping-the-app-cannot-honour-is-refused-and-says-why

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
    # notes: AGENTS.md#a-grafana-folder-may-only-be-mapped-once

  @unbuilt
  Scenario: Two mappings may not target the same Nextcloud folder
    Given a mapping with the following values:
      | grafana folder | observe |
      | nc folder      | shared  |
    When the admin maps the Grafana folder "secrets" with:
      | nc folder | shared |
    Then the mapping is rejected
    And the refusal explains "already"
    And there is exactly 1 configured mapping
    # UNBUILT, AND THE GAP IS REAL: assertFolderUnique checks the GRAFANA uid and
    # says nothing about the Nextcloud folder, so today this is accepted and the
    # two mappings then prune each other's dashboards forever.
    # notes: AGENTS.md#two-mappings-may-not-target-the-same-nextcloud-folder

  Scenario: The Grafana root can be mapped via the reserved "/" folder
    Given no Grafana folders are mapped
    When the admin maps the Grafana folder "/" with:
      | nc folder | dashboards |
      | mode      | sync       |
    Then the mapping matches the form, unset fields at their defaults
    # The Grafana root ("General") holds dashboards with no folder and has no real
    # uid, so the picker offers a reserved "/" entry for it.
    # notes: AGENTS.md#the-grafana-root-can-be-mapped-via-the-reserved-folder

  @unbuilt
  Scenario Outline: What a mapping locks, it locks for a reason
    Given a mapping with the following values:
      | grafana folder | observe |
      | nc folder      | observe |
    When the admin changes that mapping's <field> to "<value>"
    Then the change is rejected as immutable

    Examples: the four fields a change would have to migrate
      | field          | value     |
      | grafana folder | secrets   |
      | nc folder      | elsewhere |
      | storage        | admin     |
      | subfolders     | on        |

    # notes: AGENTS.md#what-a-mapping-locks-it-locks-for-a-reason

  # ── the optional Grafana recycle-bin folder ────────────────────────────────
  # Off by default: a move-out or delete is a true Grafana delete. Turned on, the
  # admin names an existing Grafana folder to act as the bin, so a delete MOVES
  # the dashboard there with its uid intact and a restore returns the same
  # dashboard. It is a setting of the app, not a property of a mapping — which is
  # why the bin folder may not itself be mapped.
  # notes: AGENTS.md#the-recycle-bin-folder

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
