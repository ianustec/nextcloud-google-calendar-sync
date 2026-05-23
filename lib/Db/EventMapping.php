<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getNcCalendarId()
 * @method void setNcCalendarId(string $ncCalendarId)
 * @method string getNcEventUid()
 * @method void setNcEventUid(string $ncEventUid)
 * @method string getGoogleCalendarId()
 * @method void setGoogleCalendarId(string $googleCalendarId)
 * @method string getGoogleEventId()
 * @method void setGoogleEventId(string $googleEventId)
 * @method string|null getNcEtag()
 * @method void setNcEtag(?string $ncEtag)
 * @method string|null getGoogleEtag()
 * @method void setGoogleEtag(?string $googleEtag)
 */
class EventMapping extends Entity {
    protected $userId;
    protected $ncCalendarId;
    protected $ncEventUid;
    protected $googleCalendarId;
    protected $googleEventId;
    protected $ncEtag;
    protected $googleEtag;

    public function __construct() {
        $this->addType('userId', 'string');
        $this->addType('ncCalendarId', 'string');
        $this->addType('ncEventUid', 'string');
        $this->addType('googleCalendarId', 'string');
        $this->addType('googleEventId', 'string');
        $this->addType('ncEtag', 'string');
        $this->addType('googleEtag', 'string');
    }
}
