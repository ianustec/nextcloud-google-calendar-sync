<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Service;

use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

class IcalConverter {
    public function googleEventToIcal(Event $event, string $uid): string {
        $vcal = new VCalendar();

        $summary = trim($event->getSummary() ?? '');
        $location = trim($event->getLocation() ?? '');
        $description = trim($event->getDescription() ?? '');

        // Extract Meet / conference link
        $conferenceUrl = $this->extractConferenceUrl($event);

        // Append Meet link to description if not already present
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

        // URL property — primary conference link or hangout link
        if ($conferenceUrl !== null) {
            $vevent->add('URL', $conferenceUrl);
        }

        // CONFERENCE property (RFC 7986) — recognised by Nextcloud Calendar
        if ($conferenceUrl !== null) {
            $vevent->add('CONFERENCE', $conferenceUrl, [
                'VALUE' => 'URI',
                'FEATURE' => 'VIDEO',
                'LABEL' => 'Google Meet',
            ]);
        }

        $this->applyGoogleDatesToVEvent($vevent, $event);

        if ($event->getUpdated()) {
            $vevent->DTSTAMP = $this->toSabreDateTime($event->getUpdated());
            $vevent->LASTMODIFIED = $this->toSabreDateTime($event->getUpdated());
        }

        if ($event->getRecurrence() !== null && count($event->getRecurrence()) > 0) {
            $vevent->RRULE = implode(';', $event->getRecurrence());
        }

        return $vcal->serialize();
    }

    private function extractConferenceUrl(Event $event): ?string {
        // hangoutLink is the simplest Google Meet URL
        $hangout = $event->getHangoutLink();
        if ($hangout !== null && $hangout !== '') {
            return $hangout;
        }

        // conferenceData.entryPoints contains video/phone/etc
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
        }

        if (isset($vevent->RRULE)) {
            $event->setRecurrence([(string)$vevent->RRULE]);
        }

        return $event;
    }

    public function extractUid(string $icalData): string {
        $vcal = Reader::read($icalData);
        /** @var VEvent|null $vevent */
        $vevent = $vcal->VEVENT ?? null;
        if ($vevent === null || !isset($vevent->UID)) {
            throw new \InvalidArgumentException('No UID in iCal data');
        }
        return (string)$vevent->UID;
    }

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

    public function googleLastModified(Event $event): ?\DateTimeImmutable {
        $updated = $event->getUpdated();
        if ($updated === null) {
            return null;
        }
        return new \DateTimeImmutable($updated);
    }

    private function applyGoogleDatesToVEvent(VEvent $vevent, Event $event): void {
        $start = $event->getStart();
        $end = $event->getEnd();

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

    private function toSabreDateTime(string $value): string {
        return (new \DateTimeImmutable($value))->format('Ymd\THis\Z');
    }
}
