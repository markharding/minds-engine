<?php
/**
 * Routes
 */

namespace Minds\Core\Media\Video;

use Minds\Core\Di\Ref;
use Minds\Core\Router\ModuleRoutes;
use Minds\Core\Router\Route;

class Routes extends ModuleRoutes
{
    /**
     * Registers all module routes
     */
    public function register(): void
    {
        $this->route
            ->withPrefix('api/v3/media/video')
            ->do(function (Route $route) {
                $route->get(
                    'download-url/:guid',
                    Ref::_('Media\Video\Controller', 'getDownloadUrl')
                );
            });
    }
}
