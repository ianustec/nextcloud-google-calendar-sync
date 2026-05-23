<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Cron;

use OCA\NeuraGoogleCalendarSync\Service\ConfigService;
use OCA\NeuraGoogleCalendarSync\Service\GoogleSyncSkipException;
use OCA\NeuraGoogleCalendarSync\Service\SyncEngine;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud background job that runs the calendar sync on a configurable interval.
 *
 * Registered via appinfo/info.xml. The interval is read from app config at
 * runtime so changes take effect on the next execution without redeploying.
 *
 * Users that are outside the configured Google domain, lack a Google Calendar
 * licence, or have no Nextcloud calendars are silently counted as "skipped"
 * via GoogleSyncSkipException. Any other error is logged and counted as "failed"
 * without interrupting the remaining users.
 */
class CalendarSyncJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private ConfigService $configService,
        private SyncEngine $syncEngine,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(15 * 60);
    }

    /**
     * Entry point called by the Nextcloud job scheduler.
     *
     * Iterates over all enabled users and delegates per-user sync to SyncEngine.
     * The interval is updated at the start of each run to reflect config changes.
     *
     * @param mixed $argument Unused (required by the TimedJob contract).
     */
    protected function run($argument): void {
        if (!$this->configService->isEnabled()) {
            return;
        }
        if (!$this->configService->hasServiceAccountKey()) {
            $this->logger->warning('Google Calendar Sync: SA key not configured');
            return;
        }
        if ($this->configService->getGoogleDomain() === '') {
            $this->logger->warning('Google Calendar Sync: google_domain not configured');
            return;
        }

        $intervalMinutes = $this->configService->getSyncIntervalMinutes();
        $this->setInterval($intervalMinutes * 60);

        $synced = 0;
        $skipped = 0;
        $failed = 0;

        $this->userManager->callForAllUsers(function ($user) use (&$synced, &$skipped, &$failed): void {
            $userId = $user->getUID();
            if (!$user->isEnabled()) {
                return;
            }
            try {
                $pairs = $this->syncEngine->syncUser($userId);
                if ($pairs > 0) {
                    $synced++;
                } else {
                    $skipped++;
                }
            } catch (GoogleSyncSkipException) {
                $skipped++;
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->error('Calendar sync failed for user', [
                    'user' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $this->logger->info('Google Calendar Sync completed', [
            'synced' => $synced,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }
}
