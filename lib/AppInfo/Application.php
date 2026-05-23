<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\AppInfo;

if (\class_exists(Application::class, false)) {
    return;
}

require_once __DIR__ . '/ApplicationImpl.php';
