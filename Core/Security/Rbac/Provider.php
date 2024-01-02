<?php

namespace Minds\Core\Security\Rbac;

use Minds\Core\Config\Config;
use Minds\Core\Data\MySQL\Client;
use Minds\Core\Di\Provider as DiProvider;
use Minds\Core\EntitiesBuilder;
use Minds\Core\MultiTenant\Services\MultiTenantBootService;
use  Minds\Core\Security\Rbac\Services\RolesService;
use Minds\Core\Security\Rbac\Controllers\PermissionsController;
use Minds\Core\Security\Rbac\Entities;
use Minds\Core\Security\Rbac\Services\RbacGatekeeperService;
use Minds\Core\Sessions\ActiveSession;

class Provider extends DiProvider
{
    public function register()
    {
        $this->di->bind('Permissions\Entities\Manager', function ($di) {
            return new Entities\Manager();
        });

        $this->di->bind(PermissionsController::class, function ($di) {
            return new PermissionsController(
                $di->get(RolesService::class),
                $di->get(EntitiesBuilder::class)
            );
        });

        $this->di->bind(RolesService::class, function ($di) {
            return new RolesService(
                $di->get(Config::class),
                $di->get(Repository::class),
                $di->get(EntitiesBuilder::class),
            );
        });

        $this->di->bind(Repository::class, function ($di) {
            return new Repository(
                $di->get(MultiTenantBootService::class),
                $di->get(Client::class),
                $di->get(Config::class),
                $di->get('Logger')
            );
        });

        $this->di->bind(RbacGatekeeperService::class, function ($di) {
            return new RbacGatekeeperService($di->get(RolesService::class), new ActiveSession());
        }, ['useFactory' => true]);
    }
}
