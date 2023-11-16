<?php
declare(strict_types=1);

namespace Minds\Core\Security\Rbac;

use Minds\Core\GraphQL\AbstractGraphQLMappings;
use Minds\Core\MultiTenant\Models\Tenant;
use Minds\Core\MultiTenant\Types\TenantUser;
use Minds\Core\Security\Rbac\Models\Role;
use Minds\Core\Security\Rbac\Types\UserRoleConnection;
use Minds\Core\Security\Rbac\Types\UserRoleEdge;
use TheCodingMachine\GraphQLite\Mappers\StaticClassListTypeMapperFactory;

class GraphQLMappings extends AbstractGraphQLMappings
{
    /**
     * @inheritDoc
     */
    public function register(): void
    {
        $this->schemaFactory->addControllerNamespace('Minds\Core\Security\Rbac\Controllers');
        $this->schemaFactory->addTypeMapperFactory(new StaticClassListTypeMapperFactory([
            Role::class,
            UserRoleConnection::class,
            UserRoleEdge::class,
        ]));

    }
}
