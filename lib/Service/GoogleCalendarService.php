<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Service;

use Google\Client as GoogleClient;
use Google\Service\Calendar;
use Google\Service\Calendar\CalendarListEntry;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\Events;
use Google\Service\Exception as GoogleServiceException;

class GoogleCalendarService {
    private const SCOPE = Calendar::CALENDAR;

    public function __construct(
        private ConfigService $configService,
    ) {
    }

    public function createClientForUser(string $userEmail): GoogleClient {
        $key = $this->configService->getServiceAccountKeyJson();
        if ($key === null) {
            throw new \RuntimeException('Google Service Account key not configured');
        }

        $client = new GoogleClient();
        $client->setAuthConfig($key);
        $client->setScopes([self::SCOPE]);
        $client->setSubject($userEmail);

        return $client;
    }

    /** @return CalendarListEntry[] */
    public function listCalendars(string $userEmail): array {
        $service = new Calendar($this->createClientForUser($userEmail));
        $items = [];
        $pageToken = null;
        do {
            $params = ['maxResults' => 250];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }
            $result = $service->calendarList->listCalendarList($params);
            foreach ($result->getItems() ?? [] as $item) {
                $items[] = $item;
            }
            $pageToken = $result->getNextPageToken();
        } while ($pageToken !== null);

        return $items;
    }

    /**
     * @return array{events: Event[], nextSyncToken: ?string}
     */
    public function listEvents(string $userEmail, string $calendarId, ?string $syncToken = null, ?\DateTimeImmutable $fromDate = null): array {
        $service = new Calendar($this->createClientForUser($userEmail));
        $events = [];
        $pageToken = null;
        $nextSyncToken = null;

        try {
            do {
                $params = [
                    'maxResults' => 250,
                    'singleEvents' => true,
                    'showDeleted' => true,
                ];
                if ($syncToken !== null) {
                    $params['syncToken'] = $syncToken;
                } elseif ($fromDate !== null) {
                    $params['timeMin'] = $fromDate->format(\DateTimeInterface::RFC3339);
                }
                // No fromDate = no timeMin filter = import all events
                if ($pageToken !== null) {
                    $params['pageToken'] = $pageToken;
                }

                /** @var Events $result */
                $result = $service->events->listEvents($calendarId, $params);
                foreach ($result->getItems() ?? [] as $event) {
                    $events[] = $event;
                }
                $pageToken = $result->getNextPageToken();
                if ($pageToken === null) {
                    $nextSyncToken = $result->getNextSyncToken();
                }
            } while ($pageToken !== null);
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 410 && $syncToken !== null) {
                return $this->listEvents($userEmail, $calendarId, null, $fromDate);
            }
            throw $e;
        }

        return ['events' => $events, 'nextSyncToken' => $nextSyncToken];
    }

    public function insertEvent(string $userEmail, string $calendarId, Event $event): Event {
        $service = new Calendar($this->createClientForUser($userEmail));
        return $service->events->insert($calendarId, $event);
    }

    public function updateEvent(string $userEmail, string $calendarId, string $eventId, Event $event): Event {
        $service = new Calendar($this->createClientForUser($userEmail));
        return $service->events->update($calendarId, $eventId, $event);
    }

    public function deleteEvent(string $userEmail, string $calendarId, string $eventId): void {
        $service = new Calendar($this->createClientForUser($userEmail));
        $service->events->delete($calendarId, $eventId);
    }

    public function testConnection(string $userEmail): bool {
        $this->listCalendars($userEmail);
        return true;
    }
}
