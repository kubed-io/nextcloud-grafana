<!--
SPDX-FileCopyrightText: 2026 kubed-io
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Mapping version Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A mapping carries `version` 1 or 2; a v2 mapping reads and writes Grafana's `dashboard.grafana.app/v2` resource and keeps its files as YAML, so editing a v2 dashboard from Nextcloud no longer flattens it.

**Architecture:** One version-neutral body object (`DashboardDocument`) decodes any `.grafana` file (JSON first, YAML second) and answers uid/title/tags for both shapes. `GrafanaClient::writeDocument()` sends a document through the API its own shape names. Only the pull consults the mapping's version, to choose the read and the file format.

**Tech Stack:** PHP 8.2+, Nextcloud 32–34 OCP, symfony/yaml 7.4 prefixed by Strauss, PHPUnit 12, Behat 3.

**Spec:** [`docs/superpowers/specs/2026-10-14-mapping-version-design.md`](../specs/2026-10-14-mapping-version-design.md)

## Global Constraints

- `version` is the int `1` or `2`; omitted or absent in stored config ⇒ `1`. Anything else is refused with `version must be 1 or 2`.
- A version is fixed when the mapping is made; only groups are editable afterwards.
- v1 behaviour is byte-for-byte unchanged: same endpoints, same file bytes, same tests passing untouched.
- v2 endpoints: `GET|PUT /apis/dashboard.grafana.app/v2/namespaces/default/dashboards/{uid}`. Namespace `default`, as `FOLDERS_RESOURCE` already assumes.
- v2 file: `apiVersion: dashboard.grafana.app/v2`, `kind: Dashboard`, `metadata: {name: <uid>}`, `spec`. Nothing else under `metadata`; no `status`.
- A resource body is PUT to the URL of **its own** `apiVersion`; a classic body is POSTed to `/api/dashboards/db`. A classic body is never wrapped in an envelope.
- Every v2 write sets `metadata.annotations["grafana.app/folder"]` (omitted only for the reserved root) and `["grafana.app/message"]`.
- `grafana_version` for v2 = `metadata.generation` from the PUT answer / the GET.
- Mirror clocks for v2: `metadata.creationTimestamp` and the `grafana.app/updatedTimestamp` annotation.
- YAML dump flags: `DUMP_OBJECT_AS_MAP | DUMP_COMPACT_NESTED_MAPPING | DUMP_MULTI_LINE_LITERAL_BLOCK | DUMP_EMPTY_ARRAY_AS_SEQUENCE`, inline `PHP_INT_MAX`, indent 2. Parse flags: `PARSE_OBJECT_FOR_MAP | PARSE_EXCEPTION_ON_INVALID_TYPE`.
- Admin copy is terse: the select reads `v1 · JSON` / `v2 · YAML`.
- Changelog: one functional line under `## [Unreleased]`.

## Review Focus

1. **A v2 dashboard whose conversion failed** (`status.conversion.failed: true`) — the pull must skip it and count it failed, leaving the existing file alone. Test pinned in Task 5.
2. **A YAML body that is not a dashboard** (a list, a scalar, a stray `---` document, a tab-indented file) — push and create must refuse with a message naming YAML, not crash or push `{}`. Test pinned in Task 3.
3. **A v2 file whose `metadata.name` was hand-edited** — the push must still target the uid in Files-Metadata, never the edited name. Test pinned in Task 6.
4. **The reserved-root (`/`) v2 mapping** — writes must omit the folder annotation, and every other write must carry it, or Grafana moves the dashboard to General. Test pinned in Task 4.
5. **An object keyed `"0"`, `"1"`** (value mappings) — must survive a YAML round trip as a map, not a list. Test pinned in Task 2.

---

### Task 1: Ship symfony/yaml, prefixed, and raise the PHP floor

