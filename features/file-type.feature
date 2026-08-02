# The custom mimetype makes a dashboard a first-class FILE TYPE: its own mimetype,
# its own icon, DAV-exposed (and read-only) metadata, and — because grafana_mode is
# indexed — it's queryable. (What happens when you OPEN one is the related but
# separate "open with" concern; see open-with.feature.)
#
# METADATA KEYS (saga Ch2 Round 2 — scrutinised, NOT a 1:1 rename of the master's
# n8n_* keys). Five renamed + two Grafana-only additions:
#   grafana_uid        — the dashboard UID (the master's n8n_id). Stable thread.
#   grafana_mode       — sync | reference(=link) | unmapped | ignored. INDEXED.
#   grafana_version    — last-seen Grafana version (bumps every save — STORED, never
#                        hashed; the master's n8n_versionId).
#   grafana_syncedHash — sha1 of the spec WE sent (loop guard; the master's
#                        n8n_syncedHash).
#   grafana_mapping    — originating mapping id. INDEXED.
#   grafana_folderUid  — NEW: source Grafana folder UID (Grafana has real nested
#                        folders; needed for the cascade/subfolder model).
#   grafana_apiVersion — NEW: serialization schema (classic JSON vs v2 YAML) so a
#                        file self-describes how to be read back.
# Isolation is free: NC Files-Metadata keys are a flat string-keyed namespace, so a
# grafana_* key can never surface in an n8n_* query and vice-versa. (The shared-module
# neutral-key question is deferred — saga Ch2 Round 2, fork B.)
#
# Live for the WebDAV-observable surface: the custom mimetype, the nc:metadata-*
# props exposed in PROPFIND, the descriptive grafana_mode value for sync + unmapped,
# and the read-only (PROPPATCH-rejected) guarantee. Left @todo: the link/ignored mode
# rows (link integration is uncertain; ignored is the reserved-tags slice), and the
# REPORT-by-indexed-mode query (the DAV search plumbing for nc:metadata-* is unproven
# against the pod). CI skips @todo.

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

  # grafana_mode is indexed → "find every sync / unmapped / ignored file" is a fast
  # query. @todo until the DAV-search plumbing for nc:metadata-* is confirmed.
  # @blocked, and the missing capability is named: there is no proven DAV REPORT
  # search over `nc:metadata-*` in this harness. The index itself is real — the mode
  # is registered as an indexed metadata key precisely so "find every unmapped file"
  # is a query rather than a folder walk — but nothing here can issue that query.
  @user @gesture @ui @blocked
  Scenario: Files are queryable by their indexed mode
    Given a "sync" dashboard file and a "link" dashboard file in the same user's storage
    When a DAV REPORT searches for files where "nc:metadata-grafana_mode" is "sync"
    Then only the sync file is returned
