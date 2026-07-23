#!/usr/bin/env bash
#
# Preload Grafana with a sample folder + dashboard for the integration tests — a
# CONTROL CASE that does NOT rely on the app under test. These are "dashboards
# that already exist in Grafana", created straight through Grafana's own API, so
# mapping/pull scenarios have real, pre-existing resources to act on (the
# equivalent of "a Grafana admin already built some dashboards").
#
# Creates one folder and one dashboard inside it:
#   folder     nextcloud-alpha   (uid: nc-alpha)
#   dashboard  Alpha Demo        (uid: nc-alpha-demo) in that folder
#
# Inputs (env):  GRAFANA_URL, GRAFANA_TOKEN (minted by mint-grafana-token.sh)
# Idempotent-ish: intended for a fresh CI Grafana; re-runs may 412 on the folder.

set -euo pipefail

: "${GRAFANA_URL:?GRAFANA_URL is required}"
: "${GRAFANA_TOKEN:?GRAFANA_TOKEN is required (run mint-grafana-token.sh first)}"

api() {
  # api <method> <path> [json-body]
  local method="$1" path="$2" body="${3:-}"
  if [ -n "$body" ]; then
    curl -fsS -X "$method" "$GRAFANA_URL/api$path" \
      -H "Authorization: Bearer $GRAFANA_TOKEN" -H 'Content-Type: application/json' -d "$body"
  else
    curl -fsS -X "$method" "$GRAFANA_URL/api$path" -H "Authorization: Bearer $GRAFANA_TOKEN"
  fi
}

echo "== preloading Grafana with a sample folder + dashboard =="

# 1. Folder (the mapping unit). Grafana folders are first-class, unlike n8n tags.
api POST '/folders' '{"uid":"nc-alpha","title":"nextcloud-alpha"}' >/dev/null
echo "  created folder nextcloud-alpha (uid nc-alpha)"

# 2. A minimal but valid dashboard placed inside that folder. `id:null` = create.
api POST '/dashboards/db' '{
  "dashboard": {
    "uid": "nc-alpha-demo",
    "title": "Alpha Demo",
    "tags": ["integration"],
    "schemaVersion": 39,
    "version": 0,
    "panels": []
  },
  "folderUid": "nc-alpha",
  "overwrite": true,
  "message": "integration preload"
}' >/dev/null
echo "  created dashboard Alpha Demo (uid nc-alpha-demo) in nextcloud-alpha"

echo "== preload complete =="