**Files:**
- Modify: `composer.json` — `require: {"php": "^8.2", "symfony/yaml": "^7.4"}`, `replace: {"symfony/polyfill-ctype": "*", "symfony/deprecation-contracts": "*"}`, `extra.strauss` (target `lib/Vendor`, `namespace_prefix` `OCA\\GrafanaSync\\Vendor\\`, `classmap_prefix` `OCA_GrafanaSync_Vendor_`, `delete_vendor_packages: true`), a `prefix-dependencies` script
- Create: `lib/Vendor/**` (Strauss output, committed — per Dr K's answer to the spec's open question)
- Modify: `lib/AppInfo/Application.php` — `require_once __DIR__ . '/../Vendor/autoload.php';` at file scope, above the class
- Modify: `appinfo/info.xml` — `<php min-version="8.2"/>` inside `<dependencies>`
- Modify: `psalm.xml` (`<ignoreFiles><directory name="lib/Vendor"/>`), `.php-cs-fixer.dist.php` (`->notPath('lib/Vendor')`), `composer.json` `lint` script (skip `lib/Vendor`)
- Modify: `tests/bootstrap.php` — require `lib/Vendor/autoload.php`
- Modify: `.github/workflows/integration.yml:142` — `grafana/grafana:13.2.1`
- Modify: `tests/integration/composer.json` — `require-dev` `symfony/yaml: ^7.4` (unprefixed; the harness is not the app)
- Test: `tests/unit/VendorTest.php`

**Interfaces:**
- Produces: `OCA\GrafanaSync\Vendor\Symfony\Component\Yaml\Yaml` (and its `Exception\ParseException`) loadable both in Nextcloud and in the unit suite.

- [ ] **Step 1: Write the failing test** — `VendorTest::testThePrefixedYamlLoads`: `Yaml::parse("a: {}", Yaml::PARSE_OBJECT_FOR_MAP)` returns an object whose `a` is an empty `\stdClass`; `testNoUnprefixedSymfonyIsShipped`: no `lib/Vendor` file declares `namespace Symfony\`.
- [ ] **Step 2: Run** `composer run test:unit -- --filter VendorTest` — Expected: FAIL, class not found.
- [ ] **Step 3: Add the dependency and run Strauss** (`composer require symfony/yaml:^7.4 && composer run prefix-dependencies`); wire the autoload, the floor, the exclusions and the CI image as listed.
- [ ] **Step 4: Run** `composer run test:unit && composer run cs:check && composer run lint` — Expected: PASS; cs and lint do not mention `lib/Vendor`.
- [ ] **Step 5: Commit** — `build: ship symfony/yaml 7.4 prefixed under OCA\GrafanaSync\Vendor`

### Task 2: `DashboardYaml` — the shape-exact codec

**Files:**
- Create: `lib/Service/DashboardYaml.php`
- Test: `tests/unit/Service/DashboardYamlTest.php`, fixture `tests/unit/Fixtures/v2-dashboard.json` (the live `crossplane` v2 spec, trimmed to two elements and a `TabsLayout`)

**Interfaces:**
- Produces: `final class DashboardYaml { public static function dump(\stdClass $doc): string; public static function parse(string $yaml): mixed; }` — `parse` throws `\RuntimeException('The dashboard file is not valid YAML: …')` on a `ParseException`.

- [ ] **Step 1: Write the failing tests**
  - `testRoundTripIsJsonIdentical` — fixture → `dump` → `parse` → `json_encode` equals the fixture's `json_encode` (flags `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION`).
  - `testDumpIsStable` — `dump(parse(dump(x))) === dump(x)`.
  - `testEdgeShapesSurvive` — `{"m":{"0":"a","1":"b"},"e":{},"l":[],"n":null,"f":1.0,"s":"012","t":"true","md":"line1\nline2","nested":[{"a":1,"b":{}},[1,2]]}` round-trips JSON-identical; `m` comes back an object.
  - `testListsOfMapsAreCompact` — dump of `{"a":[{"k":1}]}` contains `"a:\n  - k: 1\n"`.
  - `testMultiLineStringsAreLiteralBlocks` — `md` dumps as `md: |-`.
- [ ] **Step 2: Run** `--filter DashboardYamlTest` — FAIL.
- [ ] **Step 3: Implement.** `dump` converts each non-empty `\stdClass` to an array unless `array_is_list(get_object_vars($o))` (then it stays an object, recursively converted), leaves empty objects as objects, and calls `Yaml::dump($x, PHP_INT_MAX, 2, <flags>)`. `parse` uses the parse flags.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat(yaml): a shape-exact YAML codec for v2 files`

### Task 3: `DashboardDocument` — one body object for both shapes

**Files:**
- Create: `lib/Service/DashboardDocument.php`
- Test: `tests/unit/Service/DashboardDocumentTest.php`

