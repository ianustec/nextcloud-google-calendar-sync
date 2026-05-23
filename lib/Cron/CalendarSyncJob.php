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

    protected function run($argument): void {
        if (!$this->configService->isEnabled()) {
            return;
        }
        if (!$this->configService->hasServiceAccountKey()) {
            $this->logger->warning('NEURA Google Calendar Sync: SA key not configured');
            return;
        }
        if ($this->configService->getGoogleDomain() === '') {
            $this->logger->warning('NEURA Google Calendar Sync: google_domain not configured');
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

        $this->logger->info('NEURA Google Calendar Sync completed', [
            'synced' => $synced,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }
}
