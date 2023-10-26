<?php
declare(strict_types=1);

namespace Minds\Core\MultiTenant;

use Minds\Core\GraphQL\AbstractGraphQLMappings;
use Minds\Core\MultiTenant\Models\Tenant;
use Minds\Core\MultiTenant\Types\CustomHostname;
use Minds\Core\MultiTenant\Types\CustomHostnameMetadata;
use Minds\Core\MultiTenant\Types\Domain;
use Minds\Core\MultiTenant\Types\NetworkUser;
use TheCodingMachine\GraphQLite\Mappers\StaticClassListTypeMapperFactory;

class GraphQLMappings extends AbstractGraphQLMappings
{
    /**
     * @inheritDoc
     */
    public function register(): void
    {
        $this->schemaFactory->addControllerNamespace('Minds\Core\MultiTenant\Controllers');
        $this->schemaFactory->addTypeNamespace('Minds\\Core\\MultiTenant\\Enums');
        $this->schemaFactory->addTypeNamespace('Minds\\Core\\MultiTenant\\Types\\Factories');
        $this->schemaFactory->addTypeMapperFactory(new StaticClassListTypeMapperFactory([
            Tenant::class,
            NetworkUser::class,
            CustomHostname::class,
            CustomHostnameMetadata::class,
            Domain::class
        ]));

        $this->schemaFactory->setInputTypeValidator(new Types\Validators\TenantInputValidator());
        $this->schemaFactory->setInputTypeValidator(new Types\Validators\NetworkUserInputValidator());
        $this->schemaFactory->setInputTypeValidator(new Types\Validators\CustomHostnameInputValidator());
    }
}