**Interfaces:**
- Consumes: `DashboardYaml`.
- Produces:
  ```php
  final class DashboardDocument {
      public const API_GROUP = 'dashboard.grafana.app';
      public static function decode(string $content): self;          // '' → empty classic; JSON first, YAML second; non-object → RuntimeException
      public static function classic(\stdClass $dashboard): self;
      public static function resource(\stdClass $resource): self;
      public function isResource(): bool;                             // apiVersion starts "dashboard.grafana.app/" && kind === "Dashboard"
      public function apiVersion(): string;                           // "" for classic
      public function uid(): string;   public function withUid(string $uid): self;   public function withoutUid(): self;
      public function title(): string; public function withTitle(string $title): self;
      public function tags(): TagSet;  public function withTags(TagSet $tags): self;
      public function body(): \stdClass;                              // a clone; the dashboard (classic) or the whole resource
      public function encode(): string;                               // classic → JSON_PRETTY; resource → DashboardYaml::dump
  }
  ```
  uid/title/tags live at `uid`/`title`/`tags` (classic) and `metadata.name`/`spec.title`/`spec.tags` (resource). The `with*` methods return a new instance; the original is never mutated.

- [ ] **Step 1: Write the failing tests**
  - `testDecodesJsonAsClassic`, `testDecodesYamlResource`, `testDecodesAJsonResource` (a resource written as JSON is still a resource), `testEmptyFileIsAnEmptyClassicDashboard`.
  - `testRefusesNonDashboards` — data provider: `"[1,2]"`, `"just text"`, `"- a\n- b"`, `"\ta: b"`; each throws `\RuntimeException` whose message contains `JSON` for JSON-looking input and `YAML` otherwise (Review Focus 2).
  - `testUidTitleTagsReadBothShapes`, `testWithUidSetsMetadataNameOnAResource`, `testWithoutUidDropsIt`, `testWithTagsWritesAList` (a gappy TagSet still encodes as a list).
  - `testEncodeKeepsTheShapesFormat` — classic encodes byte-identical to `json_encode($x, DashboardBody::JSON_PRETTY)`; a resource encodes as YAML starting `apiVersion: dashboard.grafana.app/v2`.
- [ ] **Step 2: Run** `--filter DashboardDocumentTest` — FAIL.
- [ ] **Step 3: Implement** in `lib/Service/DashboardDocument.php`.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat: DashboardDocument, one body object for classic and v2 files`

### Task 4: The v2 wire — read, write, encode

**Files:**
- Modify: `lib/Service/GrafanaClient.php` (add after `upsertDashboard`, ~:475)
- Modify: `lib/Service/DashboardBody.php`, `lib/Service/DashboardSpec.php`
- Test: `tests/unit/Service/GrafanaClientTest.php`, `DashboardBodyTest.php`, `DashboardSpecTest.php`

**Interfaces:**
- Consumes: `DashboardDocument`.
- Produces:
  - `GrafanaClient::DASHBOARDS_V2 = '/apis/dashboard.grafana.app/v2/namespaces/default/dashboards'`
  - `GrafanaClient::readDashboardResource(string $uid): ?DashboardSpec` — `spec` holds the whole resource as `\stdClass`; `updated`/`created` from the timestamps; null for a body with no `spec`; throws `GrafanaApiException` when `status.conversion.failed` is true, with Grafana's `status.conversion.error` as the message.
  - `GrafanaClient::writeDocument(DashboardDocument $doc, ?string $folderUid, string $basename = '', string $message = ''): array{uid:string, version:string}` — classic → `toUpsertBody` + `POST /api/dashboards/db`; resource → `DashboardBody::toResourceBody()` + `PUT /apis/<doc apiVersion>/namespaces/default/dashboards/<uid>`; a resource with no uid mints one with `DashboardBody::mintUid()` first.
  - `DashboardSpec::version()` reads `metadata.generation` when the spec is a resource.
  - `DashboardBody::encodeResource(\stdClass $resource): string` — keeps `apiVersion`, `kind`, `metadata.name`, `spec`; YAML.
  - `DashboardBody::encodeReference(array $row, string $url, string $folderUid, int $version = 1): string` — `2` ⇒ the same payload as YAML.
  - `DashboardBody::toResourceBody(DashboardDocument $doc, ?string $folderUid, string $basename, string $message): array` — `metadata` = `{name, annotations: {grafana.app/folder?, grafana.app/message}}`, `spec.title` falls back to the basename like `toUpsertBody`.
  - `DashboardBody::mintUid(): string` — 14 chars of `[a-z0-9]`, Grafana's own shape.

- [ ] **Step 1: Write the failing tests**
  - Client: `testReadDashboardResourceHitsTheV2Url`, `testReadDashboardResourceDatesFromMetadata` (`creationTimestamp` + `grafana.app/updatedTimestamp`; created only when never updated), `testAFailedConversionThrows` (Review Focus 1), `testWriteDocumentPutsAResourceToItsOwnApiVersion` (a `v2beta1` body goes to `/apis/dashboard.grafana.app/v2beta1/…`), `testWriteDocumentPostsAClassicBody` (unchanged URL and envelope), `testWriteDocumentMintsAUidForABornResource`, `testWriteDocumentReturnsGenerationAsVersion`.
  - Body: `testEncodeResourceStripsVolatileMetadataAndStatus`, `testResourceBodySetsFolderAndMessage`, `testResourceBodyOmitsTheFolderForTheRoot` (both `null` and `GrafanaClient::FOLDER_GENERAL`; Review Focus 4), `testReferenceAsYaml`, `testMintUidShape`.
  - Spec: `testVersionIsGenerationForAResource`.
- [ ] **Step 2: Run** the three test classes — FAIL.
- [ ] **Step 3: Implement.** `request()` already JSON-encodes; a PUT needs no new transport.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat(client): read and write dashboards through dashboard.grafana.app/v2`

