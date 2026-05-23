<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\AppInfo;

/**
 * Guard against duplicate class registration when Nextcloud loads the app
 * more than once (e.g. during occ upgrade with autoloader conflicts).
 * The actual class definition lives in ApplicationImpl.php.
 */
if (\class_exists(Application::class, false)) {
    return;
}

require_once __DIR__ . '/ApplicationImpl.php';
