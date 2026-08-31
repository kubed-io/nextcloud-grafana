<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

use OCP\Files\Node;

/**
 * Who a file gesture is acting for: the session user, else the node's owner.
 * This is the uid a background job re-resolves the node through (team-folder
 * files are mounted per-user) and the user a failure notification addresses.
 * Empty when neither resolves — the caller decides what that means.
 *
 * NOT the rule for tag events ({@see \OCA\GrafanaSync\Listener\TagChangeListener}
 * uses the session alone — a tag change has no owner to borrow) and not the rule
 * for the DAV guard, which likewise only ever has a session. Those two are
 * deliberate non-users; everything else that asks "who is this for?" is here.
 *
 * Ported from the n8n sibling, which collapsed the same six hand-copies.
 */
trait ResolvesActingUser {
	private function actingUserUid(?Node $node): string {
		return $this->userSession->getUser()?->getUID() ?? $node?->getOwner()?->getUID() ?? '';
	}
}