### Task 5: `Mapping::$version` and the pull

**Files:**
- Modify: `lib/Service/Mapping.php` (field, constants `VERSION_1 = 1`, `VERSION_2 = 2`, `fromArray`/`toArray`/`with*`; replace the "THERE IS NO FORMAT OPTION" section with the spec's rule in a few lines)
- Modify: `lib/Service/SyncService.php` — `writeDashboard()` :607 and `tagsIn()` :874
- Modify: `lib/Command/AddMapping.php` (docblock example + output line), `lib/Command/ListMappings.php` (nothing; `describe()` carries it)
- Test: `tests/unit/Service/MappingTest.php`, `MappingServiceTest.php`, `SyncServiceTest.php`

**Interfaces:**
- Consumes: Task 4.
- Produces: `Mapping::$version` (`public readonly int`, constructor param after `$ncFolderId`, default 1); `toArray()['version']`.

- [ ] **Step 1: Write the failing tests**
  - Mapping: `testVersionDefaultsToOne`, `testAStoredRowWithoutVersionReadsAsOne`, `testAcceptsTwoAsIntOrString`, `testRejectsAnUnknownVersion` (`3`, `"v2"`, `0`), `testWithersKeepTheVersion`, and extend `testToArrayRoundTripsThroughFromArray`.
  - MappingService: `testAddThenListRoundTripsTheVersion`, `testAV2MappingIsRefusedWhenGrafanaHasNoV2` (message `This Grafana does not serve version 2 dashboards (dashboard.grafana.app/v2).`), `testAV1MappingNeverAsksGrafanaAboutVersions`.
  - Client: `testServesDashboardV2ReadsTheGroupVersions` — `GrafanaClient::servesDashboardV2(): bool` from `GET /apis/dashboard.grafana.app` `.versions[].version`; a 404 is `false`.
  - Sync: `testAV2SyncPullWritesTheResourceAsYaml`, `testAV2LinkPullWritesThePointerAsYaml`, `testAV1PullIsUnchanged` (existing fixture bytes), `testAV2PullStampsGenerationAsVersion`, `testAFailedConversionIsAFailedFileAndKeepsTheOldBody` (Review Focus 1), `testTagsComeFromSpecTagsInAV2Body`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** `writeDashboard` branches once: v2 sync reads `readDashboardResource` and encodes `encodeResource`; v2 link passes `2` to `encodeReference`; `tagsIn` decodes through `DashboardDocument`.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat: a mapping's version decides how its folder is read`

### Task 6: Push, create and copy write the body's own way

**Files:**
- Modify: `lib/Service/PushService.php` (:56-107 `push`, :168 `uidInBody`), `lib/Service/CreateService.php` (:63-126, :136-149)
- Test: `tests/unit/Service/PushServiceTest.php`, `CreateServiceTest.php`, `CopyServiceTest.php`

**Interfaces:**
- Consumes: `DashboardDocument`, `GrafanaClient::writeDocument`, `readDashboardResource`.
- Produces: `CreateService::createForFile()` keeps its signature; after creating, if the mapping is v2 and the file arrived classic (or v1 and it arrived as a resource), it rewrites the file in the mapping's format under the `SyncGuard`, then stamps the hash of the bytes it wrote.

- [ ] **Step 1: Write the failing tests**
  - Push: `testAResourceFileIsPutThroughV2`, `testTheMetadataUidWinsOverAnEditedMetadataName` (Review Focus 3), `testAClassicFileInAV2FolderPostsClassic`, `testTagsAreReadFromSpecTags`, `testInvalidYamlIsRefusedWithAMessage`.
  - Create: `testTheStarterInAV2FolderIsCreatedClassicThenRewrittenAsYaml`, `testAYamlFileInAV2FolderIsCreatedThroughV2`, `testABornCopyOfAResourceGetsAMintedUid` (asNewDashboard drops `metadata.name`), `testAV1CreateIsUnchanged`.
  - Copy: `testCopyingAV2FileCreatesAV2Dashboard`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** by swapping each `json_decode` / `toUpsertBody` + `upsertDashboard` pair for `DashboardDocument::decode` + `writeDocument`.
- [ ] **Step 4: Run** — PASS, and the untouched v1 tests in these classes still pass.
- [ ] **Step 5: Commit** — `feat: push, create and copy write a file through the API its body names`

### Task 7: Every other writer — names, tags, delete, motion

**Files:**
- Modify: `lib/Service/TagSyncService.php:135-186`, `lib/Listener/NameSyncListener.php:100`, `lib/BackgroundJob/ReconcileNameJob.php:88-101`, `lib/Service/DeleteService.php` (:68-87, :177-243, :287-297), `lib/Service/MotionService.php` (:184-197, :244-271, :306-342, :364-404, :413-423)
- Test: `TagSyncServiceTest`, `NameSyncListenerTest`, `DeleteServiceTest`, `MotionServiceTest`, new `tests/unit/BackgroundJob/ReconcileNameJobTest.php`

**Interfaces:**
- Consumes: `DashboardDocument`, `GrafanaClient::writeDocument`, `readDashboardResource`.

- [ ] **Step 1: Write the failing tests**
  - Tags: `testATagOnAV2FileLandsInSpecTagsAndStaysYaml`.
  - Names: `testAV2TitleIsSpecTitle` (listener), `testTitleFromFilenameRewritesAV2FileAsYaml` (job).
  - Delete: `testParkingAV2FileInTheBinPutsItThroughV2WithTheBinFolder`, `testRestoringAV2FilePutsItBack`.
  - Motion: `testReparentingAV2FilePutsTheNewFolder`, `testAV2FileMovedIntoAV1MappingIsWrittenThroughV2`, `testDisposingASupersededV2DashboardReadsItThroughV2` (reads the stored dashboard by the target mapping's version, so parking it does not flatten it).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.** Existence checks (`DeleteService` :160/:258, `TrashReconcileService` :532/:677) stay on the classic GET: measured to answer for every stored version on 13.2.1.
- [ ] **Step 4: Run** `composer run test:unit` — the whole suite PASSES.
- [ ] **Step 5: Commit** — `feat: tags, names, the bin and moves keep a v2 dashboard v2`

### Task 8: The admin surface and the docs

**Files:**
- Modify: `templates/mapping_settings.php` (the sealed card shows the version beside the mode, ~:117-124), `js/mapping-settings.js` (`readCard` :131 sends `version`; `buildEmptyCard` :446 adds the select `v1 · JSON` / `v2 · YAML`, default `1`; `sealCard` :359 shows it fixed), the `$desc` / `DESC` help line (one sentence: v2 keeps tabs and auto-grid layouts that v1 would flatten)
- Modify: `README.md` (one paragraph under the mapping section), `CHANGELOG.md` `[Unreleased]`, `AGENTS.md` (the non-negotiables gain "**The version picks the read; the body picks the write.**")
- Test: manual on the dev deploy (Task 9)

- [ ] **Step 1: Implement** the three UI touches and the docs.
- [ ] **Step 2: Run** `npm run build && npm run lint` — PASS.
- [ ] **Step 3: Commit** — `feat(admin): choose a mapping's version`

### Task 9: Gherkin, steps, notes, and the live check

**Files:**
- Modify: `features/mapping/create.feature`, `features/dashboards/create.feature`, `features/dashboards/edit.feature` (exact text below)
- Modify: `tests/integration/bootstrap/Steps/MappingSteps.php` (`addMappingFromForm` :808 sends `version`; `theMappingMatchesTheForm` :393 asserts it), `SyncSteps.php` (`the file holds :contents` :353 learns *the dashboard's full YAML* and *a pointer to the dashboard, in YAML*; `I edit the file's panels and save` :159 and `the dashboard in Grafana holds the file's panels` :173 handle a resource), new steps `a dashboard file named :name in :folder whose dashboard has tabs` and `the dashboard in Grafana still has its tabs` (v2 GET, `spec.layout.kind === 'TabsLayout'`), `Support/GrafanaApiTrait.php` (v2 GET/PUT helpers)
- Modify: `features/AGENTS.md` — `### Creating a mapping saves the form` (a version paragraph), `### There is no way to change a mapping except its groups` (a **version** bullet), `### The mappings in the Background` (the two v2 mappings), new `### An edit in a version-2 mapping keeps its tabs`; correct the stale `grafana_apiVersion` paragraph (~:2390), which claims a key that is not registered.

**`features/mapping/create.feature`** — the defaults table gains `| version   | 1            |`, the submitted table `| version        | <version>   |`, both Examples tables a `version` column; *one field at a time* gains rows `1` and `2`; *in combination* row one takes `2`, row two `1`.

**`features/dashboards/create.feature`** — the Background table gains a `version` column (blank for the three existing rows) and two rows:

```gherkin
      | Dynamic        | Dynamic          | sync | admin folder |        | 2       |
      | dynamic links  | Dynamic Pointers | link | admin folder |        | 2       |
```

*Create a new dashboard in a mapped folder* gains `| Dynamic | Dynamic |`. *Create a dashboard in Grafana* gains:

```gherkin
      | Dynamic           | Dynamic          | sync | set     | the dashboard's full YAML           |
      | dynamic links     | Dynamic Pointers | link | absent  | a pointer to the dashboard, in YAML |
```

**`features/dashboards/edit.feature`** — the Background gains a fourth mapping (`Dynamic` / `Dynamic` / `sync` / `admin folder` / version `2`), and a rule of its own after *Edit a dashboard file outside every mapping*:

```gherkin
    # ── RULE: a version 2 mapping keeps what version 1 would flatten ─────────
    # notes: ../AGENTS.md#an-edit-in-a-version-2-mapping-keeps-its-tabs

  @user @in-nextcloud @gesture @ui
  Scenario: Edit a dashboard file whose dashboard has tabs
    Given a dashboard file named "Fleet Health.grafana" in "Dynamic" whose dashboard has tabs
    When I edit the file's panels and save
    Then the dashboard in Grafana holds the file's panels
    And the dashboard in Grafana still has its tabs
```

- [ ] **Step 1: Edit the three feature files** exactly as above; `vendor/bin/behat --dry-run --strict` in `tests/integration` lists every new line as undefined.
- [ ] **Step 2: Implement the steps**; `--dry-run --strict` is clean, and `bin/check-suites.sh` passes.
- [ ] **Step 3: Write the notes** in `features/AGENTS.md`.
- [ ] **Step 4: Deploy** `SKIP_PULL=1 bash /projects/kubed-io/nextcloud/components/grafana/deploy-dev.sh`, then on the live instance: map a scratch Grafana folder holding one tabbed dashboard as `sync`, version `2`; sync it; open the file (YAML, `kind: Dashboard`); edit a panel title in the Text editor; confirm in Grafana the tabs are still tabs and the title changed. Repeat with a v1 mapping and confirm the JSON file is byte-identical to before this branch. Remove the scratch mapping and folder.
- [ ] **Step 5: Commit** — `test(gherkin): the version on a mapping, in three files`
