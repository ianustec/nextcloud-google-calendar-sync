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

/**
 * HTTP controller for the admin settings panel.
 *
 * All endpoints require admin privileges (enforced by @AdminRequired).
 *
 * SyncEngine is resolved lazily from the DI container rather than being
 * injected in the constructor. This isolates the controller from potential
 * dependency chain failures (e.g. missing vendor/ during deployment) and
 * avoids slowing down every settings page load with unnecessary instantiation.
 */
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
     * Persists all admin settings submitted from the settings form.
     *
     * The SA JSON key is only updated when a non-empty value is provided,
     * so reloading the form without uploading a new file does not erase it.
     *
     * @NoAdminRequired
     * @AdminRequired
     *
     * @param string $googleDomain        Google Workspace domain (e.g. "example.com").
     * @param int    $syncIntervalMinutes Background job interval in minutes.
     * @param string $userEmailSuffix     Optional suffix to append to NC usernames.
     * @param string $saJsonKey           Service Account JSON key (raw JSON string).
     * @param string $enabled             "yes" or "no".
     * @param string $syncNcToGoogle      "yes" or "no".
     * @param string $syncGoogleToNc      "yes" or "no".
     * @param string $syncFromDate        ISO date string (YYYY-MM-DD) or empty for no limit.
     * @return DataResponse
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
     * Triggers a synchronous full sync for all users (legacy bulk endpoint).
     *
     * Kept for backward compatibility. The preferred approach is sequential
     * per-user calls via syncSingleUser, which provides live progress in the UI.
     *
     * @NoAdminRequired
     * @AdminRequired
     * @return DataResponse Sync summary with synced, skipped, and failed counts.
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
            'status'      => 'ok',
            'synced'      => $synced,
            'syncedUsers' => $syncedUsers,
            'skipped'     => $skipped,
            'failed'      => $failed,
            'errors'      => $errors,
        ]);
    }

    /**
     * Returns a deduplicated list of users eligible for sync.
     *
     * Only users whose resolved email ends with the configured Google domain
     * are included. When multiple Nextcloud accounts map to the same email
     * (e.g. "m.mazza" and "m.mazza@domain.com"), the account whose UID
     * already contains "@" is preferred because it is more likely to own
     * the CalDAV calendars.
     *
     * @NoAdminRequired
     * @AdminRequired
     * @return DataResponse List of {uid, email, displayName} objects.
     */
    public function listUsers(): DataResponse {
        $byEmail = [];
        $domain  = $this->configService->getGoogleDomain();

        $this->userManager->callForAllUsers(function ($user) use (&$byEmail, $domain): void {
            if (!$user->isEnabled()) {
                return;
            }
            $uid   = $user->getUID();
            $email = $this->configService->resolveUserEmail($uid);
            if ($domain !== '' && !str_ends_with($email, '@' . $domain)) {
                return;
            }
            if (!isset($byEmail[$email]) || str_contains($uid, '@')) {
                $byEmail[$email] = ['uid' => $uid, 'email' => $email, 'displayName' => $user->getDisplayName()];
            }
        });

        return new DataResponse(['status' => 'ok', 'users' => array_values($byEmail)]);
    }

    /**
     * Runs sync for a single user.
     *
     * Called sequentially by the admin UI for each user in the list so that
     * progress can be displayed in real time without a single long-running request.
     *
     * @NoAdminRequired
     * @AdminRequired
     *
     * @param string $userId Nextcloud user ID to sync.
     * @return DataResponse Status "synced", "skipped", or "error" with a message.
     */
    public function syncSingleUser(string $userId = ''): DataResponse {
        if ($userId === '') {
            return new DataResponse(['status' => 'error', 'message' => 'userId required'], 400);
        }
        if (!$this->configService->hasServiceAccountKey()) {
            return new DataResponse(['status' => 'error', 'message' => 'SA key not configured'], 400);
        }

        set_time_limit(3600);

        /** @var SyncEngine $syncEngine */
        $syncEngine = $this->container->get(SyncEngine::class);

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
     * Verifies that the configured Service Account can successfully impersonate
     * a user in the domain and list their Google calendars.
     *
     * Uses the first enabled Nextcloud user as the test subject when $testUser
     * is not provided.
     *
     * @NoAdminRequired
     * @AdminRequired
     *
     * @param string $testUser Optional user ID to test. Falls back to the first enabled user.
     * @return DataResponse Status "ok" with the tested email, or "error" with the exception message.
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

    /**
     * Returns the UID of the first enabled Nextcloud user found by callForSeenUsers.
     *
     * @return string|null UID or null if no enabled users exist.
     */
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
