<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getNcCalendarId()
 * @method void setNcCalendarId(string $ncCalendarId)
 * @method string getGoogleCalendarId()
 * @method void setGoogleCalendarId(string $googleCalendarId)
 * @method string|null getGoogleSyncToken()
 * @method void setGoogleSyncToken(?string $googleSyncToken)
 * @method string|null getNcCtag()
 * @method void setNcCtag(?string $ncCtag)
 * @method int|null getLastSyncedAt()
 * @method void setLastSyncedAt(?int $lastSyncedAt)
 */
class CalendarMapping extends Entity {
    protected $userId;
    protected $ncCalendarId;
    protected $googleCalendarId;
    protected $googleSyncToken;
    protected $ncCtag;
    protected $lastSyncedAt;

    public function __construct() {
        $this->addType('userId', 'string');
        $this->addType('ncCalendarId', 'string');
        $this->addType('googleCalendarId', 'string');
        $this->addType('googleSyncToken', 'string');
        $this->addType('ncCtag', 'string');
        $this->addType('lastSyncedAt', 'integer');
    }
}
