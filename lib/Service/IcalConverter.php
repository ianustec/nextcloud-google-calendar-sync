<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Service;

use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * Converts between Google Calendar API Event objects and iCalendar strings.
 *
 * Uses sabre/vobject for iCal parsing and serialisation. Key concerns:
 *
 * All-day events: Google represents them as date-only strings ("YYYY-MM-DD").
 * The iCal DATE value type requires the compact form "YYYYMMDD" with no
 * separators, so dashes are stripped before writing DTSTART/DTEND.
 *
 * Google Meet links: extracted from hangoutLink (simplest form) or from
 * conferenceData.entryPoints. They are written as:
 *   URL       for generic calendar client support.
 *   CONFERENCE (RFC 7986) for Nextcloud Calendar's video call button.
 *   Appended to DESCRIPTION as plain text for clients that support neither.
 *
 * Recurrence: RRULE strings are passed through verbatim. Expansion of
 * recurring instances is handled by Google (singleEvents=true in listEvents)
 * and by Nextcloud CalDAV clients respectively.
 */
class IcalConverter {

    /**
     * Converts a Google Calendar Event to an iCalendar string.
     *
     * @param Event  $event Google event to convert.
     * @param string $uid   UID to assign to the VEVENT component.
     * @return string Serialised iCalendar data (VCALENDAR wrapper included).
     */
    public function googleEventToIcal(Event $event, string $uid): string {
        $vcal = new VCalendar();

        $summary     = trim($event->getSummary() ?? '');
        $location    = trim($event->getLocation() ?? '');
        $description = trim($event->getDescription() ?? '');

        $conferenceUrl = $this->extractConferenceUrl($event);

        if ($conferenceUrl !== null && !str_contains($description, $conferenceUrl)) {
            $description = $description !== ''
                ? $description . "\n\nJoin: " . $conferenceUrl
                : 'Join: ' . $conferenceUrl;
        }

        $props = ['UID' => $uid, 'SUMMARY' => $summary];
        if ($description !== '') {
            $props['DESCRIPTION'] = $description;
        }
        if ($location !== '') {
            $props['LOCATION'] = $location;
        }

        $vevent = $vcal->add('VEVENT', $props);

        if ($conferenceUrl !== null) {
            $vevent->add('URL', $conferenceUrl);
            $vevent->add('CONFERENCE', $conferenceUrl, [
                'VALUE'   => 'URI',
                'FEATURE' => 'VIDEO',
                'LABEL'   => 'Google Meet',
            ]);
        }

        $this->applyGoogleDatesToVEvent($vevent, $event);

        if ($event->getUpdated()) {
            $vevent->DTSTAMP      = $this->toSabreDateTime($event->getUpdated());
            $vevent->LASTMODIFIED = $this->toSabreDateTime($event->getUpdated());
        }

        if ($event->getRecurrence() !== null && count($event->getRecurrence()) > 0) {
            $vevent->RRULE = implode(';', $event->getRecurrence());
        }

        return $vcal->serialize();
    }

    /**
     * Resolves the best available video conference URL from a Google Event.
     *
     * Preference order:
     *   1. hangoutLink (direct Google Meet URL, always present for Meet events).
     *   2. conferenceData.entryPoints of type "video" or "more".
     *
     * @param Event $event Google event to inspect.
     * @return string|null Conference URL or null if none found.
     */
    private function extractConferenceUrl(Event $event): ?string {
        $hangout = $event->getHangoutLink();
        if ($hangout !== null && $hangout !== '') {
            return $hangout;
        }

        $conferenceData = $event->getConferenceData();
        if ($conferenceData === null) {
            return null;
        }
        foreach ($conferenceData->getEntryPoints() ?? [] as $ep) {
            if (in_array($ep->getEntryPointType(), ['video', 'more'], true)) {
                $uri = $ep->getUri();
                if ($uri !== null && $uri !== '') {
                    return $uri;
                }
            }
        }
        return null;
    }

