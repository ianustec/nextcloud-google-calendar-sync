<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Service;

/**
 * Signals that a user should be silently skipped during sync.
 *
 * Thrown when a known, non-fatal condition prevents sync for a specific user:
 *   "invalid_grant"     The user does not exist in the Google directory.
 *   "notACalendarUser"  The user has no Google Workspace Calendar licence.
 *   "unauthorized_client" The Service Account is not authorised for this user.
 *
 * Callers (SyncEngine, CalendarSyncJob, AdminSettingsController) catch this
 * exception and increment a "skipped" counter rather than a "failed" counter,
 * so the admin can distinguish configuration errors from expected omissions.
 */
class GoogleSyncSkipException extends \RuntimeException {

    /**
     * Inspects a throwable and returns true when it represents a condition
     * that warrants skipping the user rather than reporting a failure.
     *
     * @param \Throwable $e Exception to inspect.
     */
    public static function shouldSkip(\Throwable $e): bool {
        $msg = $e->getMessage();
        return str_contains($msg, 'invalid_grant')
            || str_contains($msg, 'notACalendarUser')
            || str_contains($msg, 'unauthorized_client');
    }
}
