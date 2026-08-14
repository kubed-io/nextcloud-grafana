#!/usr/bin/env bash
#
# EVERY IEventListener DECLARES ITS EVENT TYPE, AND EVERY handle() IS #[\Override].
#
# This exists because the same omission has now landed in three separate PRs — a new
# listener written without `@implements IEventListener<TheEvent>`, caught only by
# Psalm in CI after a full install and analysis run. Psalm is right to catch it; it
# is just the slowest possible place to find out, and the fix is always the same one
# line. A grep takes a second and fails before the branch is pushed.
#
# It is not a style rule. `IEventListener` is a templated interface, so a listener
# without the param has an UNTYPED $event — `handle()` can be passed anything and
# static analysis cannot tell you when the registration and the type check disagree.
# The `#[\Override]` attribute is the same argument one level down: it makes a
# renamed or re-signatured `handle()` a hard error instead of a method that silently
# never runs.
#
# Runs in the quality workflow beside the other text checks, for the same reason they
# do: no services, no install, and a failure in seconds rather than minutes.
set -euo pipefail

cd "$(dirname "$0")/../../.."

missing_implements=()
missing_override=()

while IFS= read -r file; do
  # Only classes that actually implement the interface — a file that merely imports
  # it (Application.php registering listeners) is not a listener.
  grep -qE '^\s*(final\s+)?class\s+\w+.*implements\s+.*IEventListener' "$file" || continue

  grep -qE '@implements\s+IEventListener<' "$file" || missing_implements+=("$file")

  # The attribute has to sit ON handle(), not merely somewhere in the file — so the
  # line before the declaration is what gets read, not the whole file.
  grep -B1 'public function handle(' "$file" | grep -q '#\[\\Override\]' \
    || missing_override+=("$file")
done < <(find lib -name '*.php' -type f | sort)

fail=0
if [ ${#missing_implements[@]} -gt 0 ]; then
  fail=1
  echo "::error::listeners with no @implements IEventListener<TheEvent> — Psalm will reject these:"
  printf '  %s\n' "${missing_implements[@]}"
fi
if [ ${#missing_override[@]} -gt 0 ]; then
  fail=1
  echo "::error::listeners whose handle() is not marked #[\\Override]:"
  printf '  %s\n' "${missing_override[@]}"
fi

if [ "$fail" -ne 0 ]; then
  echo
  echo "Add the docblock param (and the attribute) to each file listed above."
  exit 1
fi

echo "ok: every IEventListener declares its event type and overrides handle()"