    /**
     * Parses an iCalendar string and maps its properties onto a Google Event.
     *
     * When $existing is provided the method updates it in place rather than
     * creating a new instance, which is useful for event updates where the
     * Google event ID must be preserved.
     *
     * @param string    $icalData Raw iCalendar data.
     * @param Event|null $existing Existing Google event to update, or null to create a new one.
     * @return Event Populated Google Calendar Event.
     * @throws \InvalidArgumentException If the iCal data contains no VEVENT component.
     */
    public function icalToGoogleEvent(string $icalData, ?Event $existing = null): Event {
        $event = $existing ?? new Event();
        $vcal = Reader::read($icalData);
        /** @var VEvent|null $vevent */
        $vevent = $vcal->VEVENT ?? null;
        if ($vevent === null) {
            throw new \InvalidArgumentException('No VEVENT in iCal data');
        }

        $event->setSummary((string)($vevent->SUMMARY ?? ''));
        $event->setDescription((string)($vevent->DESCRIPTION ?? ''));
        $event->setLocation((string)($vevent->LOCATION ?? ''));

        if (isset($vevent->DTSTART)) {
            // iCal DATE values (all-day events) have no "T" separator.
            // Google expects "date" for all-day and "dateTime" for timed events.
            $allDay = !str_contains((string)$vevent->DTSTART, 'T');
            if ($allDay) {
                $event->setStart(new EventDateTime([
                    'date' => $vevent->DTSTART->getDateTime()->format('Y-m-d'),
                ]));
            } else {
                $event->setStart(new EventDateTime([
                    'dateTime' => $vevent->DTSTART->getDateTime()->format(\DateTimeInterface::RFC3339),
                    'timeZone' => $vevent->DTSTART->getDateTime()->getTimezone()->getName(),
                ]));
            }
        }

        if (isset($vevent->DTEND)) {
            $allDay = !str_contains((string)$vevent->DTEND, 'T');
            if ($allDay) {
                $event->setEnd(new EventDateTime([
                    'date' => $vevent->DTEND->getDateTime()->format('Y-m-d'),
                ]));
            } else {
                $event->setEnd(new EventDateTime([
                    'dateTime' => $vevent->DTEND->getDateTime()->format(\DateTimeInterface::RFC3339),
                    'timeZone' => $vevent->DTEND->getDateTime()->getTimezone()->getName(),
                ]));
            }
        } elseif (isset($vevent->DURATION) && isset($vevent->DTSTART)) {
            // DURATION is an alternative to DTEND — compute end time from start + duration.
            $endDt = $vevent->DTSTART->getDateTime()->add($vevent->DURATION->getDateInterval());
            $allDay = !str_contains((string)$vevent->DTSTART, 'T');
            if ($allDay) {
                $event->setEnd(new EventDateTime(['date' => $endDt->format('Y-m-d')]));
            } else {
                $event->setEnd(new EventDateTime([
                    'dateTime' => $endDt->format(\DateTimeInterface::RFC3339),
                    'timeZone' => $endDt->getTimezone()->getName(),
                ]));
            }
        } elseif (isset($vevent->DTSTART)) {
            // No end time at all: use start time as end (zero-duration event).
            $allDay = !str_contains((string)$vevent->DTSTART, 'T');
            $startDt = $vevent->DTSTART->getDateTime();
            if ($allDay) {
                $event->setEnd(new EventDateTime(['date' => $startDt->modify('+1 day')->format('Y-m-d')]));
            } else {
                $event->setEnd(new EventDateTime([
                    'dateTime' => $startDt->format(\DateTimeInterface::RFC3339),
                    'timeZone' => $startDt->getTimezone()->getName(),
                ]));
            }
        }

        if (isset($vevent->RRULE)) {
            $event->setRecurrence([(string)$vevent->RRULE]);
        }

        return $event;
    }

