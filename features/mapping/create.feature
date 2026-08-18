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
      | groups     |                           |
      | storage    | admin folder              |

    # A mapping is one fact, so it is one sentence plus a table of what is in it.
    # notes: ../AGENTS.md#the-preconditions

  @admin @occ @ui
  Scenario Outline: Creating a mapping saves the form
    Given no Grafana folders are mapped
    When the admin maps the Grafana folder "<uid>" with:
      | nc folder  | <nc folder>  |
      | mode       | <mode>       |
      | groups     | <groups>     |
      | storage    | <storage>    |
    Then the mapping matches the form, unset fields at their defaults

    # Every mode against every storage backend — the two axes that survive.

    Examples: every mode, on both storage backends
      | uid     | nc folder | mode | groups | storage      |
      | observe | observe   | sync |        | team folder  |
      | secrets | secrets   | link |        | team folder  |
      | network | network   | sync |        | admin folder |
      | build   | build     | link |        | admin folder |

    Examples: and the fields that have a default
      | uid       | nc folder | mode | groups | storage |
      | defaulted |           |      |        |         |
      | grouped   | grouped   | sync | admin  |         |
      | nested    | nested    | sync |        |         |

    # notes: ../AGENTS.md#creating-a-mapping-saves-the-form

  @admin @occ @ui
  Scenario Outline: A mapping the app cannot honour is refused, and says why
    Given no Grafana folders are mapped
    When the admin maps the Grafana folder "<uid>" with:
      | nc folder | <nc folder> |
      | mode      | <mode>      |
    Then the mapping is rejected
    And the refusal explains "<reason>"
    And there are exactly 0 configured mappings

    Examples: every field that carries a rule of its own
      | uid     | nc folder | mode  | reason             |
      |         | observe   | sync  | grafana_folder_uid |
      | observe | observe   | bogus | mode must be       |

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

  @admin @occ @ui @todo
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


  # ── the optional Grafana recycle-bin folder ────────────────────────────────
  # notes: ../AGENTS.md#the-recycle-bin-folder

  @recycle-bin @todo
  Scenario: The recycle bin is off by default, and naming a folder does not enable it
    Given the app is enabled
    Then the Grafana recycle bin setting reads off
    When the admin names the Grafana recycle-bin folder "nextcloud-trash"
    Then the Grafana recycle bin setting reads off
    When the admin turns the Grafana recycle bin on
    Then the Grafana recycle bin setting reads on

    # Two settings, and the panel lets you save one without the other — naming the
    # folder is not consent to start moving dashboards into it.

  @recycle-bin @todo
  Scenario: The recycle-bin folder cannot also be a mapped folder
    Given the Grafana recycle-bin folder is named "nextcloud-trash"
    And the Grafana recycle bin is on
    When the admin maps the Grafana folder "nextcloud-trash" with:
      | nc folder | trash |
      | mode      | sync  |
    Then the mapping is rejected
    And there are exactly 0 configured mappings
