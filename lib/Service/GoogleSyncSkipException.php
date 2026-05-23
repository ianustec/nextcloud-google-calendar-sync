<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Service;

/**
 * Thrown when a user should be silently skipped (not a real sync error).
 * Examples: user not in Google domain, no Calendar, external email.
 */
class GoogleSyncSkipException extends \RuntimeException {
    public function __construct(string $reason, ?\Throwable $previous = null) {
        parent::__construct($reason, 0, $previous);
    }

    public static function fromGoogleError(string $userId, string $message): self {
        return new self("Skipped user $userId: $message");
    }

    /**
     * Detect whether a Google API exception should cause a skip vs a real failure.
     */
    public static function shouldSkip(\Throwable $e): bool {
        $msg = $e->getMessage();
        // invalid_grant = user not in domain or not a Workspace account
        if (str_contains($msg, 'invalid_grant')) {
            return true;
        }
        // notACalendarUser = user doesn't have Calendar enabled
        if (str_contains($msg, 'notACalendarUser')) {
            return true;
        }
        // unauthorized_client = external domain (e.g. gmail.com), DWD can't impersonate
        if (str_contains($msg, 'unauthorized_client')) {
            return true;
        }
        return false;
    }
}
