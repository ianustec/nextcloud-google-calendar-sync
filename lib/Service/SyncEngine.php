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
     * Returns number of calendar pairs actually synced (0 = nothing to sync, throw = real error).
     */
    public function syncUser(string $userId): int {
        $userEmail = $this->configService->resolveUserEmail($userId);

        $domain = $this->configService->getGoogleDomain();
        if ($domain !== '' && !str_ends_with($userEmail, '@' . $domain)) {
            throw new GoogleSyncSkipException("User $userId ($userEmail) is outside domain $domain");
        }

        // Check NC calendars first — cheap, avoids unnecessary Google API calls
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

        // Index Google calendars by lowercase name
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

                // Fallback: map first NC calendar to Google primary if no name match
                if ($gCal === null && $isFirstNcCal && $googlePrimary !== null) {
                    $gCal = $googlePrimary;
                    $this->logger->info('Calendar name mismatch — mapping NC calendar to Google primary', [
                        'user' => $userId,
                        'ncCalendar' => $ncCal['displayName'],
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

    private function syncCalendarPair(string $userId, string $userEmail, array $ncCal, CalendarMapping $mapping): void {
        $ncCalendarId = $ncCal['id'];
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

        $eventMappings = $this->eventMappingMapper->findByCalendar($ncCalendarId);
        $mappingByNcUid = [];
        $mappingByGoogleId = [];
        foreach ($eventMappings as $em) {
            $mappingByNcUid[$em->getNcEventUid()] = $em;
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
                        $this->logger->warning('Failed to delete NC event from Google cancellation', [
                            'uid' => $em->getNcEventUid(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $this->eventMappingMapper->delete($em);
                }
                continue;
            }

            if ($em === null) {
                $uid = $this->generateUid($gEventId);
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
                $uid = $em->getNcEventUid();
                $ical = $this->icalConverter->googleEventToIcal($gEvent, $uid);
                $etag = $this->ncService->createEvent($ncCalendarId, $uid, $ical);
                $em->setNcEtag($etag);
                $em->setGoogleEtag($gEvent->getEtag());
                $this->eventMappingMapper->update($em);
                continue;
            }

            $ncChanged = ($em->getNcEtag() ?? '') !== $ncEvent['etag'];
            $gChanged = ($em->getGoogleEtag() ?? '') !== ($gEvent->getEtag() ?? '');

            if ($ncChanged && $gChanged) {
                $ncLm = $this->icalConverter->extractLastModified($ncEvent['data']);
                $gLm = $this->icalConverter->googleLastModified($gEvent);
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

            $gEvent = $this->icalConverter->icalToGoogleEvent($ncEvent['data']);
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

    private function pushNcToGoogle(string $userEmail, string $googleCalendarId, array $ncEvent, EventMapping $em): void {
        $gEvent = $this->icalConverter->icalToGoogleEvent($ncEvent['data']);
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

    private function pushGoogleToNc(string $ncCalendarId, Event $gEvent, EventMapping $em): void {
        $ical = $this->icalConverter->googleEventToIcal($gEvent, $em->getNcEventUid());
        $etag = $this->ncService->updateEvent($ncCalendarId, $em->getNcEventUid(), $ical);
        $em->setNcEtag($etag);
        $em->setGoogleEtag($gEvent->getEtag());
        $this->eventMappingMapper->update($em);
    }

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

    private function generateUid(string $googleEventId): string {
        return 'neura-gcal-' . md5($googleEventId);
    }
}
