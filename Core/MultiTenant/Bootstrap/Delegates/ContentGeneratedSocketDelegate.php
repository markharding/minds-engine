<?php
declare(strict_types=1);

namespace Minds\Core\MultiTenant\Bootstrap\Delegates;

use Minds\Core\Config\Config;
use Minds\Core\Sockets\Events as SocketEvents;
use Minds\Core\Log\Logger;
use Minds\Core\MultiTenant\Bootstrap\Enums\BootstrapStepEnum;

/**
 * Delegate for emitting content generated events to clients.
 */
class ContentGeneratedSocketDelegate
{
    public function __construct(
        private SocketEvents $socketEvents,
        private Config $config,
        private Logger $logger
    ) {
    }

    /**
     * Emits a content generated event via sockets server.
     * @param int|null $tenantId - The tenant ID, will default to tenant id from config if not provided.
     * @return void
     */
    public function onContentGenerated(int $tenantId = null): void
    {
        if (!$tenantId) {
            $tenantId = $this->config->get('tenant_id') ?? -1;
        }

        $roomName = "tenant:bootstrap:$tenantId";

        try {
            $this->socketEvents
                ->setRoom($roomName)
                ->emit($roomName, json_encode([
                    "step" => BootstrapStepEnum::CONTENT_STEP->name,
                    "completed" => true
                ]));
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
