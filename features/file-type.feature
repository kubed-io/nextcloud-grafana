# Notes, decisions and history for this feature: AGENTS.md#file-type

Feature: Grafana dashboard is a first-class file type
  As a Nextcloud user
  I want .grafana.json files to be a real, purpose-built file type
  So that they have the right mimetype + icon, expose their state, and are queryable

  Background:
    Given the app is connected to Grafana

  @user @ui @todo
  Scenario: Dashboard files get the custom mimetype and Grafana icon
    Given a managed dashboard file
    Then its mimetype is "application/grafana+json"
    And the Files app shows the Grafana icon instead of a generic JSON icon

  @user @gesture @ui @todo
  Scenario: WebDAV PROPFIND exposes the dashboard metadata in the XML
    Given a managed dashboard file
    When a WebDAV client requests the file's properties over PROPFIND
    Then the raw XML includes:
      | property                       |
      | nc:metadata-grafana_uid        |
      | nc:metadata-grafana_mode       |
      | nc:metadata-grafana_version    |
      | nc:metadata-grafana_mapping    |
      | nc:metadata-grafana_folderUid  |
      | nc:metadata-grafana_apiVersion |

  @user @gesture @ui @todo
  Scenario Outline: The mode property carries the descriptive value
    Given a managed dashboard file in "<mode>" mode
    Then its "nc:metadata-grafana_mode" property is "<dav value>"

    Examples:
      | mode     | dav value |
      | sync     | sync      |
      | unmapped | unmapped  |
      | ignored  | ignored   |

  # link stores as "reference" (the literal "link" is is_callable() → crashes core
  # PROPFIND); link integration is uncertain (no create-on-land path).
  @user @gesture @ui @todo
  Scenario Outline: The mode property carries the descriptive value (link)
    Given a managed dashboard file in "<mode>" mode
    Then its "nc:metadata-grafana_mode" property is "<dav value>"

    Examples:
      | mode | dav value |
      | link | reference |

  @user @gesture @ui @todo
  Scenario: The metadata is read-only over DAV
    Given a managed dashboard file
    When a client tries to change "nc:metadata-grafana_uid" via PROPPATCH
    Then the change is rejected — the sync engine owns these properties

  # notes: AGENTS.md#files-are-queryable-by-their-indexed-mode
  @user @gesture @ui @blocked
  Scenario: Files are queryable by their indexed mode
    Given a "sync" dashboard file and a "link" dashboard file in the same user's storage
    When a DAV REPORT searches for files where "nc:metadata-grafana_mode" is "sync"
    Then only the sync file is returned
