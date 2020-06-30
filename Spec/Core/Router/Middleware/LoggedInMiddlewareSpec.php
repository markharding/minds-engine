<?php

namespace Spec\Minds\Core\Router\Middleware;

use Minds\Core\Router\Exceptions\UnauthorizedException;
use Minds\Core\Router\Middleware\LoggedInMiddleware;
use Minds\Entities\User;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LoggedInMiddlewareSpec extends ObjectBehavior
{
    public function let()
    {
        $xsrfValidateRequest = function () {
            /** XSRF::validateRequest() */
            return true;
        };

        $this->beConstructedWith($xsrfValidateRequest);
    }

    public function it_is_initializable()
    {
        $this->shouldHaveType(LoggedInMiddleware::class);
    }


    public function it_should_process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        ResponseInterface $response,
        User $user
    ) {
        $request->getAttribute('_phpspec_user')
            ->shouldBeCalled()
            ->willReturn($user);

        $handler->handle($request)
            ->shouldBeCalled()
            ->willReturn($response);

        $this
            ->setAttributeName('_phpspec_user')
            ->process($request, $handler)
            ->shouldReturn($response);
    }

    public function it_should_throw_unauthorized_if_no_user_during_process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ) {
        $request->getAttribute('_phpspec_user')
            ->shouldBeCalled()
            ->willReturn(null);

        $handler->handle($request)
            ->shouldNotBeCalled();

        $this
            ->setAttributeName('_phpspec_user')
            ->shouldThrow(UnauthorizedException::class)
            ->duringProcess($request, $handler);
    }

    public function it_should_throw_unauthorized_if_xsrf_check_fail_during_process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        User $user
    ) {
        $this->beConstructedWith(function () {
            /** XSRF::validateRequest() */
            return false;
        });

        $request->getAttribute('_phpspec_user')
            ->shouldBeCalled()
            ->willReturn($user);

        $request->getAttribute('oauth_user_id')
            ->shouldBeCalled()
            ->willReturn(false);

        $handler->handle($request)
            ->shouldNotBeCalled();

        $this
            ->setAttributeName('_phpspec_user')
            ->shouldThrow(UnauthorizedException::class)
            ->duringProcess($request, $handler);
    }
}
