<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Application entry point for the Google Workspace Calendar Sync app.
 *
 * Responsibilities:
 *   Registration of background jobs and settings is declared in appinfo/info.xml.
 *   The boot phase loads the Google API client autoloader from vendor/.
 */
class Application extends App implements IBootstrap {

    public const APP_ID = 'neura_google_calendar_sync';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    /**
     * Called by Nextcloud to register services, middleware, and event listeners.
     * Background jobs are registered via info.xml so no action is needed here.
     */
    public function register(IRegistrationContext $context): void {
    }

    /**
     * Called after registration is complete.
     * Loads the Composer autoloader so the Google API client classes are available
     * to all services during the request lifecycle.
     */
    public function boot(IBootContext $context): void {
        $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        }
    }
}
