# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## 1.0.1 – 2026-05-23

### Fixed
- Sync spinner color changed from white (invisible) to Nextcloud blue
- Upsert logic for NC calendar objects: update instead of create when UID already exists (avoids "uid already exists" error on re-sync)
- Upsert logic for event mappings: update instead of insert when mapping already exists (avoids unique constraint violation on re-sync)
- `occ upgrade` now runs automatically in `deploy.sh` so migrations execute on fresh install without manual SQL

### Changed
- `deploy.sh` now requires namespace and deployment as mandatory arguments (no hardcoded defaults)
- `info.xml`: licence updated to SPDX `AGPL-3.0-or-later`, added `repository`, `documentation`, `php` and `lib` dependencies

## 1.0.0 – 2026-05-23

### Added
- Bidirectional sync between Nextcloud and Google Workspace calendars
- Google Service Account with Domain-Wide Delegation for zero per-user configuration
- All calendars per user synced, matched by display name
- Primary calendar fallback: if no name match, primary Google calendar pairs with first NC calendar
- Incremental sync via Google sync tokens and Nextcloud CTags
- Configurable sync direction (Google→NC, NC→Google, or both) from admin UI
- Sync-from-date field to limit historical event import
- Google Meet conference links and event locations preserved in iCal
- Live per-user sync progress table in admin panel
- Cron background job for automatic sync at configurable interval
- Manual "Sync Now" button with real-time status updates
- Smart skip: users outside domain, without Google Calendar license, or without NC calendars are silently skipped
- Database migrations auto-run on `occ upgrade`
- Kubernetes deploy script with composer, migrations, and OPcache reload
- Admin settings: SA JSON file upload (encrypted storage), domain, interval, email suffix, sync direction, sync-from-date
- "Test connection" button to validate SA authorization before enabling sync
