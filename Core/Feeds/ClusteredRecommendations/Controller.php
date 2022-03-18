<?php

namespace Minds\Core\Feeds\ClusteredRecommendations;

use Exception;
use Minds\Core\Feeds\ClusteredRecommendations\ResponseBuilders\ClusteredRecommendationsResponseBuilder;
use Minds\Core\Feeds\ClusteredRecommendations\Validators\GetFeedRequestValidator;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response\JsonResponse;

/**
 * Controller class for the clustered recommendations
 */
class Controller
{
    public function __construct(
        private ?Manager $manager = null
    ) {
        $this->manager ??= new Manager();
    }

    /**
     * @param ServerRequestInterface $request
     * @return JsonResponse
     * @throws Exception
     */
    public function getFeed(ServerRequestInterface $request): JsonResponse
    {
        $responseBuilder = new ClusteredRecommendationsResponseBuilder();
        $requestValidator = new GetFeedRequestValidator();
        $queryParams = $request->getQueryParams();
        $limit = (int) $queryParams['limit'] ?? 12;

        $results = $this->manager?->getList($limit);

        return $responseBuilder->successfulResponse($results);
    }
}