    /**
     * Returns a normalized deduplication key for an NC iCal event.
     *
     * Key format: "summary_lowercase|start_utc_unix_timestamp" for timed events,
     * or "summary_lowercase|date_YYYY-MM-DD" for all-day events.
     * Returns null when the event cannot be parsed or has no DTSTART.
     *
     * @param string $icalData Raw iCalendar data.
     * @return string|null Deduplication key or null.
     */
    public function ncEventKey(string $icalData): ?string {
        try {
            $vcal = Reader::read($icalData);
            /** @var VEvent|null $vevent */
            $vevent = $vcal->VEVENT ?? null;
            if ($vevent === null || !isset($vevent->DTSTART)) {
                return null;
            }
            $summary = mb_strtolower(trim((string)($vevent->SUMMARY ?? '')));
            $allDay  = !str_contains((string)$vevent->DTSTART, 'T');
            if ($allDay) {
                return $summary . '|' . $vevent->DTSTART->getDateTime()->format('Y-m-d');
            }
            $ts = $vevent->DTSTART->getDateTime()
                ->setTimezone(new \DateTimeZone('UTC'))
                ->getTimestamp();
            return $summary . '|' . $ts;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Returns a normalized deduplication key for a Google Calendar event.
     *
     * Uses the same format as ncEventKey() so the two indexes can be compared
     * directly during cross-direction deduplication.
     *
     * @param Event $event Google Calendar event.
     * @return string|null Deduplication key or null.
     */
    public function googleEventKey(Event $event): ?string {
        $summary = mb_strtolower(trim($event->getSummary() ?? ''));
        $start   = $event->getStart();
        if ($start === null) {
            return null;
        }
        if ($start->getDate() !== null) {
            return $summary . '|' . $start->getDate();
        }
        if ($start->getDateTime() !== null) {
            try {
                $ts = (new \DateTimeImmutable($start->getDateTime()))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->getTimestamp();
                return $summary . '|' . $ts;
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /**
     * Extracts the UID property from an iCalendar string.
     *
     * @param string $icalData Raw iCalendar data.
     * @return string UID value.
     * @throws \InvalidArgumentException If the data contains no VEVENT or no UID.
     */
    public function extractUid(string $icalData): string {
        $vcal = Reader::read($icalData);
        /** @var VEvent|null $vevent */
        $vevent = $vcal->VEVENT ?? null;
        if ($vevent === null || !isset($vevent->UID)) {
            throw new \InvalidArgumentException('No UID in iCal data');
        }
        return (string)$vevent->UID;
    }

    /**
     * Extracts the last-modified timestamp from an iCalendar string.
     *
     * Falls back to DTSTAMP when LASTMODIFIED is absent, and returns null
     * when neither property is present.
     *
     * @param string $icalData Raw iCalendar data.
     * @return \DateTimeImmutable|null Last modified timestamp or null.
     */
    public function extractLastModified(string $icalData): ?\DateTimeImmutable {
        $vcal = Reader::read($icalData);
        /** @var VEvent|null $vevent */
        $vevent = $vcal->VEVENT ?? null;
        if ($vevent === null) {
            return null;
        }
        if (isset($vevent->LASTMODIFIED)) {
            return \DateTimeImmutable::createFromInterface($vevent->LASTMODIFIED->getDateTime());
        }
        if (isset($vevent->DTSTAMP)) {
            return \DateTimeImmutable::createFromInterface($vevent->DTSTAMP->getDateTime());
        }
        return null;
    }

    /**
     * Extracts the last-modified timestamp from a Google Calendar Event.
     *
     * @param Event $event Google event.
     * @return \DateTimeImmutable|null Updated timestamp or null if absent.
     */
    public function googleLastModified(Event $event): ?\DateTimeImmutable {
        $updated = $event->getUpdated();
        if ($updated === null) {
            return null;
        }
        return new \DateTimeImmutable($updated);
    }

    /**
     * Writes DTSTART and DTEND onto a VEvent from a Google Event's date/dateTime fields.
     *
     * All-day events use the iCal DATE value type (compact "YYYYMMDD" format).
     * Timed events use DATETIME with UTC normalisation.
     *
     * @param VEvent $vevent Target VEvent component.
     * @param Event  $event  Source Google event.
     */
    private function applyGoogleDatesToVEvent(VEvent $vevent, Event $event): void {
        $start = $event->getStart();
        $end   = $event->getEnd();

        if ($start !== null) {
            if ($start->getDate() !== null) {
                // iCal DATE format: YYYYMMDD (no dashes)
                $vevent->add('DTSTART', str_replace('-', '', $start->getDate()), ['VALUE' => 'DATE']);
            } elseif ($start->getDateTime() !== null) {
                $vevent->DTSTART = $this->toSabreDateTime($start->getDateTime());
            }
        }

        if ($end !== null) {
            if ($end->getDate() !== null) {
                $vevent->add('DTEND', str_replace('-', '', $end->getDate()), ['VALUE' => 'DATE']);
            } elseif ($end->getDateTime() !== null) {
                $vevent->DTEND = $this->toSabreDateTime($end->getDateTime());
            }
        }
    }

    /**
     * Converts an RFC3339 or ISO 8601 datetime string to the compact UTC format
     * expected by sabre/vobject for DATETIME properties ("YmdTHisZ").
     *
     * The input may carry any timezone offset (e.g. "+02:00" for CEST).
     * setTimezone('UTC') performs the actual conversion before formatting so
     * that the trailing "Z" (UTC indicator) is accurate. Without this step,
     * a CEST event at 10:00+02:00 would be serialised as "20...T100000Z",
     * which iCal clients interpret as 10:00 UTC — two hours ahead of the
     * original local time.
     *
     * @param string $value Input datetime string.
     * @return string Formatted UTC datetime string.
     */
    private function toSabreDateTime(string $value): string {
        return (new \DateTimeImmutable($value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }
}
