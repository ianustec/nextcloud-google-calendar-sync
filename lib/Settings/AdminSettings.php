<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Settings;

use OCA\NeuraGoogleCalendarSync\AppInfo\Application;
use OCA\NeuraGoogleCalendarSync\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    public function __construct(
        private ConfigService $configService,
    ) {
    }

    public function getForm(): TemplateResponse {
        return new TemplateResponse(Application::APP_ID, 'admin', [
            'enabled' => $this->configService->isEnabled(),
            'googleDomain' => $this->configService->getGoogleDomain(),
            'syncIntervalMinutes' => $this->configService->getSyncIntervalMinutes(),
            'userEmailSuffix' => $this->configService->getUserEmailSuffix(),
            'hasSaKey' => $this->configService->hasServiceAccountKey(),
            'syncNcToGoogle' => $this->configService->isSyncNcToGoogle(),
            'syncGoogleToNc' => $this->configService->isSyncGoogleToNc(),
            'syncFromDate' => $this->configService->getSyncFromDate()?->format('Y-m-d') ?? '',
        ], '');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 50;
    }
}
