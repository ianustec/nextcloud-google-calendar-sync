<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<CalendarMapping> */
class CalendarMappingMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'neura_gcal_calendar_mapping', CalendarMapping::class);
    }

    /** @return CalendarMapping[] */
    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
        return $this->findEntities($qb);
    }

    public function findByNcCalendar(string $userId, string $ncCalendarId): ?CalendarMapping {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('nc_calendar_id', $qb->createNamedParameter($ncCalendarId, IQueryBuilder::PARAM_STR)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    public function findByGoogleCalendar(string $userId, string $googleCalendarId): ?CalendarMapping {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('google_calendar_id', $qb->createNamedParameter($googleCalendarId, IQueryBuilder::PARAM_STR)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
