<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Service;

use OCA\NeuraGoogleCalendarSync\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;

/**
 * Centralises all app configuration stored via Nextcloud's IConfig.
 *
 * The Service Account JSON key is encrypted at rest using Nextcloud's ICrypto
 * (AES-256-GCM). A fallback to unencrypted storage is provided for keys that
 * were saved before encryption was introduced.
 *
 * User email resolution follows this priority order:
 *   1. The userId already contains an "@" symbol (it is a full email address).
 *   2. An explicit email suffix is configured (e.g. "@example.com").
 *   3. The google_domain setting is used as the fallback domain.
 */
class ConfigService {

    public const KEY_SA_JSON          = 'sa_json_key';
    public const KEY_GOOGLE_DOMAIN    = 'google_domain';
    public const KEY_SYNC_INTERVAL    = 'sync_interval_minutes';
    public const KEY_USER_EMAIL_SUFFIX = 'user_email_suffix';
    public const KEY_ENABLED          = 'enabled';
    public const KEY_SYNC_NC_TO_GOOGLE = 'sync_nc_to_google';
    public const KEY_SYNC_GOOGLE_TO_NC = 'sync_google_to_nc';
    public const KEY_SYNC_FROM_DATE   = 'sync_from_date';

    public function __construct(
        private IConfig $config,
        private ICrypto $crypto,
    ) {
    }

    public function isEnabled(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_ENABLED, 'yes') === 'yes';
    }

    public function setEnabled(bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_ENABLED, $enabled ? 'yes' : 'no');
    }

    public function getGoogleDomain(): string {
        return trim($this->config->getAppValue(Application::APP_ID, self::KEY_GOOGLE_DOMAIN, ''));
    }

    public function setGoogleDomain(string $domain): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_GOOGLE_DOMAIN, trim($domain));
    }

    /** Minimum enforced value is 5 minutes to avoid hammering the Google API. */
    public function getSyncIntervalMinutes(): int {
        return max(5, (int)$this->config->getAppValue(Application::APP_ID, self::KEY_SYNC_INTERVAL, '15'));
    }

    public function setSyncIntervalMinutes(int $minutes): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_SYNC_INTERVAL, (string)max(5, $minutes));
    }

    public function getUserEmailSuffix(): string {
        return trim($this->config->getAppValue(Application::APP_ID, self::KEY_USER_EMAIL_SUFFIX, ''));
    }

    public function setUserEmailSuffix(string $suffix): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_USER_EMAIL_SUFFIX, trim($suffix));
    }

    public function hasServiceAccountKey(): bool {
        return $this->getServiceAccountKeyJson() !== null;
    }

    /**
     * Decrypts and parses the stored Service Account JSON key.
     *
     * Returns null if no key is stored or if decryption/parsing fails.
     * Falls back to treating the stored value as plain JSON when decryption
     * throws (compatibility with keys saved before encryption was added).
     *
     * @return array<string, mixed>|null Parsed SA key or null on failure.
     */
    public function getServiceAccountKeyJson(): ?array {
        $encrypted = $this->config->getAppValue(Application::APP_ID, self::KEY_SA_JSON, '');
        if ($encrypted === '') {
            return null;
        }
        try {
            try {
                $json = $this->crypto->decrypt($encrypted);
            } catch (\Throwable) {
                $json = $encrypted;
            }
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Validates, encrypts, and persists the Service Account JSON key.
     * Passing an empty string removes the stored key.
     *
     * @throws \JsonException If the provided string is not valid JSON.
     */
    public function setServiceAccountKeyJson(string $json): void {
        if ($json === '') {
            $this->config->deleteAppValue(Application::APP_ID, self::KEY_SA_JSON);
            return;
        }
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->config->setAppValue(Application::APP_ID, self::KEY_SA_JSON, $this->crypto->encrypt($json));
    }

    /** Whether events should be pushed from Nextcloud to Google. */
    public function isSyncNcToGoogle(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_SYNC_NC_TO_GOOGLE, 'yes') === 'yes';
    }

    public function setSyncNcToGoogle(bool $v): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_SYNC_NC_TO_GOOGLE, $v ? 'yes' : 'no');
    }

    /** Whether events should be pulled from Google into Nextcloud. */
    public function isSyncGoogleToNc(): bool {
        return $this->config->getAppValue(Application::APP_ID, self::KEY_SYNC_GOOGLE_TO_NC, 'yes') === 'yes';
    }

    public function setSyncGoogleToNc(bool $v): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_SYNC_GOOGLE_TO_NC, $v ? 'yes' : 'no');
    }

    /**
     * Returns the earliest date from which Google events should be imported,
     * or null when no limit is configured (all past events are included).
     */
    public function getSyncFromDate(): ?\DateTimeImmutable {
        $val = trim($this->config->getAppValue(Application::APP_ID, self::KEY_SYNC_FROM_DATE, ''));
        if ($val === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $val);
        return $dt !== false ? $dt->setTime(0, 0, 0) : null;
    }

    public function setSyncFromDate(string $date): void {
        $this->config->setAppValue(Application::APP_ID, self::KEY_SYNC_FROM_DATE, trim($date));
    }

    /**
     * Resolves a Nextcloud user ID to a Google impersonation email address.
     *
     * Resolution order:
     *   1. If userId already contains "@", it is returned as-is.
     *   2. If a user_email_suffix is configured, it is appended.
     *   3. If a google_domain is configured, it is used as the domain.
     *   4. Otherwise, the userId is returned unchanged.
     */
    public function resolveUserEmail(string $userId): string {
        if (str_contains($userId, '@')) {
            return $userId;
        }
        $suffix = $this->getUserEmailSuffix();
        if ($suffix !== '') {
            if (str_starts_with($suffix, '@')) {
                return $userId . $suffix;
            }
            return $userId . '@' . $suffix;
        }
        $domain = $this->getGoogleDomain();
        if ($domain !== '') {
            return $userId . '@' . $domain;
        }
        return $userId;
    }
}
