<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Service;

use Google\Client as GoogleClient;
use Google\Service\Calendar;
use Google\Service\Calendar\CalendarListEntry;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\Events;
use Google\Service\Exception as GoogleServiceException;

/**
 * Wraps the Google Calendar API v3 client with Service Account impersonation.
 *
 * Every method accepts a $userEmail parameter and builds a short-lived OAuth2
 * client that impersonates that user via Domain-Wide Delegation (DWD). This
 * means a single Service Account JSON key is used for all users in the domain,
 * with no per-user OAuth flow required.
 *
 * Incremental sync is supported through Google's sync token mechanism:
 * a token received at the end of a full sync is stored and passed on subsequent
 * calls so that only changed events are returned.
 *
 * HTTP 410 (Gone) on a sync token request means the token has expired and a
 * full resync is automatically triggered.
 */
class GoogleCalendarService {

    private const SCOPE = Calendar::CALENDAR;

    public function __construct(
        private ConfigService $configService,
    ) {
    }

    /**
     * Builds an authenticated Google client impersonating the given user.
     *
     * @param string $userEmail Full email address of the user to impersonate.
     * @return GoogleClient Configured and authorised client instance.
     * @throws \RuntimeException If the Service Account key has not been configured.
     */
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

    /**
     * Returns all calendars visible to the given user.
     *
     * Handles pagination automatically; all pages are fetched before returning.
     *
     * @param string $userEmail User to impersonate.
     * @return CalendarListEntry[] List of calendar entries.
     */
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
     * Returns events from a calendar, supporting incremental sync via sync tokens.
     *
     * When $syncToken is provided only events changed since the previous sync are
     * returned. If the token is expired (HTTP 410) a full sync is automatically
     * retried from scratch using $fromDate as the lower bound (or no lower bound
     * if $fromDate is null).
     *
     * @param string              $userEmail   User to impersonate.
     * @param string              $calendarId  Google calendar ID (e.g. "primary").
     * @param string|null         $syncToken   Token from the previous sync run, or null for full sync.
     * @param \DateTimeImmutable|null $fromDate Lower bound for event start times (full sync only).
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
                    'maxResults'  => 250,
                    'singleEvents' => true,
                    'showDeleted' => true,
                ];
                if ($syncToken !== null) {
                    $params['syncToken'] = $syncToken;
                } elseif ($fromDate !== null) {
                    $params['timeMin'] = $fromDate->format(\DateTimeInterface::RFC3339);
                }
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

    /**
     * Creates a new event in the given Google calendar.
     *
     * @param string $userEmail  User to impersonate.
     * @param string $calendarId Target calendar ID.
     * @param Event  $event      Event object to insert.
     * @return Event             Newly created event as returned by the API.
     */
    public function insertEvent(string $userEmail, string $calendarId, Event $event): Event {
        $service = new Calendar($this->createClientForUser($userEmail));
        return $service->events->insert($calendarId, $event);
    }

    /**
     * Updates an existing event in a Google calendar.
     *
     * @param string $userEmail  User to impersonate.
     * @param string $calendarId Calendar containing the event.
     * @param string $eventId    ID of the event to update.
     * @param Event  $event      Updated event data.
     * @return Event             Event as returned by the API after update.
     */
    public function updateEvent(string $userEmail, string $calendarId, string $eventId, Event $event): Event {
        $service = new Calendar($this->createClientForUser($userEmail));
        return $service->events->update($calendarId, $eventId, $event);
    }

    /**
     * Deletes an event from a Google calendar.
     *
     * @param string $userEmail  User to impersonate.
     * @param string $calendarId Calendar containing the event.
     * @param string $eventId    ID of the event to delete.
     */
    public function deleteEvent(string $userEmail, string $calendarId, string $eventId): void {
        $service = new Calendar($this->createClientForUser($userEmail));
        $service->events->delete($calendarId, $eventId);
    }

    /**
     * Verifies that the Service Account can successfully impersonate the user
     * and list their calendars. Throws on any API or auth error.
     *
     * @param string $userEmail User to test impersonation for.
     * @return true Always true on success.
     */
    public function testConnection(string $userEmail): bool {
        $this->listCalendars($userEmail);
        return true;
    }
}
