<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Service;

use OCA\DAV\CalDAV\CalDavBackend;

/**
 * Thin wrapper around Nextcloud's CalDavBackend for calendar and event operations.
 *
 * All calendar IDs must be cast to int before being passed to CalDavBackend:
 * Nextcloud stores them as integers in the database but retrieves them as strings
 * in some return values. Passing a string ID causes silent failures (empty result
 * sets) rather than exceptions, which is why every public method enforces the cast.
 *
 * Event URIs follow the convention "<encoded-uid>.ics". The UID is percent-encoded
 * so that special characters do not break the DAV URI path.
 *
 * createEvent implements an upsert: if an object with the same URI already exists
 * (e.g. from a previous partial sync), it is updated rather than causing a
 * duplicate-key error.
 */
class NextcloudCalendarService {

    public function __construct(
        private CalDavBackend $calDavBackend,
    ) {
    }

    /**
     * Returns all VEVENT-capable calendars owned by the given user.
     *
     * Calendars that explicitly list components but do not include VEVENT
     * (e.g. VTODO-only task lists) are excluded.
     *
     * @param string $userId Nextcloud user ID.
     * @return array<int, array{id: string, uri: string, displayName: string, ctag: string}>
     */
    public function listCalendars(string $userId): array {
        $principal = 'principals/users/' . $userId;
        $calendars = $this->calDavBackend->getCalendarsForUser($principal);
        $result = [];
        foreach ($calendars as $cal) {
            $components = (string)($cal['components'] ?? '');
            if ($components !== '' && !str_contains($components, 'VEVENT')) {
                continue;
            }
            $result[] = [
                'id' => (string)$cal['id'],
                'uri' => (string)($cal['uri'] ?? ''),
                'displayName' => (string)($cal['{DAV:}displayname'] ?? $cal['uri'] ?? 'Calendar'),
                'ctag' => (string)($cal['getctag'] ?? ''),
            ];
        }
        return $result;
    }

    /**
     * Returns all calendar objects (events) in the given calendar.
     *
     * Each object is fetched individually to include the full iCal data, because
     * getCalendarObjects only returns metadata (uri, etag) without the payload.
     *
     * @param string $calendarId Nextcloud calendar ID.
     * @return array<int, array{uri: string, etag: string, data: string}>
     */
    public function listEvents(string $calendarId): array {
        $id = (int)$calendarId;
        $objects = $this->calDavBackend->getCalendarObjects($id);
        $events = [];
        foreach ($objects as $obj) {
            // getCalendarObjects returns metadata only (uri, etag, size).
            // A second call per object is needed to get the actual iCal payload.
            $full = $this->calDavBackend->getCalendarObject($id, $obj['uri']);
            if ($full === null) {
                continue;
            }
            $events[] = [
                'uri' => (string)$full['uri'],
                'etag' => (string)($full['etag'] ?? ''),
                'data' => (string)($full['calendardata'] ?? ''),
            ];
        }
        return $events;
    }

    /**
     * Creates or updates a calendar object (upsert).
     *
     * If an object with the derived URI already exists in the calendar the
     * method updates it instead of inserting a duplicate, which prevents unique
     * constraint violations when re-syncing after a partial sync failure.
     *
     * @param string $calendarId Nextcloud calendar ID.
     * @param string $uid        Event UID used to derive the DAV URI.
     * @param string $icalData   Full iCalendar payload.
     * @return string ETag of the stored object.
     */
    public function createEvent(string $calendarId, string $uid, string $icalData): string {
        $id = (int)$calendarId;
        $uri = $this->uidToUri($uid);

        $existing = $this->calDavBackend->getCalendarObject($id, $uri);
        if ($existing !== null) {
            // Object already exists — update instead of create (upsert)
            $this->calDavBackend->updateCalendarObject($id, $uri, $icalData);
        } else {
            $this->calDavBackend->createCalendarObject($id, $uri, $icalData);
        }

        $obj = $this->calDavBackend->getCalendarObject($id, $uri);
        return (string)($obj['etag'] ?? '');
    }

