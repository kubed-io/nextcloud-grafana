#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Kelly Ferrone
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# TWO STEP TRAITS MAY NOT CONTRIBUTE THE SAME METHOD NAME.
#
# Every file in Steps/ is a trait composed into the one FeatureContext. PHP refuses a
# class whose traits collide on a method name — and it refuses it at COMPILE time, with
# a fatal, before Behat has run a single scenario. So the blast radius is not one test:
# it is every leg of the matrix, all reporting a PHP fatal instead of a result.
#
# That is exactly what happened when `grafanaFolderUidByTitle` was added to SyncSteps
# while TrashSteps already had one. Six legs, one minute each, no scenario executed. The
# helper was genuinely already there; the mistake was grepping for the helpers NEARBY
# rather than for the name being introduced.
#
# `check-step-definitions.sh` answers the neighbouring question — two traits declaring
# the same STEP TEXT — and could not catch this one, because a private helper has no
# step text at all.
set -euo pipefail

cd "$(dirname "$0")/../bootstrap/Steps"

dupes="$(
	grep -hoE '^	p(rivate|ublic|rotected) function [a-zA-Z_][a-zA-Z0-9_]*\(' ./*.php \
		| sed -E 's/.*function ([a-zA-Z_][a-zA-Z0-9_]*)\(/\1/' \
		| sort | uniq -d
)"

if [ -n "$dupes" ]; then
	echo "✘ DUPLICATE TRAIT METHOD — these names are declared in more than one Steps/"
	echo "  trait, and PHP fatals on the composed FeatureContext before any scenario runs:"
	while IFS= read -r name; do
		[ -z "$name" ] && continue
		echo "    $name"
		grep -lE "^	p(rivate|ublic|rotected) function ${name}\(" ./*.php | sed 's/^/      /'
	done <<<"$dupes"
	echo
	echo "  Call the existing one — every trait composes into the same FeatureContext, so"
	echo "  a helper in any of them is already reachable from all of them."
	exit 1
fi

count="$(grep -hcE '^	p(rivate|ublic|rotected) function ' ./*.php | awk '{s+=$1} END {print s}')"
echo "ok: $count trait methods across $(ls ./*.php | wc -l | tr -d ' ') step traits, no name declared twice"
