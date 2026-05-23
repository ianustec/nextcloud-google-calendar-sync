<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'neura_google_calendar_sync';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // CalendarSyncJob registered via info.xml background-jobs
    }

    public function boot(IBootContext $context): void {
        $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        }
    }
}
