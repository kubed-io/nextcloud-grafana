<?php

/**
 * SPDX-FileCopyrightText: 2026 kubed-io
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\GrafanaSync\Tests\Integration\Steps;

/**
 * What only a version-2 mapping keeps (`dashboards/edit.feature`).
 *
 * A dashboard with TABS is the witness. Tabs exist only in the v2 schema: the classic
 * API reads them as a row panel, and a classic write stores that row — so a dashboard
 * that still has its tabs after an edit is one that was never written through v1.
 * Measured on Grafana 13.2.1 (docs/superpowers/specs/2026-10-14-mapping-version-design.md).
 *
 * The dashboard is made in Grafana and pulled, because a tabbed dashboard cannot be
 * authored any other way: the classic body a Nextcloud file would start from has no
 * way to say "tabs".
 */
trait VersionSteps {
	/**
	 * @Given a dashboard file named :filename in :folder whose dashboard has tabs
	 */
	public function aDashboardFileNamedInWhoseDashboardHasTabs(string $filename, string $folder): void {
		$title = preg_replace('/\.grafana$/', '', $filename) ?? $filename;
		$uid = 'nc-tabs-' . bin2hex(random_bytes(3));

		$grafanaFolder = $this->grafanaFolderUidForMapping($folder);
		$this->grafanaPutDashboardV2($uid, $grafanaFolder, self::tabbedSpec($title));
		$this->createdDashboardUids[] = $uid;
		// A DASHBOARD WRITTEN THROUGH THE RESOURCE API IS SEARCHABLE A MOMENT LATER, not at
		// once, and the pull finds dashboards by searching. Waiting here is the harness
		// letting Grafana settle, the way a person would not notice it had to.
		$this->waitUntilGrafanaSearchFinds($uid, $grafanaFolder);
		$this->pullEveryMapping();

		$this->lastUid = $uid;
		$this->currentFolder = $folder;
		foreach ($this->davListDashboardFiles($folder) as $name) {
			$path = $folder . '/' . $name;
			if ($this->davReadMetadata($path, self::META_UID) === $uid) {
				$this->currentFilePath = $path;
				$this->originalPath = $path;
				return;
			}
		}
		throw new \RuntimeException("the pull did not mirror '$title' into '$folder'; it reported "
			. json_encode($this->lastPullReport));
	}

	/** The pull lists a folder's dashboards by searching it, so that is the question asked. */
	private function waitUntilGrafanaSearchFinds(string $uid, string $folderUid): void {
		for ($i = 0; $i < 40; $i++) {
			$res = $this->grafanaClient()->request('GET', 'search', ['query' => ['folderUIDs' => $folderUid, 'type' => 'dash-db']]);
			$rows = json_decode((string)$res->getBody(), true);
			if (is_array($rows) && in_array($uid, array_column($rows, 'uid'), true)) {
				return;
			}
			usleep(250_000);
		}
		// SAY WHERE IT IS INSTEAD. A fresh CI Grafana has answered differently from a live
		// one here, so the failure carries what each API reports about the dashboard.
		$byUid = json_decode((string)$this->grafanaClient()->request('GET', 'search', ['query' => ['dashboardUIDs' => $uid]])->getBody(), true);
		$classic = $this->grafanaGetDashboard($uid);
		$resource = $this->grafanaGetDashboardV2($uid);
		throw new \RuntimeException("Grafana's search of folder '$folderUid' never listed '$uid'. "
			. 'Search by uid: ' . json_encode($byUid)
			. '; classic meta.folderUid: ' . json_encode($classic['meta']['folderUid'] ?? null)
			. '; v2 folder annotation: ' . json_encode($resource->metadata->annotations->{'grafana.app/folder'} ?? null));
	}

	/** @Then the dashboard in Grafana still has its tabs */
	public function theDashboardInGrafanaStillHasItsTabs(): void {
		$uid = (string)$this->davReadMetadata($this->currentFilePath, self::META_UID);
		$resource = $this->grafanaGetDashboardV2($uid);
		if ($resource === null) {
			throw new \RuntimeException("Grafana has no dashboard '$uid'");
		}
		$layout = (string)($resource->spec->layout->kind ?? '');
		if ($layout !== 'TabsLayout') {
			throw new \RuntimeException("the dashboard's layout is '$layout' — its tabs were flattened");
		}
	}

	/** One text panel on one tab, laid out by the auto grid: nothing classic can say. */
	private static function tabbedSpec(string $title): \stdClass {
		return json_decode(json_encode([
			'title' => $title,
			'elements' => ['panel-1' => ['kind' => 'Panel', 'spec' => [
				'id' => 1, 'title' => 'Hello', 'description' => '', 'links' => [],
				'data' => ['kind' => 'QueryGroup', 'spec' => ['queries' => [], 'transformations' => [], 'queryOptions' => new \stdClass()]],
				'vizConfig' => ['kind' => 'VizConfig', 'group' => 'text', 'version' => '', 'spec' => [
					'options' => ['content' => 'hello', 'mode' => 'markdown'],
					'fieldConfig' => ['defaults' => new \stdClass(), 'overrides' => []],
				]],
			]]],
			'layout' => ['kind' => 'TabsLayout', 'spec' => ['tabs' => [['kind' => 'TabsLayoutTab', 'spec' => [
				'title' => 'One',
				'layout' => ['kind' => 'AutoGridLayout', 'spec' => [
					'maxColumnCount' => 3, 'columnWidthMode' => 'standard', 'rowHeightMode' => 'standard',
					'items' => [['kind' => 'AutoGridLayoutItem', 'spec' => ['element' => ['kind' => 'ElementReference', 'name' => 'panel-1']]]],
				]],
			]]]]],
		], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
	}
}
