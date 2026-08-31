<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Settings;

use OCA\GrafanaSync\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\Security\ICrypto;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

/**
 * The Grafana **instance** — the whole connection in one card: the base URL and the
 * service-account token.
 *
 * Unlike the n8n master (which splits Instance / REST-API / Webhook because it has
 * *two* credentials and two channels), Grafana has a **single API and a single
 * token**, so there's nothing to split — URL + token live together under one
 * "Instance" section. The name stays "Instance" to line up with n8n's first
 * section.
 *
 * The token is sent as `Authorization: Bearer` on every request, and it's what the
 * Test connection button (Sync Actions) and the sync chapters authenticate with.
 * Values land in appconfig under app `grafana_sync`; `grafana_token` is `sensitive`
 * so core stores it encrypted and never echoes it back.
 *
 * Because a sensitive field renders **blank** even when a value is stored (core
 * never echoes it), the admin otherwise can't tell "no token yet" from "a token is
 * saved". So the token field's copy is rendered *dynamically* from whether a token
 * is currently stored — a plain, reliable "is it set?" signal. (Whether that token
 * is *valid* is the separate question the Test connection button answers.)
 *
 * ## WHY THIS CARD HANDLES ITS OWN STORAGE, THOUGH IT HAS NO CHECKBOX
 *
 * It has nothing a string cannot hold, so `STORAGE_TYPE_INTERNAL` would work
 * perfectly — for THIS card. It was poisoning the OTHER one.
 *
 * `DeclarativeManager::getStorageType($app, $fieldId)` answers with the FIRST
 * schema registered for the app that declares a `storage_type`, whatever field was
 * asked about — the schema-level `return` sits in the outer loop, so it never gets
 * as far as the schema that actually owns the field:
 *
 *     foreach ($this->appSchemas[$app] as $schema) {
 *         foreach ($schema['fields'] as $field) { ... per-field override ... }
 *         if (array_key_exists('storage_type', $schema)) {
 *             return $schema['storage_type'];   // <- first schema wins, always
 *         }
 *     }
 *
 * This card registers first, so its INTERNAL answer was returned for every field in
 * the app — including {@see AutoSyncSettings}'s checkboxes. Their bool then reached
 * `IAppConfig::setValueString()`, which is typed `string` under `strict_types=1`,
 * so the save died with a TypeError and the toggle sprang back to off. Verified by
 * calling `IDeclarativeManager::setValue()` directly against the live instance:
 *
 *     TypeError: OC\AppConfig::setValueString(): Argument #3 ($value) must be of
 *     type string, true given, called in .../DeclarativeManager.php on line 343
 *
 * AutoSyncSettings declaring EXTERNAL could never have helped — nothing ever asked
 * it. The fix has to be that NO form in the app declares INTERNAL, because any one
 * that does can answer for all the others. Dispatch is unaffected: the EXTERNAL
 * branch resolves the form by `formId`, so each card still gets its own values.
 *
 * The per-field `storage_type` override is not a way out either. It is only reached
 * while iterating the schema that holds the field, and the first schema returns
 * before that. Nor can the key simply be dropped — `validateSchema()` requires it
 * and discards the whole form without it.
 */
final class InstanceSettings implements IDeclarativeSettingsFormWithHandlers {
	private const FIELD_URL = 'grafana_url';
	private const FIELD_TOKEN = 'grafana_token';

	public function __construct(
		private readonly IAppConfig $config,
		private readonly ICrypto $crypto,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		$hasToken = $this->config->getValueString(Application::APP_ID, 'grafana_token', '') !== '';

		$tokenDescription = $hasToken
			? '✓ A token is currently stored (encrypted). Paste a new one to replace it, or use Test connection to check it still works.'
			: 'A Grafana service-account token (role Editor is enough) — create one under Administration → Service accounts in Grafana. Sent as Authorization: Bearer once saved.';
		$tokenPlaceholder = $hasToken
			? '•••••••••••••• — a token is stored (paste to replace)'
			: 'Paste the Grafana service-account token';

		return [
			// NOTE: do NOT prefix the form id with the app id. The settings frontend
			// strips a leading "<app>_" before calling the save API, so a prefixed id
			// (e.g. grafana_sync_instance -> instance) fails the backend's exact-match
			// lookup and sensitive fields get stored unencrypted. A clean id keeps
			// both sides in agreement.
			'id' => 'instance',
			'priority' => 5,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'grafana_sync',
			// EXTERNAL so it cannot answer INTERNAL for another card's checkbox — see
			// the class docblock. The handlers below do exactly what core's internal
			// path did: a plain string for the url, ICrypto for the sensitive token.
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => 'Instance',
			'description' => 'The Grafana instance this app talks to, and the service-account token it authenticates with.',
			'fields' => [
				[
					'id' => self::FIELD_URL,
					'title' => 'Grafana base URL',
					'description' => 'e.g. https://grafana.example.com — no trailing slash.',
					'type' => DeclarativeSettingsTypes::URL,
					'placeholder' => 'https://grafana.example.com',
					'default' => '',
				],
				[
					'id' => self::FIELD_TOKEN,
					'title' => 'Service-account token',
					'description' => $tokenDescription,
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => $tokenPlaceholder,
					'default' => '',
					'sensitive' => true,
				],
			],
		];
	}

	/**
	 * Read one field for the settings UI.
	 *
	 * THE TOKEN IS NEVER ECHOED BACK, which is what core's internal path did for a
	 * `sensitive` field and what the card's copy promises. Returning the decrypted
	 * value would put a live credential in an HTML response for a field the admin
	 * cannot even see.
	 */
	#[\Override]
	public function getValue(string $fieldId, IUser $user): mixed {
		return match ($fieldId) {
			self::FIELD_URL => $this->config->getValueString(Application::APP_ID, self::FIELD_URL, ''),
			self::FIELD_TOKEN => '',
			default => null,
		};
	}

	/**
	 * Persist one field.
	 *
	 * The token is encrypted here because EXTERNAL storage means core no longer does
	 * it — the same `ICrypto` round trip {@see \OCA\GrafanaSync\Command\SetToken}
	 * performs, so a token set from the panel and one set from `occ` are byte-identical
	 * on disk and {@see \OCA\GrafanaSync\Service\GrafanaClient} decrypts either.
	 *
	 * AN EMPTY SUBMISSION LEAVES THE STORED TOKEN ALONE. The field renders blank on
	 * every page load (see getValue), so saving the card after editing only the URL
	 * posts an empty token — and treating that as "clear it" would delete a working
	 * credential as a side effect of an unrelated edit.
	 */
	#[\Override]
	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		switch ($fieldId) {
			case self::FIELD_URL:
				$url = is_string($value) ? rtrim(trim($value), '/') : '';
				$this->config->setValueString(Application::APP_ID, self::FIELD_URL, $url);
				break;
			case self::FIELD_TOKEN:
				$token = is_string($value) ? trim($value) : '';
				if ($token === '') {
					return;
				}
				$this->config->setValueString(
					Application::APP_ID,
					self::FIELD_TOKEN,
					$this->crypto->encrypt($token),
					sensitive: true,
				);
				break;
		}
	}
}
