<!--
SPDX-FileCopyrightText: 2026 kubed-io
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# The saga — deprecated, kept as history

**Frozen on 2026-10-14.** Nothing new is written here. These three chapters
record how the app was built, and the code still cites them ("saga Ch2 §14",
"Ch1 risk #6") for the reasoning behind a decision. Those citations stay valid,
because these files stay.

New design work follows the superpowers standard instead:

| What | Where |
|---|---|
| A design, written before the code | [`docs/superpowers/specs/`](../superpowers/specs/) — `YYYY-MM-DD-<topic>-design.md` |
| The implementation plan for that design | [`docs/superpowers/plans/`](../superpowers/plans/) — `YYYY-MM-DD-<topic>.md` |
| Why a scenario exists | [`features/AGENTS.md`](../../features/AGENTS.md), unchanged |

A spec supersedes a saga decision by naming it. One already has: the
[mapping version](../superpowers/specs/2026-10-14-mapping-version-design.md)
spec reverses Chapter 2 Round 8, which removed the `json`/`yaml` format field.

| Chapter | Covers |
|---|---|
| [Chapter 1 — Mise en Place](Chapter_1_Mise_en_Place.md) | Feasibility against a live Grafana, and the port plan from the n8n master |
| [Chapter 2 — Service for a King](Chapter_2_Service_for_a_King.md) | Building it: pull, push, create, the mode model, the metadata keys |
| [Chapter 3 — The Menu and the Deep Clean](Chapter_3_The_Menu_and_the_Deep_Clean.md) | The duplication-and-dead-code sweep, the store listing, and the 1.0.0 release |
