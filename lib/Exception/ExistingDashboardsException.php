<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Exception;

/**
 * A `link` mapping was asked for over a folder that already holds dashboard files
 * (`mapping/create.feature`).
 *
 * ## WHY THIS IS NOT JUST ANOTHER `InvalidArgumentException`
 *
 * Every other refusal in {@see \OCA\GrafanaSync\Service\MappingService::add()} is
 * final: the Grafana folder is taken, the Nextcloud folder is taken, it is the
 * recycle bin. This one is a QUESTION — the admin can answer it, and answering it
 * destroys files. So the two front doors need to tell it apart from the others,
 * and neither should do that by matching on the message:
 *
 *   - the panel turns it into a confirmation and re-submits with the
 *     acknowledgement, which means it needs the COUNT as a number rather than as a
 *     sentence it would have to parse back out — and the FOLDER as the app resolved
 *     it, since an admin who left the field blank took the Grafana folder's title
 *     as the default and the panel never saw it;
 *   - `occ` prints it like any other refusal, because a CLI has nowhere to ask.
 *
 * It extends `InvalidArgumentException` so every existing caller — the command's
 * catch, the controller's 422 — keeps working unchanged; only the code that wants
 * to offer the choice has to know the type exists.
 *
 * Ported from `kubed-io/nextcloud-penpot`, where this shape was worked out.
 */
final class ExistingDashboardsException extends \InvalidArgumentException {
	public function __construct(
		string $message,
		public readonly int $dashboards,
		public readonly string $folder,
	) {
		parent::__construct($message);
	}
}
