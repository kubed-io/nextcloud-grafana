<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Service;

/**
 * Any `.grafana` file's body, whichever of Grafana's two shapes it is in.
 *
 * | shape | what the file holds | uid | title / tags |
 * |---|---|---|---|
 * | classic | the dashboard JSON `/api/dashboards/uid/*` serves | `uid` | `title` / `tags` |
 * | resource | a `dashboard.grafana.app/*` object: `apiVersion`, `kind`, `metadata`, `spec` | `metadata.name` | `spec.title` / `spec.tags` |
 *
 * ## THE SHAPE DECIDES, NOT THE SYNTAX, AND NOT THE MAPPING
 *
 * A version-2 mapping writes resources as YAML, but a body is a resource because of
 * what it holds: a resource someone saved as JSON is still a resource, and the classic
 * starter the + New menu writes is still classic in a version-2 folder. Whoever writes
 * a body to Grafana sends it through the API its shape belongs to
 * ({@see GrafanaClient::writeDocument()}), which is what lets a file cross between a
 * version-1 and a version-2 mapping without losing anything it holds. Only the pull
 * asks the mapping which shape to WRITE (spec: docs/superpowers/specs/
 * 2026-10-14-mapping-version-design.md).
 *
 * Before this, a dozen call sites decoded the file themselves and reached into
 * `$spec->uid`, `->title` and `->tags` — a classic-only assumption in every one of them.
 *
 * Immutable: every `with*` returns a deep copy, so a caller that still holds the
 * original (to compare, or to retry) is never surprised.
 */
final class DashboardDocument {
	public const API_GROUP = 'dashboard.grafana.app';

	private function __construct(
		private readonly \stdClass $body,
		private readonly bool $resource,
	) {
	}

	/**
	 * JSON first, YAML second — so a folder of JSON never reaches the YAML parser.
	 *
	 * Empty content is an empty classic dashboard: the + New menu and a desktop client
	 * both create a file before they fill it, and Grafana mints a dashboard from nothing
	 * the way it always has ({@see DashboardBody::toUpsertBody()}).
	 *
	 * @throws \RuntimeException naming the syntax that failed, for the user's notification
	 */
	public static function decode(string $content): self {
		if (trim($content) === '') {
			return new self(new \stdClass(), false);
		}
		try {
			$decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			// Something that opens like JSON is reported as JSON: a missing brace in a
			// classic file should not come back as a YAML error the user never wrote.
			if (in_array(ltrim($content)[0], ['{', '['], true)) {
				throw new \RuntimeException('The dashboard file is not valid JSON: ' . $e->getMessage(), 0, $e);
			}
			$decoded = DashboardYaml::parse($content);
			if (!$decoded instanceof \stdClass) {
				throw new \RuntimeException('The dashboard file is not a YAML map.');
			}
			return self::of($decoded);
		}
		if (!$decoded instanceof \stdClass) {
			throw new \RuntimeException('The dashboard file is not a JSON object.');
		}
		return self::of($decoded);
	}

	/**
	 * {@see decode()}, for a file that already IS a dashboard — the one being pushed,
	 * parked, restored, moved or tagged.
	 *
	 * Empty content reads as a dashboard-to-be for a file being born, and that is ruinous
	 * here: sending it would replace a real dashboard with an empty one. So an emptied
	 * file is refused, as it was when every one of these call sites decoded JSON itself.
	 */
	public static function decodeNonEmpty(string $content): self {
		if (trim($content) === '') {
			throw new \RuntimeException('The dashboard file is empty.');
		}
		return self::decode($content);
	}

	public static function classic(\stdClass $dashboard): self {
		return new self(self::copy($dashboard), false);
	}

	public static function resource(\stdClass $resource): self {
		return new self(self::copy($resource), true);
	}

	private static function of(\stdClass $body): self {
		$apiVersion = $body->apiVersion ?? null;
		$isResource = is_string($apiVersion)
			&& str_starts_with($apiVersion, self::API_GROUP . '/')
			&& ($body->kind ?? null) === 'Dashboard';
		return new self($body, $isResource);
	}

	public function isResource(): bool {
		return $this->resource;
	}

	/** `dashboard.grafana.app/v2`, `…/v2beta1`, …; empty for a classic body. */
	public function apiVersion(): string {
		return $this->resource ? (string)$this->body->apiVersion : '';
	}

	public function uid(): string {
		$uid = $this->resource ? ($this->metadata()->name ?? null) : ($this->body->uid ?? null);
		return is_string($uid) ? trim($uid) : '';
	}

	public function withUid(string $uid): self {
		$copy = self::copy($this->body);
		if ($this->resource) {
			$copy->metadata = self::objectAt($copy, 'metadata');
			$copy->metadata->name = $uid;
		} else {
			$copy->uid = $uid;
		}
		return new self($copy, $this->resource);
	}

	public function withoutUid(): self {
		$copy = self::copy($this->body);
		if ($this->resource) {
			if (isset($copy->metadata) && $copy->metadata instanceof \stdClass) {
				unset($copy->metadata->name);
			}
		} else {
			unset($copy->uid);
		}
		return new self($copy, $this->resource);
	}

	public function title(): string {
		$title = $this->fields()->title ?? null;
		return is_string($title) ? trim($title) : '';
	}

	public function withTitle(string $title): self {
		$copy = self::copy($this->body);
		$this->fieldsOf($copy)->title = $title;
		return new self($copy, $this->resource);
	}

	public function tags(): TagSet {
		$tags = $this->fields()->tags ?? null;
		return TagSet::of(is_array($tags) ? $tags : []);
	}

	/** Always a list: `json_encode` writes a gappy array as `{"0": …}`, which is not tags. */
	public function withTags(TagSet $tags): self {
		$copy = self::copy($this->body);
		$this->fieldsOf($copy)->tags = $tags->toList();
		return new self($copy, $this->resource);
	}

	/** A deep copy: the dashboard object (classic) or the whole resource. */
	public function body(): \stdClass {
		return self::copy($this->body);
	}

	/** Each shape in its own syntax: classic as JSON, a resource as YAML. */
	public function encode(): string {
		return $this->resource
			? DashboardYaml::dump($this->body)
			: json_encode($this->body, DashboardBody::JSON_PRETTY);
	}

	/** Where title and tags live: the dashboard itself, or the resource's spec. */
	private function fields(): \stdClass {
		if (!$this->resource) {
			return $this->body;
		}
		$spec = $this->body->spec ?? null;
		return $spec instanceof \stdClass ? $spec : new \stdClass();
	}

	private function fieldsOf(\stdClass $copy): \stdClass {
		if (!$this->resource) {
			return $copy;
		}
		$copy->spec = self::objectAt($copy, 'spec');
		return $copy->spec;
	}

	private function metadata(): \stdClass {
		$meta = $this->body->metadata ?? null;
		return $meta instanceof \stdClass ? $meta : new \stdClass();
	}

	private static function objectAt(\stdClass $on, string $key): \stdClass {
		$value = $on->{$key} ?? null;
		return $value instanceof \stdClass ? $value : new \stdClass();
	}

	/** Exact, unlike a JSON round trip, which turns `1.0` into `1`. */
	private static function copy(\stdClass $body): \stdClass {
		$copy = unserialize(serialize($body), ['allowed_classes' => [\stdClass::class]]);
		assert($copy instanceof \stdClass);
		return $copy;
	}
}
