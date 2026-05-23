<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Service;

use OCA\DAV\CalDAV\CalDavBackend;

class NextcloudCalendarService {
    public function __construct(
        private CalDavBackend $calDavBackend,
    ) {
    }

    /** @return array<int, array{id: string, uri: string, displayName: string, ctag: string}> */
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

    /** @return array<int, array{uri: string, etag: string, data: string}> */
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
                'uri' => (string)$full['uri'],
                'etag' => (string)($full['etag'] ?? ''),
                'data' => (string)($full['calendardata'] ?? ''),
            ];
        }
        return $events;
    }

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

    public function updateEvent(string $calendarId, string $uid, string $icalData): string {
        $id = (int)$calendarId;
        $uri = $this->uidToUri($uid);
        $this->calDavBackend->updateCalendarObject($id, $uri, $icalData);
        $obj = $this->calDavBackend->getCalendarObject($id, $uri);
        return (string)($obj['etag'] ?? '');
    }

    public function deleteEvent(string $calendarId, string $uid): void {
        $this->calDavBackend->deleteCalendarObject((int)$calendarId, $this->uidToUri($uid));
    }

    public function getEvent(string $calendarId, string $uid): ?array {
        $id = (int)$calendarId;
        $uri = $this->uidToUri($uid);
        $obj = $this->calDavBackend->getCalendarObject($id, $uri);
        if ($obj === null) {
            return null;
        }
        return [
            'uri' => (string)$obj['uri'],
            'etag' => (string)($obj['etag'] ?? ''),
            'data' => (string)($obj['calendardata'] ?? ''),
        ];
    }

    public function uidToUri(string $uid): string {
        return rawurlencode($uid) . '.ics';
    }

    public function uriToUid(string $uri): string {
        return rawurldecode(preg_replace('/\.ics$/', '', $uri) ?? $uri);
    }
}
