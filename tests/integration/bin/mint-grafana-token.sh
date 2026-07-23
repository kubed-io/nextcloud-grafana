#!/usr/bin/env bash
#
# Mint a Grafana service-account token for the integration tests — a
# PREREQUISITE, not a feature. The app under test does nothing about how a token
# is created; this only exists so the test can act as "an admin who already
# pasted a valid token".
#
# Unlike n8n, Grafana HAS a headless token mint. With admin basic-auth:
#   POST /api/serviceaccounts               -> create an Editor service account
#   POST /api/serviceaccounts/{id}/tokens   -> returns { key: "<token>" }
#
# Inputs (env):  GRAFANA_URL, GRAFANA_ADMIN_USER, GRAFANA_ADMIN_PASS
# Output:        the raw token on stdout (nothing else). Exits non-zero if it
#                cannot obtain a token — the pipeline must fail loud before tests.

set -euo pipefail

: "${GRAFANA_URL:?GRAFANA_URL is required}"
GRAFANA_ADMIN_USER="${GRAFANA_ADMIN_USER:-admin}"
GRAFANA_ADMIN_PASS="${GRAFANA_ADMIN_PASS:-admin}"

auth="$GRAFANA_ADMIN_USER:$GRAFANA_ADMIN_PASS"
# Unique per run: service-account names must be unique (a duplicate 400s).
name="integration-tests-$(date +%s)-$$"

# 1. Create an Editor service account; capture its numeric id.
sa_id=$(
  curl -fsS -u "$auth" -X POST "$GRAFANA_URL/api/serviceaccounts" \
    -H 'Content-Type: application/json' \
    -d "{\"name\":\"$name\",\"role\":\"Editor\",\"isDisabled\":false}" \
  | sed -n 's/.*"id":\([0-9]*\).*/\1/p'
)
if [ -z "$sa_id" ]; then
  echo "mint-grafana-token: could not create a service account" >&2
  exit 1
fi

# 2. Mint a token on that service account. Grafana returns { ..., "key": "glsa_..." }.
token=$(
  curl -fsS -u "$auth" -X POST "$GRAFANA_URL/api/serviceaccounts/$sa_id/tokens" \
    -H 'Content-Type: application/json' \
    -d "{\"name\":\"$name\"}" \
  | sed -n 's/.*"key":"\([^"]*\)".*/\1/p'
)
if [ -z "$token" ]; then
  echo "mint-grafana-token: /tokens did not return a key" >&2
  exit 1
fi

printf '%s' "$token"
