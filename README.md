<div align="center">

<img src="img/app-dark.svg" width="80" alt="Google Workspace Calendar Sync" />

# Google Workspace Calendar Sync for Nextcloud

**by [IANUSTEC](https://ianustec.com)**

_Migrating away from the cloud shouldn't mean losing your data. Nextcloud has everything Google does — and more._

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](LICENSE)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-27--32-0082C9)](https://nextcloud.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4)](https://php.net)

</div>

---

IANUSTEC believes leaving Big Tech infrastructure should be easy. We build open-source Nextcloud addons that bridge the gap between Google / Microsoft services and self-hosted alternatives — so companies can migrate at their own pace, without losing productivity.

This app is our **Google Calendar bridge**: it keeps every user's Nextcloud calendar in perfect sync with Google Workspace, bidirectionally, without any per-user configuration.

---

## Features

| | |
|---|---|
| **Bidirectional sync** | Events flow both ways — Nextcloud ↔ Google — with last-modified-wins conflict resolution |
| **Domain-wide, zero per-user setup** | One Service Account impersonates every user in the domain automatically |
| **All calendars** | Every calendar per user is synced, matched by display name |
| **Incremental sync** | Uses Google sync tokens and Nextcloud CTags — only changed events are transferred |
| **Sync from date** | Optionally limit import to events on or after a specific date |
| **Sync direction control** | Choose one or both directions independently from the admin UI |
| **Meet links & locations** | Google Meet conference links and event locations are preserved |
| **Live progress UI** | Admin panel shows per-user sync status in real time |
| **Automatic & manual sync** | Runs on a configurable cron interval; "Sync Now" button for immediate execution |
| **Smart skip** | Users outside the domain, without a Google Workspace license, or without calendars are silently skipped |

---

## Requirements

- Nextcloud 27–32 with DAV app enabled
- Google Workspace account with admin access
- Google Cloud Service Account with Domain-Wide Delegation
- PHP 8.1+ with `json` and `curl` extensions

---

## Google Workspace Setup

Follow these steps once, as a Google Workspace admin.

### 1 — Enable the Google Calendar API

Go to [Google Cloud Console](https://console.cloud.google.com) → **APIs & Services** → **Library** → search for _Google Calendar API_ → **Enable**.

### 2 — Create a Service Account

**IAM & Admin** → **Service Accounts** → **Create Service Account**.  
Give it a name (e.g. `nextcloud-calendar-sync`). No roles are needed.

### 3 — Enable Domain-Wide Delegation

Open the Service Account → **Details** tab → check **Enable G Suite Domain-wide Delegation** → **Save**.

### 4 — Download the JSON key

Service Account → **Keys** tab → **Add Key** → **Create new key** → **JSON**.  
Save this file — you will upload it in the Nextcloud admin panel.

### 5 — Authorize the SA in Google Workspace Admin

Go to [admin.google.com](https://admin.google.com) → **Security** → **API Controls** → **Domain-wide delegation** → **Add new**:

| Field | Value |
|---|---|
| **Client ID** | The numeric `client_id` from the JSON key file |
| **Scopes** | `https://www.googleapis.com/auth/calendar` |

Click **Authorize**. Allow up to 10 minutes for propagation.

---

## Installation

### Kubernetes

```bash
git clone https://github.com/ianustec/nextcloud-google-calendar-sync
cd nextcloud-google-calendar-sync
./deploy.sh k8s <namespace> <deployment>

# Example
GOOGLE_WORKSPACE_DOMAIN=yourdomain.com ./deploy.sh k8s my-namespace nextcloud
```

### Docker / Docker Compose

```bash
git clone https://github.com/ianustec/nextcloud-google-calendar-sync
cd nextcloud-google-calendar-sync
./deploy.sh docker <container-name>

# Example (container name from docker-compose.yml)
GOOGLE_WORKSPACE_DOMAIN=yourdomain.com ./deploy.sh docker nextcloud
```

The container name is the value of `container_name` in your `docker-compose.yml`, or the name shown by `docker ps`.

### Bare metal / manual

```bash
git clone https://github.com/ianustec/nextcloud-google-calendar-sync
cd nextcloud-google-calendar-sync
./deploy.sh local /var/www/html

# If occ requires root
GOOGLE_WORKSPACE_DOMAIN=yourdomain.com sudo ./deploy.sh local /var/www/nextcloud
```

All three modes handle composer dependencies, file copy, `occ app:enable`, migrations (`occ upgrade`), and web server reload automatically.

---

## Configuration

Open **Nextcloud Admin** → **Additional settings** → **Google Workspace Calendar Sync**.

### Settings reference

| Setting | Description |
|---|---|
| **Enable sync** | Master switch. Disabling stops both cron and manual sync. |
| **Sync direction** | _Google → Nextcloud_: import Google events into NC. _Nextcloud → Google_: push NC events to Google. Both can be active simultaneously. |
| **Google domain** | Your Workspace domain (e.g. `ianustec.com`). Only users matching this domain are synced. |
| **Sync interval** | How often the background job runs, in minutes. Default: 15. |
| **Email suffix** | If Nextcloud usernames are not full email addresses (e.g. `m.mazza` instead of `m.mazza@domain.com`), set this to `@domain.com` to build the impersonation address. |
| **Sync events from date** | Only events on or after this date are imported from Google. Leave empty to sync all events. |
| **Service Account JSON key** | Upload the JSON file downloaded in step 4. Stored encrypted. |

### Test the connection

After saving, click **Test connection** — it will attempt to list calendars for your own account and confirm the SA is correctly authorized.

### Sync Now

Click **Sync Now** to immediately run a full sync for all users. A live table shows each user's status (Synced / Skipped / Failed) as it progresses.

---

## How it works

```
┌─────────────────────────────────────────────────────┐
│  Nextcloud Admin UI (admin.js / AdminSettingsController) │
│  "Sync Now" → sequential per-user AJAX calls         │
└────────────────────┬────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────┐
│  SyncEngine::syncUser(userId)                        │
│  1. List NC calendars (CalDavBackend)                │
│  2. List Google calendars (SA impersonation)         │
│  3. Match pairs by display name                      │
│  4. For each pair → syncCalendarPair()               │
│     a. Google→NC: create/update/delete NC events     │
│     b. NC→Google: push new/changed NC events         │
│     c. Conflict: last-modified wins                  │
│  5. Save sync tokens + etags in mapping tables       │
└─────────────────────────────────────────────────────┘
                     │
          ┌──────────┴──────────┐
          ▼                     ▼
┌──────────────────┐  ┌──────────────────────────┐
│  CalDavBackend   │  │  Google Calendar API v3   │
│  (Nextcloud DAV) │  │  (Service Account + DWD)  │
└──────────────────┘  └──────────────────────────┘
```

**Calendar matching**: calendars are paired by display name. If no match is found, the primary Google calendar is paired with the first Nextcloud calendar.

**Event mapping**: two database tables (`oc_neura_gcal_calendar_mapping`, `oc_neura_gcal_event_mapping`) track which NC event UID corresponds to which Google event ID, enabling incremental sync.

**Skip logic**: users are silently skipped if they are outside the configured domain, don't have a Google Workspace Calendar license (`notACalendarUser`), or have no Nextcloud calendars. They appear as "Skipped" in the UI.

---

## Database migrations

Migrations run automatically on `occ upgrade` after app enable. Two tables are created:

- `oc_neura_gcal_calendar_mapping` — NC ↔ Google calendar ID pairs with sync tokens
- `oc_neura_gcal_event_mapping` — NC event UID ↔ Google event ID pairs with etags

---

## CLI commands

```bash
# Reset sync state for a user (forces full re-sync on next run)
psql -c "UPDATE oc_neura_gcal_calendar_mapping SET google_sync_token = NULL WHERE user_id = 'user@domain.com';"

# Clear all events for a calendar (get calendarid from oc_calendars)
psql -c "DELETE FROM oc_calendarobjects WHERE calendarid = <id>;"
psql -c "DELETE FROM oc_neura_gcal_event_mapping WHERE nc_calendar_id = '<id>';"
psql -c "UPDATE oc_neura_gcal_calendar_mapping SET google_sync_token = NULL WHERE nc_calendar_id = '<id>';"
```

---

## Contributing

Pull requests are welcome. Please open an issue first for significant changes.

This project is part of IANUSTEC's open-source initiative to help companies migrate from Google and Microsoft to self-hosted infrastructure.

---

## License

[GNU Affero General Public License v3.0](LICENSE) — © 2026 [IANUSTEC s.r.l.](https://ianustec.com)
