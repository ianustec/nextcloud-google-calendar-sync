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
                'id'          => (string)$cal['id'],
                'uri'         => (string)($cal['uri'] ?? ''),
                'displayName' => (string)($cal['{DAV:}displayname'] ?? $cal['uri'] ?? 'Calendar'),
                'ctag'        => (string)($cal['getctag'] ?? ''),
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
            $full = $this->calDavBackend->getCalendarObject($id, $obj['uri']);
            if ($full === null) {
                continue;
            }
            $events[] = [
                'uri'  => (string)$full['uri'],
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
        $id  = (int)$calendarId;
        $uri = $this->uidToUri($uid);

        $existing = $this->calDavBackend->getCalendarObject($id, $uri);
        if ($existing !== null) {
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
        $id  = (int)$calendarId;
        $uri = $this->uidToUri($uid);
        $this->calDavBackend->updateCalendarObject($id, $uri, $icalData);
        $obj = $this->calDavBackend->getCalendarObject($id, $uri);
        return (string)($obj['etag'] ?? '');
    }

    /**
     * Removes a calendar object from the given calendar.
     *
     * @param string $calendarId Nextcloud calendar ID.
     * @param string $uid        UID of the event to delete.
     */
    public function deleteEvent(string $calendarId, string $uid): void {
        $this->calDavBackend->deleteCalendarObject((int)$calendarId, $this->uidToUri($uid));
    }

    /**
     * Retrieves a single calendar object by UID.
     *
     * @param string $calendarId Nextcloud calendar ID.
     * @param string $uid        UID of the event to retrieve.
     * @return array{uri: string, etag: string, data: string}|null Object data or null if not found.
     */
    public function getEvent(string $calendarId, string $uid): ?array {
        $id  = (int)$calendarId;
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
