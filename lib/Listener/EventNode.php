<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Listener;

use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Node;

/**
 * The post-event file node of the two events this app treats as "a file landed
 * here": a write carries it as the node, a rename as the target.
 *
 * One reader, because two listeners each carrying their own copy is how the pair
 * drifts — and both {@see CreateInGrafanaListener} and {@see NameSyncListener} are
 * registered on BOTH events, so a divergence would be silent. Ported from the n8n
 * sibling, which collapsed the identical pair.
 */
final class EventNode {
	public static function of(Event $event): ?Node {
		if ($event instanceof NodeWrittenEvent) {
			return $event->getNode();
		}
		if ($event instanceof NodeRenamedEvent) {
			return $event->getTarget();
		}
		return null;
	}
}
