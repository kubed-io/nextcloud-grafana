<?php

/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Exception;

/**
 * A failed Grafana HTTP call, normalised so callers get Grafana's *own*
 * complaint verbatim instead of a multi-line Guzzle dump.
 *
 * The message is the human-readable reason Grafana returned (e.g.
 * "Unauthorized" or a validation message) — short enough to drop straight into a
 * toast/notification. `httpStatus` is the response code (0 for transport errors
 * with no response).
 */
final class GrafanaApiException extends \RuntimeException {
	public function __construct(
		string $message,
		public readonly int $httpStatus = 0,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}
}
