<?php

declare(strict_types=1);

namespace Minds\Core\Boost\V3\Settings;

use Minds\Core\Di\Di;
use Minds\Exceptions\UserErrorException;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Response\JsonResponse;

/**
 * Boost Settings Controller.
 */
class Controller
{
    public function __construct(
        protected ?Manager $manager = null
    ) {
        $this->manager ??= Di::_()->get('Boost\V3\Settings\Manager');
    }

    /**
     * Get settings for logged in user.
     * @param ServerRequestInterface $request - request object.
     * @return JsonResponse - response.
     */
//    #[OA\Get(
//        path: '/api/v3/boost/settings',
//        responses: [
//            new OA\Response(response: 200, description: "Ok"),
//            new OA\Response(response: 401, description: "Unauthorized"),
//        ]
//    )]
    public function getSettings(ServerRequestInterface $request): JsonResponse
    {
        $user = $request->getAttribute("_user");
        $settings = $this->manager
            ->setUser($user)
            ->getSettings();
        return new JsonResponse($settings);
    }

    /**
     * Store settings change for logged in user.
     * @throws UserErrorException - bad request.
     * @param ServerRequestInterface $request - request object.
     * @return JsonResponse - response.
     */
//    #[OA\Post(
//        path: '/api/v3/boost/settings',
//        responses: [
//            new OA\Response(response: 200, description: "Ok"),
//            new OA\Response(response: 400, description: "Bad Request"),
//            new OA\Response(response: 401, description: "Unauthorized"),
//        ]
//    )]
    public function storeSettings(ServerRequestInterface $request): JsonResponse
    {
        $user = $request->getAttribute("_user");

        $settings = $request->getParsedBody();

        // ojm todo
        // $validator = new BoostUpdateSettingsRequestValidator();
        // if (!$validator->validate($settings)) {
        //     throw new UserErrorException(
        //         message: "An error was encountered whilst validating the request",
        //         code: 400,
        //         errors: $validator->getErrors()
        //     );
        // }

        $this->manager->setUser($user)
            ->updateSettings($settings);

        return new JsonResponse([]);
    }
}