    /**
     * Overwrites an existing calendar object with new iCal data.
     *
     * @param string $calendarId Nextcloud calendar ID.
     * @param string $uid        UID of the event to update.
     * @param string $icalData   Updated iCalendar payload.
     * @return string New ETag of the stored object.
     */
    public function updateEvent(string $calendarId, string $uid, string $icalData): string {
        $id = (int)$calendarId;
        $uri = $this->uidToUri($uid);
        $this->calDavBackend->updateCalendarObject($id, $uri, $icalData);
        $obj = $this->calDavBackend->getCalendarObject($id, $uri);
        return (string)($obj['etag'] ?? '');
    }

    /**
     * Removes a calendar object from the given calendar.
     *
     * CalDAV clients are free to use any filename as the object URI regardless
     * of the event UID, so the derived URI (uid + ".ics") may not match the
     * actual stored filename. This method first tries the derived URI, then
     * falls back to scanning all objects to find the one whose iCal UID matches.
     *
     * @param string $calendarId Nextcloud calendar ID.
     * @param string $uid        UID of the event to delete.
     */
    public function deleteEvent(string $calendarId, string $uid): void {
        $id  = (int)$calendarId;
        $uri = $this->resolveUri($id, $uid);
        if ($uri !== null) {
            $this->calDavBackend->deleteCalendarObject($id, $uri);
        }
    }

    /**
     * Resolves the actual DAV URI for a given UID.
     *
     * Returns null when no matching object is found.
     *
     * @param int    $calendarId Nextcloud calendar ID (integer).
     * @param string $uid        Event UID to look up.
     * @return string|null Actual URI or null.
     */
    private function resolveUri(int $calendarId, string $uid): ?string {
        // Fast path: URI derived from UID (most clients follow this convention).
        $derived = $this->uidToUri($uid);
        if ($this->calDavBackend->getCalendarObject($calendarId, $derived) !== null) {
            return $derived;
        }
        // Slow path: scan all objects to find the one with a matching UID.
        foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $obj) {
            $full = $this->calDavBackend->getCalendarObject($calendarId, $obj['uri']);
            if ($full === null) {
                continue;
            }
            try {
                if ($this->extractUidFromIcal((string)($full['calendardata'] ?? '')) === $uid) {
                    return (string)$obj['uri'];
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    /**
     * Extracts the UID from raw iCalendar data without a full Sabre parse.
     *
     * @param string $icalData Raw iCalendar payload.
     * @return string|null UID value or null if not found.
     */
    private function extractUidFromIcal(string $icalData): ?string {
        if (preg_match('/^UID:(.+)$/m', $icalData, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Retrieves a single calendar object by UID.
     *
     * @param string $calendarId Nextcloud calendar ID.
     * @param string $uid        UID of the event to retrieve.
     * @return array{uri: string, etag: string, data: string}|null Object data or null if not found.
     */
    public function getEvent(string $calendarId, string $uid): ?array {
        $id = (int)$calendarId;
        $uri = $this->uidToUri($uid);
        $obj = $this->calDavBackend->getCalendarObject($id, $uri);
        if ($obj === null) {
            return null;
        }
        return [
            'uri'  => (string)$obj['uri'],
            'etag' => (string)($obj['etag'] ?? ''),
            'data' => (string)($obj['calendardata'] ?? ''),
        ];
    }

    /**
     * Converts a UID to a percent-encoded DAV URI by appending ".ics".
     *
     * @param string $uid Event UID.
     * @return string DAV-safe URI string.
     */
    public function uidToUri(string $uid): string {
        return rawurlencode($uid) . '.ics';
    }

    /**
     * Converts a DAV URI back to the original UID by stripping ".ics" and
     * percent-decoding the result.
     *
     * @param string $uri DAV object URI.
     * @return string Decoded UID.
     */
    public function uriToUid(string $uri): string {
        return rawurldecode(preg_replace('/\.ics$/', '', $uri) ?? $uri);
    }
}
