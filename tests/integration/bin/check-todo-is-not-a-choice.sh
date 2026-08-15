#!/usr/bin/env bash
#
# A @todo WHOSE STEPS ALL EXIST IS NOT A @todo — IT IS A SCENARIO SOMEBODY DECLINED
# TO RUN.
#
# `@todo` means "the code exists; only the test is missing" (features/README.md), and
# CONTRIBUTING.md and AGENTS.md both say a scenario is flipped live **in the same PR
# that lands its code**. Those rules were already written, and were repeatedly not
# followed — the tag is cheap to leave on, nothing failed when it stayed, and each
# deferral looked locally reasonable.
#
# It cost a real bug. `dashboards/copy.feature`'s copy-into-a-mapped-folder scenario
# shipped `@todo`, so nothing exercised a copy end to end, and a copy landing beside
# its source produced a file the app could not see at all — invisible for as long as
# the scenario stayed unrun.
#
# So this makes the deferral visible instead of free. If every step a @todo scenario
# needs is ALREADY DEFINED, the scenario could have been run by simply removing the
# tag, and leaving it on is a choice rather than a limitation. That is the exact case
# this fails on.
#
# It deliberately does NOT fail a @todo whose steps are missing: that is honest work
# not yet done, and the step-definition check already reports it. Nor does it touch
# @unbuilt / @blocked / @decision — those describe the CODE or the harness, not a
# test somebody skipped.
set -euo pipefail

cd "$(dirname "$0")/../../.."

# Behat resolves a step by matching its text against every registered pattern. We do
# not have Behat here (it lives in a separate composer project), so the cheap
# equivalent is: a scenario is runnable when `check-step-definitions.sh` — which does
# know how to match — reports nothing missing for its file with the tag removed.
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
cp -r features "$tmp/features"

offenders=()
while IFS= read -r file; do
  grep -q '@todo' "$file" || continue

  rel="${file#features/}"
  # Strip @todo from this ONE file, leave the rest of the suite as it is, and ask the
  # step checker whether anything in it is now unresolvable.
  cp "$file" "$tmp/one.feature.bak"
  sed -i 's/ @todo//g' "$file"
  missing="$(bash tests/integration/bin/check-step-definitions.sh 2>&1 | grep -c "^    ${rel}:" || true)"
  cp "$tmp/one.feature.bak" "$file"

  if [ "$missing" -eq 0 ]; then
    offenders+=("$rel")
  fi
done < <(find features -name '*.feature' -type f | sort)

if [ ${#offenders[@]} -gt 0 ]; then
  echo "::error::@todo scenarios whose steps ALL already exist — remove the tag and let them run:"
  printf '  %s\n' "${offenders[@]}"
  echo
  echo "A @todo is for a test not yet written, not one written and withheld."
  echo "If a scenario genuinely cannot run, it is @blocked — and must say why."
  exit 1
fi

echo "ok: every @todo is waiting on a step definition, not on a decision"
