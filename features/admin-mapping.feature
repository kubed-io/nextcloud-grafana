# "Admin makes a mapping" — the folder-mapping list in admin settings, driven over
# the CLI (the same operations the Settings panel performs).
#
# THE KEY DIFFERENCE FROM THE n8n TEMPLATE: Grafana has real folders. Where the
# n8n app had to bind an n8n *tag* to a Nextcloud folder (n8n has no folders), a
# Grafana mapping binds a Grafana **folder** (by uid) to a Nextcloud folder — a
# plain folder-to-folder mirror, no tagging scheme to maintain. The dashboards
# inside that Grafana folder become the `.grafana.json` files in the NC folder,
# and nested Grafana folders mirror to nested NC folders (the "General"/root area
# maps to the mapping's root). Modes are sync / link (see the saga).
#
# @todo — the mapping engine lands with the sync chapter; this feature is the
# executable spec for it. The POC ships only the connection panel.
@todo
Feature: Admin configures folder mappings
  As a Nextcloud admin
  I want to map Grafana folders to Nextcloud folders with a mode
  So that I can automate the connection and mappings (e.g. in k8s)

  Background:
    Given the app is enabled

  Scenario: Map Grafana folders to Nextcloud folders across both modes
    When the admin adds these mappings:
      | grafana folder | folder  | mode |
      | observe        | observe | sync |
      | secrets        | secrets | link |
    Then there are 2 configured mappings
    And the mapping for grafana folder "observe" is in "sync" mode
    And the mapping for grafana folder "secrets" is in "link" mode

  # New-model invariant: a mapping's mode is exactly sync or link.
  Scenario: A mapping mode must be sync or link
    When the admin adds a mapping with an unknown mode for grafana folder "build"
    Then the mapping is rejected
