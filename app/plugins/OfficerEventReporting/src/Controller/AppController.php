<?php

declare(strict_types=1);

namespace OfficerEventReporting\Controller;

use App\Controller\AppController as BaseController;
use Cake\Event\EventInterface;

class AppController extends BaseController
{
    /**
     * Initialization hook method.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadComponent("Authentication.Authentication");
        $this->loadComponent("Authorization.Authorization");
        $this->loadComponent("Flash");
    }
}