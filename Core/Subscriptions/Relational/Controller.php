<?php
namespace Minds\Core\Subscriptions\Relational;

use Minds\Api\Exportable;
use Minds\Entities\User;
use Minds\Exceptions\UserErrorException;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Diactoros\ServerRequest;

class Controller
{
    public function __construct(protected ?Repository $repository = null)
    {
        $this->repository ??= new Repository();
    }

    /**
     * Returns users who **I subscribe to** that also subscribe to this users
     * @param ServerRequest $request
     * @return JsonResponse
     */
    public function getSubscriptionsThatSubscribeTo(ServerRequest $request): JsonResponse
    {
        /** @var User */
        $loggedInUser = $request->getAttribute('_user');

        /** @var string */
        $subscribedToGuid = $request->getQueryParams()['guid'] ?? null;

        /** @var int */
        $limit = $request->getQueryParams()['limit'] ?? 3;

        if (!$subscribedToGuid) {
            throw new UserErrorException("You must provide ?guid parameter");
        }

        $count =  $this->repository->getSubscriptionsThatSubscribeToCount(
            userGuid: $loggedInUser->getGuid(),
            subscribedToGuid: $subscribedToGuid
        );

        $users = iterator_to_array($this->repository->getSubscriptionsThatSubscribeTo(
            userGuid: $loggedInUser->getGuid(),
            subscribedToGuid: $subscribedToGuid,
            limit: (int) $limit
        ));

        return new JsonResponse([
            'count' => $count,
            'users' => Exportable::_($users),
        ]);
    }
}
