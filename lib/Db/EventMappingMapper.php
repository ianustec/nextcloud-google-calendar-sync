<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<EventMapping> */
class EventMappingMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'neura_gcal_event_mapping', EventMapping::class);
    }

    /** @return EventMapping[] */
    public function findByCalendar(string $ncCalendarId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('nc_calendar_id', $qb->createNamedParameter($ncCalendarId, IQueryBuilder::PARAM_STR)));
        return $this->findEntities($qb);
    }

    public function findByNcEvent(string $ncCalendarId, string $ncEventUid): ?EventMapping {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('nc_calendar_id', $qb->createNamedParameter($ncCalendarId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('nc_event_uid', $qb->createNamedParameter($ncEventUid, IQueryBuilder::PARAM_STR)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findByGoogleEvent(string $googleCalendarId, string $googleEventId): ?EventMapping {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('google_calendar_id', $qb->createNamedParameter($googleCalendarId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('google_event_id', $qb->createNamedParameter($googleEventId, IQueryBuilder::PARAM_STR)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function deleteByCalendar(string $ncCalendarId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('nc_calendar_id', $qb->createNamedParameter($ncCalendarId, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }
}
