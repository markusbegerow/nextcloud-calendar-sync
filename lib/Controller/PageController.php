<?php

namespace OCA\CalDavSync\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Controller;
use OCP\Util;

class PageController extends Controller {
    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        Util::addScript('calendar-sync', 'vendor/jquery.min');
        Util::addScript('calendar-sync', 'calendar-sync-main');
        Util::addStyle('calendar-sync', 'style');
        
        return new TemplateResponse('calendar-sync', 'main');
    }
}