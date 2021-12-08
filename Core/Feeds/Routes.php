<?php

/**
 * Routes
 * @author edgebal
 */

namespace Minds\Core\Feeds;

use Minds\Core\Di\Ref;
use Minds\Core\Router\Middleware\LoggedInMiddleware;
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
            ->withPrefix('api/v3/newsfeed')
            ->do(function (Route $route) {
                $route->get(
                    'default-feed',
                    Ref::_('Feeds\Controller', 'getDefaultFeed')
                );

                $route->get(
                    'logged-out', // alias
                    Ref::_('Feeds\Controller', 'getDefaultFeed'),
                );

                $route
                    ->withMiddleware([
                        LoggedInMiddleware::class
                    ])
                    ->do(function (Route $route) {
                        $route->get(
                            '',
                            Ref::_('Feeds\Controller', 'getFeed')
                        );
                        $route->delete(
                            ':urn',
                            Ref::_('Feeds\Activity\Controller', 'delete')
                        );
                    });
            });
    }
}
