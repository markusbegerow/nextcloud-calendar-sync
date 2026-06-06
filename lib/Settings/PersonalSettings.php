<?php

namespace OCA\CalDavSync\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {

    public function getForm() {
        // Personal settings are handled through the main app interface
        // This could be used for user-specific preferences
        return new TemplateResponse('calendar-sync', 'personal-settings');
    }

    public function getSection() {
        return 'additional';
    }

    public function getPriority() {
        return 10;
    }
}