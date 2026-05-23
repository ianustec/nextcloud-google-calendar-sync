<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Controller;

use OCA\NeuraGoogleCalendarSync\AppInfo\Application;
use OCA\NeuraGoogleCalendarSync\Service\ConfigService;
use OCA\NeuraGoogleCalendarSync\Service\GoogleCalendarService;
use OCA\NeuraGoogleCalendarSync\Service\GoogleSyncSkipException;
use OCA\NeuraGoogleCalendarSync\Service\SyncEngine;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

class AdminSettingsController extends Controller {
    public function __construct(
        IRequest $request,
        private ConfigService $configService,
        private GoogleCalendarService $googleService,
        private IUserManager $userManager,
        private ContainerInterface $container,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     * @AdminRequired
     */
    public function save(
        string $googleDomain = '',
        int $syncIntervalMinutes = 15,
        string $userEmailSuffix = '',
        string $saJsonKey = '',
        string $enabled = 'yes',
        string $syncNcToGoogle = 'yes',
        string $syncGoogleToNc = 'yes',
        string $syncFromDate = '',
    ): DataResponse {
        $this->configService->setGoogleDomain($googleDomain);
        $this->configService->setSyncIntervalMinutes($syncIntervalMinutes);
        $this->configService->setUserEmailSuffix($userEmailSuffix);
        $this->configService->setEnabled($enabled === 'yes');
        $this->configService->setSyncNcToGoogle($syncNcToGoogle === 'yes');
        $this->configService->setSyncGoogleToNc($syncGoogleToNc === 'yes');
        if ($syncFromDate !== '') {
            $this->configService->setSyncFromDate($syncFromDate);
        }

        if ($saJsonKey !== '') {
            $this->configService->setServiceAccountKeyJson($saJsonKey);
        }

        return new DataResponse(['status' => 'ok']);
    }

    /**
     * @NoAdminRequired
     * @AdminRequired
     */
    public function syncNow(): DataResponse {
        if (!$this->configService->hasServiceAccountKey()) {
            return new DataResponse(['status' => 'error', 'message' => 'Service Account key not configured'], 400);
        }

        $synced = 0;
        $syncedUsers = [];
        $skipped = 0;
        $failed = 0;
        $errors = [];

        set_time_limit(300);

        /** @var SyncEngine $syncEngine */
        $syncEngine = $this->container->get(SyncEngine::class);

        $this->userManager->callForAllUsers(function ($user) use ($syncEngine, &$synced, &$skipped, &$failed, &$errors): void {
            if (!$user->isEnabled()) {
                return;
            }
            try {
                $pairs = $syncEngine->syncUser($user->getUID());
                if ($pairs > 0) {
                    $synced++;
                    $syncedUsers[] = $user->getUID();
                } else {
                    $skipped++;
                }
            } catch (GoogleSyncSkipException) {
                $skipped++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $user->getUID() . ': ' . $e->getMessage();
            }
        });

        return new DataResponse([
            'status' => 'ok',
            'synced' => $synced,
            'syncedUsers' => $syncedUsers,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    /**
     * @NoAdminRequired
     * @AdminRequired
     */
    public function listUsers(): DataResponse {
        $byEmail = [];
        $domain = $this->configService->getGoogleDomain();

        $this->userManager->callForAllUsers(function ($user) use (&$byEmail, $domain): void {
            if (!$user->isEnabled()) {
                return;
            }
            $uid = $user->getUID();
            $email = $this->configService->resolveUserEmail($uid);
            if ($domain !== '' && !str_ends_with($email, '@' . $domain)) {
                return;
            }
            // Prefer the account whose uid IS the full email (most likely the real account with calendars)
            if (!isset($byEmail[$email]) || str_contains($uid, '@')) {
                $byEmail[$email] = ['uid' => $uid, 'email' => $email, 'displayName' => $user->getDisplayName()];
            }
        });

        return new DataResponse(['status' => 'ok', 'users' => array_values($byEmail)]);
    }

    /**
     * @NoAdminRequired
     * @AdminRequired
     */
    public function syncSingleUser(string $userId = ''): DataResponse {
        if ($userId === '') {
            return new DataResponse(['status' => 'error', 'message' => 'userId required'], 400);
        }
        if (!$this->configService->hasServiceAccountKey()) {
            return new DataResponse(['status' => 'error', 'message' => 'SA key not configured'], 400);
        }

        set_time_limit(120);

        /** @var \OCA\NeuraGoogleCalendarSync\Service\SyncEngine $syncEngine */
        $syncEngine = $this->container->get(\OCA\NeuraGoogleCalendarSync\Service\SyncEngine::class);

        try {
            $pairs = $syncEngine->syncUser($userId);
            if ($pairs > 0) {
                return new DataResponse(['status' => 'synced', 'pairs' => $pairs]);
            }
            return new DataResponse(['status' => 'skipped', 'message' => 'No calendar pairs matched']);
        } catch (GoogleSyncSkipException $e) {
            return new DataResponse(['status' => 'skipped', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], 200);
        }
    }

    /**
     * @NoAdminRequired
     * @AdminRequired
     */
    public function testConnection(string $testUser = ''): DataResponse {
        if ($testUser === '') {
            $testUser = $this->findFirstUserId();
        }
        if ($testUser === null) {
            return new DataResponse(['status' => 'error', 'message' => 'No user found'], 400);
        }

        $email = $this->configService->resolveUserEmail($testUser);
        try {
            $this->googleService->testConnection($email);
            return new DataResponse(['status' => 'ok', 'email' => $email]);
        } catch (\Throwable $e) {
            return new DataResponse(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    private function findFirstUserId(): ?string {
        $found = null;
        $this->userManager->callForSeenUsers(function ($user) use (&$found): void {
            if ($found === null && $user->isEnabled()) {
                $found = $user->getUID();
            }
        });
        return $found;
    }
}
