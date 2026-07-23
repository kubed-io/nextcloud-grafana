#!/usr/bin/env bash
#
# Preload Grafana with example folders + dashboards for the integration tests — a
# CONTROL CASE that does NOT rely on the app under test. These are "dashboards that
# already exist in Grafana", created straight through Grafana's own API from the
# fixtures in tests/dashboards/, so mapping/pull scenarios have real, pre-existing
# resources to act on (the equivalent of "a Grafana admin already built some
# dashboards"). Mirrors nextcloud-n8n's preload-n8n.sh, which loads
# tests/workflows/*.json.
#
# Each fixture (tests/dashboards/*.json) is a raw Grafana dashboard body, loaded
# into its own folder — the folder is the mapping unit (Grafana has real folders,
# unlike n8n tags):
#   alpha-demo.json   -> folder nextcloud-alpha   (uid nc-alpha)
#   bravo-demo.json   -> folder nextcloud-bravo   (uid nc-bravo)
#   charlie-demo.json -> folder nextcloud-charlie (uid nc-charlie)
#   delta-demo.json   -> folder nextcloud-delta   (uid nc-delta)
#
# Inputs (env):  GRAFANA_URL, GRAFANA_TOKEN (minted by mint-grafana-token.sh)
# Idempotent-ish: intended for a fresh CI Grafana; folder creates use overwrite so
# re-runs don't 412, and dashboard writes pass overwrite:true.

set -euo pipefail

: "${GRAFANA_URL:?GRAFANA_URL is required}"
: "${GRAFANA_TOKEN:?GRAFANA_TOKEN is required (run mint-grafana-token.sh first)}"

here="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../dashboards" && pwd)"

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

# Create (or reuse) a folder by uid.
create_folder() {
  # create_folder <uid> <title>
  local uid="$1" title="$2"
  # A 412 (already exists) on a re-run is fine; swallow it.
  api POST '/folders' "{\"uid\":\"$uid\",\"title\":\"$title\"}" >/dev/null 2>&1 || true
}

# Wrap a raw dashboard fixture in the /api/dashboards/db envelope and create it in
# the given folder. `id` is forced null so Grafana treats it as a create.
create_dashboard() {
  # create_dashboard <file> <folderUid>
  local file="$1" folder="$2" body
  body="$(python3 -c '
import sys, json
d = json.load(open(sys.argv[1]))
d["id"] = None
print(json.dumps({"dashboard": d, "folderUid": sys.argv[2],
                  "overwrite": True, "message": "integration preload"}))
' "$file" "$folder")"
  api POST '/dashboards/db' "$body" >/dev/null
}

preload() {
  # preload <file> <folderUid> <folderTitle>
  local file="$1" uid="$2" title="$3"
  create_folder "$uid" "$title"
  create_dashboard "$here/$file" "$uid"
  echo "  loaded $file -> folder $title (uid $uid)"
}

echo "== preloading Grafana with example folders + dashboards =="
preload alpha-demo.json   nc-alpha   nextcloud-alpha
preload bravo-demo.json   nc-bravo   nextcloud-bravo
preload charlie-demo.json nc-charlie nextcloud-charlie
preload delta-demo.json   nc-delta   nextcloud-delta
echo "== preload complete =="
