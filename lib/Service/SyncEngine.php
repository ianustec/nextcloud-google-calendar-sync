<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Service;

use Google\Service\Calendar\Event;
use OCA\NeuraGoogleCalendarSync\Db\CalendarMapping;
use OCA\NeuraGoogleCalendarSync\Service\GoogleSyncSkipException;
use OCA\NeuraGoogleCalendarSync\Db\CalendarMappingMapper;
use OCA\NeuraGoogleCalendarSync\Db\EventMapping;
use OCA\NeuraGoogleCalendarSync\Db\EventMappingMapper;
use Psr\Log\LoggerInterface;

/**
 * Core synchronisation engine: orchestrates bidirectional calendar sync for a single user.
 *
 * Entry point is syncUser(), which:
 *   1. Resolves the user's email address and validates domain membership.
 *   2. Lists Nextcloud calendars (cheap, no external call) and bails early if empty.
 *   3. Lists Google calendars via the Service Account and matches them to NC calendars by name.
 *   4. Delegates each matched pair to syncCalendarPair().
 *
 * Calendar matching strategy:
 *   Calendars are paired by display name (case-insensitive). If the first NC calendar
 *   has no name match in Google, it is paired with the user's primary Google calendar
 *   as a fallback. This covers the common case where a fresh Nextcloud account has a
 *   single "Personal" calendar that should map to the Google primary.
 *
 * Event-level sync (syncCalendarPair):
 *   Google events are iterated first (Google to NC direction). For each event:
 *     Cancelled events trigger deletion of the NC counterpart.
 *     New events (no mapping exists) are created in NC and a mapping row is inserted.
 *     Existing events with changed etags are updated; when both sides changed the
 *     last-modified timestamp is used to pick the winner (last-write-wins).
 *   NC events without an existing mapping are then pushed to Google (NC to Google direction).
 *
 * Both insert operations use upsert semantics (check before insert / update on conflict)
 * to handle re-runs after partial failures without raising unique constraint violations.
 *
 * Sync tokens and CTags are persisted after each successful pair sync so the next
 * run is incremental.
 */
class SyncEngine {

