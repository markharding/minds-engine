<?php

namespace Minds\Core\MultiTenant\Models;

use Minds\Core\MultiTenant\Configs\Models\MultiTenantConfig;
use Minds\Core\MultiTenant\Enums\TenantPlanEnum;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

#[Type]
class Tenant
{
    public function __construct(
        #[Field] public readonly int $id,
        #[Field] public readonly ?string $domain = null,
        #[Field(outputType: 'String')] public readonly ?int $ownerGuid = null,
        #[Field(outputType: 'String')] public readonly ?int $rootUserGuid = null,
        #[Field] public readonly ?MultiTenantConfig $config = null,
        #[Field] public readonly TenantPlanEnum $plan = TenantPlanEnum::TEAM,
    ) {
    }
}
