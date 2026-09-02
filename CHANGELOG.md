# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

<!--
  These ARE the release notes. One line per entry, written for a user — never a
  paragraph. Length tracks impact: functional changes get the most words (still
  one line); refactors/types/tests stay short; CI/devops are shortest. Only
  **BREAKING:** may stretch. Deeper detail lives in the saga or the PR, not here.

  ONLY EVER EDIT THE [Unreleased] SECTION. Every section below it carries a
  version number and is IMMUTABLE — those notes shipped with a release and must
  never be reworded, reordered, or removed. Add new work under [Unreleased].
  See CONTRIBUTING.md / AGENTS.md.
-->

## [Unreleased]

The first release. Everything is new, which is why there is no *Changed* or
*Fixed* below — both are relative to a version somebody is running, and there
isn't one. The build history is in [`saga/`](saga/).

### Added

- **Map a Grafana folder to a Nextcloud folder.** Its subfolders arrive as folders and its dashboards as `.grafana` files, wearing the Grafana icon and the dashboard's own dates. Backed by a Team Folder or a plain shared folder.

- **Real folders, all the way down.** The whole tree mirrors, however deep, in both directions. A folder becomes a Grafana folder when a dashboard lands in it; one holding no dashboards stays an ordinary folder the app never touches.

- **Every file gesture reaches Grafana**: create, edit, rename, move between folders or mappings, copy, delete, restore and purge.

- **A name is one value in three places.** The filename, the JSON `title` and the Grafana title never disagree, whichever one you changed.

- **Exactly one file per dashboard.** Grafana lets dashboards in one folder share a title; their files take a numbered suffix and keep it, and no later sync shuffles which is which.

- **Nothing is matched on filename.** Every file carries its dashboard's uid, so renaming, moving, copying, trashing and restoring never break the link, and re-running a sync never duplicates anything.


- **A recycle bin Grafana never had.** Name any ordinary Grafana folder as the bin and trashing a dashboard file parks its dashboard there instead of deleting it — id, URL and history kept. Restore the file and it comes straight back out. Only emptying the Nextcloud trash deletes anything for good.

- **Restore a trashed folder and its dashboards come back with it**, keeping the uids they left with. A folder that also holds a file this app never managed keeps its trash entry when its dashboards are purged, so that file is still there to restore.

- **It works from the Grafana side too.** Drag a parked dashboard back out of the bin and its trashed file returns; purge the bin and the trashed files follow it out of existence.


- **Tags are one set on three surfaces** — the file, the JSON and Grafana — and any of them can be the one you touched. Folders carry them too, to whatever depth.

- **Sync or Link, chosen per mapping.** `sync` holds the complete dashboard JSON, so the folder doubles as a backup you can open offline; `link` holds a pointer that costs nothing and is read-only from this side.

- **Sync to Grafana** turns `.grafana` files Grafana has never seen into real dashboards, subfolders included.

- **Open in Grafana** is the default click; the raw JSON opens in the text editor, which is a real editing surface here because a dashboard *is* its JSON.

- **Scheduled pulls**, one-shot buttons for either direction, and a connection test.


- **Every admin action has an `occ` command**, so the app can be configured headlessly.

- Grafana uids are published over WebDAV and indexed for search.