    public function __construct(
        private ConfigService $configService,
        private GoogleCalendarService $googleService,
        private NextcloudCalendarService $ncService,
        private IcalConverter $icalConverter,
        private CalendarMappingMapper $calendarMappingMapper,
        private EventMappingMapper $eventMappingMapper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Runs a full sync cycle for a single user.
     *
     * @param string $userId Nextcloud user ID.
     * @return int Number of calendar pairs that were actually synchronised.
     * @throws GoogleSyncSkipException For known non-fatal conditions (wrong domain, no licence, etc.).
     * @throws \Throwable For unexpected errors that should be counted as failures.
     */
    public function syncUser(string $userId): int {
        $userEmail = $this->configService->resolveUserEmail($userId);

        $domain = $this->configService->getGoogleDomain();
        if ($domain !== '' && !str_ends_with($userEmail, '@' . $domain)) {
            throw new GoogleSyncSkipException("User $userId ($userEmail) is outside domain $domain");
        }

        try {
            $ncCalendars = $this->ncService->listCalendars($userId);
        } catch (\Throwable $e) {
            if (GoogleSyncSkipException::shouldSkip($e)) {
                throw new GoogleSyncSkipException("Skipped $userId", $e);
            }
            throw $e;
        }

        if (empty($ncCalendars)) {
            throw new GoogleSyncSkipException("User $userId has no NC calendars");
        }

        try {
            $googleCalendars = $this->googleService->listCalendars($userEmail);
        } catch (\Throwable $e) {
            if (GoogleSyncSkipException::shouldSkip($e)) {
                throw new GoogleSyncSkipException("Skipped $userId", $e);
            }
            throw $e;
        }

        if (empty($googleCalendars)) {
            throw new GoogleSyncSkipException("User $userId has no Google calendars (no Calendar license?)");
        }

        $googleByName = [];
        $googlePrimary = null;
        foreach ($googleCalendars as $gCal) {
            $name = mb_strtolower(trim($gCal->getSummary() ?? ''));
            if ($name !== '') {
                $googleByName[$name] = $gCal;
            }
            if ($gCal->getPrimary()) {
                $googlePrimary = $gCal;
            }
        }

        $mappedGoogleIds = [];
        $syncedPairs = 0;
        $isFirstNcCal = true;

        foreach ($ncCalendars as $ncCal) {
            $mapping = $this->calendarMappingMapper->findByNcCalendar($userId, $ncCal['id']);
            if ($mapping === null) {
                $nameKey = mb_strtolower(trim($ncCal['displayName']));
                $gCal = $googleByName[$nameKey] ?? null;

                if ($gCal === null && $isFirstNcCal && $googlePrimary !== null) {
                    $gCal = $googlePrimary;
                    $this->logger->info('Calendar name mismatch — mapping NC calendar to Google primary', [
                        'user'          => $userId,
                        'ncCalendar'    => $ncCal['displayName'],
                        'googlePrimary' => $googlePrimary->getSummary(),
                    ]);
                }

                if ($gCal === null) {
                    $isFirstNcCal = false;
                    continue;
                }

                $mapping = new CalendarMapping();
                $mapping->setUserId($userId);
                $mapping->setNcCalendarId($ncCal['id']);
                $mapping->setGoogleCalendarId($gCal->getId());
                $mapping = $this->calendarMappingMapper->insert($mapping);
            }

            $isFirstNcCal = false;
            $mappedGoogleIds[$mapping->getGoogleCalendarId()] = true;
            $this->syncCalendarPair($userId, $userEmail, $ncCal, $mapping);
            $syncedPairs++;
        }

        if ($syncedPairs === 0) {
            throw new GoogleSyncSkipException("User $userId: no matching calendar pairs found");
        }

        return $syncedPairs;
    }

    /**
     * Synchronises a single NC calendar with its matched Google calendar.
     *
     * Processes Google events first (Google to NC), then pushes any NC events
     * that have no mapping yet to Google (NC to Google). Sync state is persisted
     * at the end so the next call to listEvents uses the incremental sync token.
     *
     * @param string          $userId    Nextcloud user ID.
     * @param string          $userEmail Google impersonation email.
     * @param array           $ncCal     NC calendar metadata (id, displayName, ctag).
     * @param CalendarMapping $mapping   Persisted mapping row for this calendar pair.
     */
    private function syncCalendarPair(string $userId, string $userEmail, array $ncCal, CalendarMapping $mapping): void {
        $ncCalendarId    = $ncCal['id'];
        $googleCalendarId = $mapping->getGoogleCalendarId();

        $fromDate = $this->configService->getSyncFromDate();
        $googleResult = $this->googleService->listEvents(
            $userEmail,
            $googleCalendarId,
            $mapping->getGoogleSyncToken(),
            $fromDate
        );

        $ncEvents = $this->ncService->listEvents($ncCalendarId);
        $ncByUid = [];
        foreach ($ncEvents as $ncEvent) {
            try {
                $uid = $this->icalConverter->extractUid($ncEvent['data']);
                $ncByUid[$uid] = $ncEvent;
            } catch (\Throwable) {
                continue;
            }
        }

        $eventMappings  = $this->eventMappingMapper->findByCalendar($ncCalendarId);
        $mappingByNcUid = [];
        $mappingByGoogleId = [];
        foreach ($eventMappings as $em) {
            $mappingByNcUid[$em->getNcEventUid()]       = $em;
            $mappingByGoogleId[$em->getGoogleEventId()] = $em;
        }

        foreach ($googleResult['events'] as $gEvent) {
            $gEventId = $gEvent->getId();
            if ($gEventId === null) {
                continue;
            }

            $em = $mappingByGoogleId[$gEventId] ?? null;

            if ($gEvent->getStatus() === 'cancelled') {
                if ($em !== null) {
                    try {
                        $this->ncService->deleteEvent($ncCalendarId, $em->getNcEventUid());
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to delete NC event for Google cancellation', [
                            'uid'   => $em->getNcEventUid(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $this->eventMappingMapper->delete($em);
                }
                continue;
            }

            if ($em === null) {
                $uid  = $this->generateUid($gEventId);
                $ical = $this->icalConverter->googleEventToIcal($gEvent, $uid);
                $etag = $this->ncService->createEvent($ncCalendarId, $uid, $ical);

                $existingMapping = $this->eventMappingMapper->findByGoogleEvent($googleCalendarId, $gEventId);
                if ($existingMapping !== null) {
                    $existingMapping->setNcEtag($etag);
                    $existingMapping->setGoogleEtag($gEvent->getEtag());
                    $this->eventMappingMapper->update($existingMapping);
                } else {
                    $newMapping = new EventMapping();
                    $newMapping->setUserId($userId);
                    $newMapping->setNcCalendarId($ncCalendarId);
                    $newMapping->setNcEventUid($uid);
                    $newMapping->setGoogleCalendarId($googleCalendarId);
                    $newMapping->setGoogleEventId($gEventId);
                    $newMapping->setNcEtag($etag);
                    $newMapping->setGoogleEtag($gEvent->getEtag());
                    $this->eventMappingMapper->insert($newMapping);
                }
                continue;
            }

            $ncEvent = $ncByUid[$em->getNcEventUid()] ?? null;
            if ($ncEvent === null) {
                $uid  = $em->getNcEventUid();
                $ical = $this->icalConverter->googleEventToIcal($gEvent, $uid);
                $etag = $this->ncService->createEvent($ncCalendarId, $uid, $ical);
                $em->setNcEtag($etag);
                $em->setGoogleEtag($gEvent->getEtag());
                $this->eventMappingMapper->update($em);
                continue;
            }

            $ncChanged = ($em->getNcEtag() ?? '') !== $ncEvent['etag'];
            $gChanged  = ($em->getGoogleEtag() ?? '') !== ($gEvent->getEtag() ?? '');

            if ($ncChanged && $gChanged) {
                $ncLm = $this->icalConverter->extractLastModified($ncEvent['data']);
                $gLm  = $this->icalConverter->googleLastModified($gEvent);
                if ($this->ncWins($ncLm, $gLm)) {
                    $this->pushNcToGoogle($userEmail, $googleCalendarId, $ncEvent, $em);
                } else {
                    $this->pushGoogleToNc($ncCalendarId, $gEvent, $em);
                }
            } elseif ($ncChanged) {
                $this->pushNcToGoogle($userEmail, $googleCalendarId, $ncEvent, $em);
            } elseif ($gChanged) {
                $this->pushGoogleToNc($ncCalendarId, $gEvent, $em);
            }
        }

        foreach ($ncEvents as $ncEvent) {
            try {
                $uid = $this->icalConverter->extractUid($ncEvent['data']);
            } catch (\Throwable) {
                continue;
            }

            if (isset($mappingByNcUid[$uid])) {
                continue;
            }

            $gEvent  = $this->icalConverter->icalToGoogleEvent($ncEvent['data']);
            $created = $this->googleService->insertEvent($userEmail, $googleCalendarId, $gEvent);

            $gEventId = $created->getId();
            if ($gEventId === null) {
                continue;
            }

            $existingNcMapping = $this->eventMappingMapper->findByNcEvent($ncCalendarId, $uid);
            if ($existingNcMapping !== null) {
                $existingNcMapping->setGoogleCalendarId($googleCalendarId);
                $existingNcMapping->setGoogleEventId($gEventId);
                $existingNcMapping->setNcEtag($ncEvent['etag']);
                $existingNcMapping->setGoogleEtag($created->getEtag());
                $this->eventMappingMapper->update($existingNcMapping);
            } else {
                $newMapping = new EventMapping();
                $newMapping->setUserId($userId);
                $newMapping->setNcCalendarId($ncCalendarId);
                $newMapping->setNcEventUid($uid);
                $newMapping->setGoogleCalendarId($googleCalendarId);
                $newMapping->setGoogleEventId($gEventId);
                $newMapping->setNcEtag($ncEvent['etag']);
                $newMapping->setGoogleEtag($created->getEtag());
                $this->eventMappingMapper->insert($newMapping);
            }
        }

        $mapping->setGoogleSyncToken($googleResult['nextSyncToken'] ?? $mapping->getGoogleSyncToken());
        $mapping->setNcCtag($ncCal['ctag'] ?? null);
        $mapping->setLastSyncedAt(time());
        $this->calendarMappingMapper->update($mapping);
    }

    /**
     * Pushes an NC event update to Google.
     *
     * @param string       $userEmail        Google impersonation email.
     * @param string       $googleCalendarId Target Google calendar ID.
     * @param array        $ncEvent          NC event data (uri, etag, data).
     * @param EventMapping $em               Mapping row to update after the push.
     */
    private function pushNcToGoogle(string $userEmail, string $googleCalendarId, array $ncEvent, EventMapping $em): void {
        $gEvent  = $this->icalConverter->icalToGoogleEvent($ncEvent['data']);
        $updated = $this->googleService->updateEvent(
            $userEmail,
            $googleCalendarId,
            $em->getGoogleEventId(),
            $gEvent
        );
        $em->setNcEtag($ncEvent['etag']);
        $em->setGoogleEtag($updated->getEtag());
        $this->eventMappingMapper->update($em);
    }

    /**
     * Pushes a Google event update to Nextcloud.
     *
     * @param string       $ncCalendarId Target NC calendar ID.
     * @param Event        $gEvent       Updated Google event.
     * @param EventMapping $em           Mapping row to update after the push.
     */
    private function pushGoogleToNc(string $ncCalendarId, Event $gEvent, EventMapping $em): void {
        $ical = $this->icalConverter->googleEventToIcal($gEvent, $em->getNcEventUid());
        $etag = $this->ncService->updateEvent($ncCalendarId, $em->getNcEventUid(), $ical);
        $em->setNcEtag($etag);
        $em->setGoogleEtag($gEvent->getEtag());
        $this->eventMappingMapper->update($em);
    }

    /**
     * Resolves a conflict by comparing last-modified timestamps.
     *
     * Returns true when the Nextcloud version should win. When timestamps are
     * equal or both are absent, Nextcloud wins (conservative default).
     *
     * @param \DateTimeImmutable|null $ncLm Nextcloud last-modified timestamp.
     * @param \DateTimeImmutable|null $gLm  Google last-modified timestamp.
     * @return bool True if Nextcloud wins, false if Google wins.
     */
    private function ncWins(?\DateTimeImmutable $ncLm, ?\DateTimeImmutable $gLm): bool {
        if ($ncLm === null && $gLm === null) {
            return true;
        }
        if ($ncLm === null) {
            return false;
        }
        if ($gLm === null) {
            return true;
        }
        return $ncLm >= $gLm;
    }

    /**
     * Generates a stable, deterministic UID for a Google event imported into Nextcloud.
     *
     * The prefix "neura-gcal-" ensures the UID namespace does not collide with
     * UIDs generated by other Nextcloud clients or apps.
     *
     * @param string $googleEventId Google event ID.
     * @return string Stable UID string.
     */
    private function generateUid(string $googleEventId): string {
        return 'neura-gcal-' . md5($googleEventId);
    }
}
