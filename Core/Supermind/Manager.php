<?php

declare(strict_types=1);

namespace Minds\Core\Supermind;

use Exception;
use Minds\Common\Repository\Response;
use Minds\Core\Blockchain\Wallets\OffChain\Exceptions\OffchainWalletInsufficientFundsException;
use Minds\Core\Data\Locks\LockFailedException;
use Minds\Core\Di\Di;
use Minds\Core\Router\Exceptions\ForbiddenException;
use Minds\Core\Supermind\Delegates\EventsDelegate;
use Minds\Core\Supermind\Exceptions\SupermindNotFoundException;
use Minds\Core\Supermind\Exceptions\SupermindOffchainPaymentFailedException;
use Minds\Core\Supermind\Exceptions\SupermindPaymentIntentCaptureFailedException;
use Minds\Core\Supermind\Exceptions\SupermindPaymentIntentFailedException;
use Minds\Core\Supermind\Exceptions\SupermindRequestAcceptCompletionException;
use Minds\Core\Supermind\Exceptions\SupermindRequestCreationCompletionException;
use Minds\Core\Supermind\Exceptions\SupermindRequestDeleteException;
use Minds\Core\Supermind\Exceptions\SupermindRequestExpiredException;
use Minds\Core\Supermind\Exceptions\SupermindRequestIncorrectStatusException;
use Minds\Core\Supermind\Exceptions\SupermindRequestStatusUpdateException;
use Minds\Core\Supermind\Exceptions\SupermindUnauthorizedSenderException;
use Minds\Core\Supermind\Models\SupermindRequest;
use Minds\Core\Supermind\Payments\SupermindPaymentProcessor;
use Minds\Entities\User;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;

/**
 *
 */
class Manager
{
    private User $user;

    public function __construct(
        private ?Repository $repository = null,
        private ?SupermindPaymentProcessor $paymentProcessor = null,
        private ?EventsDelegate $eventsDelegate = null
    ) {
        $this->repository ??= Di::_()->get("Supermind\Repository");
        $this->paymentProcessor ??= new SupermindPaymentProcessor();
        $this->eventsDelegate ??= new EventsDelegate();
    }

    /**
     * @param User $user
     * @return $this
     */
    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @param SupermindRequest $supermindRequest
     * @param string|null $paymentMethodId
     * @return bool
     * @throws ApiErrorException
     * @throws LockFailedException
     * @throws OffchainWalletInsufficientFundsException
     * @throws SupermindOffchainPaymentFailedException
     * @throws SupermindPaymentIntentFailedException
     */
    public function addSupermindRequest(SupermindRequest $supermindRequest, ?string $paymentMethodId = null): bool
    {
        $this->repository->beginTransaction();

        try {
            if ($supermindRequest->getPaymentMethod() == SupermindRequestPaymentMethod::CASH) {
                $paymentIntentId = $this->setupCashPayment($paymentMethodId, $supermindRequest);
                $supermindRequest->setPaymentTxID($paymentIntentId);
            } else {
                $this->paymentProcessor->setupOffchainPayment($supermindRequest);
            }

            $isRequestAdded = $this->repository->addSupermindRequest($supermindRequest);

            if (!$isRequestAdded) {
                if ($supermindRequest->getPaymentMethod() == SupermindRequestPaymentMethod::CASH) {
                    $this->paymentProcessor->cancelPaymentIntent($supermindRequest->getPaymentTxID());
                } else {
                    $this->paymentProcessor->refundOffchainPayment($supermindRequest);
                }

                $this->repository->rollbackTransaction();
            }
        } catch (CardException $e) {
            throw new SupermindPaymentIntentFailedException(message: $e->getMessage());
        } catch (OffchainWalletInsufficientFundsException $e) {
            throw new SupermindOffchainPaymentFailedException(message: $e->getMessage());
        } catch (Exception $e) {
            $this->repository->rollbackTransaction();
            throw $e;
        }

        $this->repository->commitTransaction();
        return true;
    }

    /**
     * @param string $supermindRequestId
     * @return bool
     * @throws ApiErrorException
     * @throws LockFailedException
     * @throws SupermindNotFoundException
     * @throws SupermindPaymentIntentCaptureFailedException
     * @throws SupermindRequestExpiredException
     * @throws SupermindRequestIncorrectStatusException
     */
    public function acceptSupermindRequest(string $supermindRequestId): bool
    {
        $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);

        if (is_null($supermindRequest)) {
            throw new SupermindNotFoundException();
        }

        if ($supermindRequest->getStatus() !== SupermindRequestStatus::CREATED) {
            throw new SupermindRequestIncorrectStatusException();
        }

        if ($supermindRequest->isExpired()) {
            $this->expireSupermindRequest($supermindRequestId);
            throw new SupermindRequestExpiredException();
        }

        $this->repository->updateSupermindRequestStatus(SupermindRequestStatus::ACCEPTED, $supermindRequestId);

        if ($supermindRequest->getPaymentMethod() === SupermindRequestPaymentMethod::CASH) {
            $isPaymentSuccessful = $this->paymentProcessor->capturePaymentIntent($supermindRequest->getPaymentTxID());
        } else {
            $isPaymentSuccessful = $this->paymentProcessor->creditOffchainPayment($supermindRequest);
        }

        if (!$isPaymentSuccessful) {
            $this->repository->updateSupermindRequestStatus(SupermindRequestStatus::FAILED_PAYMENT, $supermindRequestId);
            throw new SupermindPaymentIntentCaptureFailedException();
        }

        return true;
    }

    /**
     * @param string $supermindRequestId
     * @param int $targetStatus
     * @return bool
     * @throws SupermindRequestStatusUpdateException
     */
    public function updateSupermindRequestStatus(string $supermindRequestId, int $targetStatus): bool
    {
        return $this->repository->updateSupermindRequestStatus($targetStatus, $supermindRequestId) ? true : throw new SupermindRequestStatusUpdateException();
    }

    /**
     * @param string $paymentMethodId
     * @param SupermindRequest $supermindRequest
     * @return string
     * @throws SupermindPaymentIntentFailedException
     * @throws Exception
     */
    private function setupCashPayment(string $paymentMethodId, SupermindRequest $supermindRequest): string
    {
        try {
            $paymentIntentId = $this->paymentProcessor->setupSupermindStripePayment($paymentMethodId, $supermindRequest);

            if (!$paymentIntentId) {
                $this->repository->rollbackTransaction();
                throw new SupermindPaymentIntentFailedException();
            }

            return $paymentIntentId;
        } catch (Exception $e) {
            $this->repository->rollbackTransaction();
            throw $e;
        }
    }

    /**
     * @param string $supermindRequestId
     * @return bool
     * @throws ApiErrorException
     * @throws LockFailedException
     * @throws SupermindNotFoundException
     * @throws SupermindRequestExpiredException
     * @throws SupermindRequestIncorrectStatusException
     * @throws SupermindUnauthorizedSenderException
     */
    public function revokeSupermindRequest(string $supermindRequestId): bool
    {
        $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);

        if (is_null($supermindRequest)) {
            throw new SupermindNotFoundException();
        }

        if ($supermindRequest->getStatus() !== SupermindRequestStatus::CREATED) {
            throw new SupermindRequestIncorrectStatusException();
        }

        if ($supermindRequest->isExpired()) {
            $this->expireSupermindRequest($supermindRequestId);
            throw new SupermindRequestExpiredException();
        }

        if ($supermindRequest) {
            if ($this->user->isAdmin() || $supermindRequest->getSenderGuid() !== $this->user->getGuid()) {
                throw new SupermindUnauthorizedSenderException();
            }
        }

        $this->reimburseSupermindPayment($supermindRequest);

        $this->repository->updateSupermindRequestStatus(SupermindRequestStatus::REVOKED, $supermindRequestId);

        return true;
    }

    /**
     * @param string $supermindRequestId
     * @return bool
     * @throws ApiErrorException
     * @throws LockFailedException
     * @throws SupermindNotFoundException
     * @throws SupermindRequestExpiredException
     * @throws SupermindRequestIncorrectStatusException
     * @throws SupermindUnauthorizedSenderException
     */
    public function rejectSupermindRequest(string $supermindRequestId): bool
    {
        $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);

        if (is_null($supermindRequest)) {
            throw new SupermindNotFoundException();
        }

        if ($supermindRequest->getStatus() !== SupermindRequestStatus::CREATED) {
            throw new SupermindRequestIncorrectStatusException();
        }

        if ($supermindRequest->isExpired()) {
            $this->expireSupermindRequest($supermindRequestId);
            throw new SupermindRequestExpiredException();
        }

        if ($supermindRequest->getReceiverGuid() !== $this->user->getGuid()) {
            throw new SupermindUnauthorizedSenderException();
        }

        $this->reimburseSupermindPayment($supermindRequest);

        $this->repository->updateSupermindRequestStatus(SupermindRequestStatus::REJECTED, $supermindRequestId);

        $this->eventsDelegate->onRejectSupermindRequest($supermindRequest);

        return true;
    }

    /**
     * @param string $supermindRequestId
     * @return bool
     * @throws ApiErrorException
     * @throws LockFailedException
     * @throws SupermindNotFoundException
     */
    private function expireSupermindRequest(string $supermindRequestId): bool
    {
        $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);

        if (is_null($supermindRequest)) {
            throw new SupermindNotFoundException();
        }

        $this->reimburseSupermindPayment($supermindRequest);

        $expired = $this->repository->updateSupermindRequestStatus(SupermindRequestStatus::EXPIRED, $supermindRequestId);

        $this->eventsDelegate->onExpireSupermindRequest($supermindRequest);

        return $expired;
    }

    /**
     * @param SupermindRequest $request
     * @return void
     * @throws ApiErrorException
     * @throws LockFailedException
     */
    private function reimburseSupermindPayment(SupermindRequest $request): void
    {
        if ($request->getPaymentMethod() === SupermindRequestPaymentMethod::CASH) {
            $this->paymentProcessor->cancelPaymentIntent($request->getPaymentTxID());
        } else {
            $this->paymentProcessor->refundOffchainPayment($request);
        }
    }

    /**
     * @param string $supermindRequestId
     * @param int $activityGuid
     * @return bool
     * @throws SupermindRequestCreationCompletionException
     */
    public function completeSupermindRequestCreation(string $supermindRequestId, int $activityGuid): bool
    {
        $isSuccessful = $this->repository->updateSupermindRequestActivityGuid($supermindRequestId, $activityGuid);

        if ($isSuccessful) {
            $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);
            $this->eventsDelegate->onCompleteSupermindRequestCreation($supermindRequest);
        }

        return $isSuccessful
            ? true
            : throw new SupermindRequestCreationCompletionException();
    }

    /**
     * @param string $supermindRequestId
     * @param int $replyActivityGuid
     * @return bool
     * @throws SupermindRequestAcceptCompletionException
     */
    public function completeAcceptSupermindRequest(string $supermindRequestId, int $replyActivityGuid): bool
    {
        $isSuccessful = $this->repository->updateSupermindRequestReplyActivityGuid($supermindRequestId, $replyActivityGuid);

        if ($isSuccessful) {
            $supermindRequest = $this->repository->getSupermindRequest($supermindRequestId);
            $this->eventsDelegate->onAcceptSupermindRequest($supermindRequest);
        }

        return $isSuccessful
            ? true
            : throw new SupermindRequestAcceptCompletionException();
    }

    /**
     * @param string $supermindRequestId
     * @return bool
     * @throws SupermindRequestDeleteException
     */
    public function deleteSupermindRequest(string $supermindRequestId): bool
    {
        $isSuccessful = $this->repository->deleteSupermindRequest($supermindRequestId);

        return $isSuccessful
            ? true
            : throw new SupermindRequestDeleteException();
    }

    /**
     * @param int $offset
     * @param int $limit
     * @return Response
     */
    public function getReceivedRequests(int $offset, int $limit): Response
    {
        $requests = [];
        foreach ($this->repository->getReceivedRequests($this->user->getGuid(), $offset, $limit) as $supermindRequest) {
            $requests[] = $supermindRequest;
        }

        return new Response($requests);
    }

    /**
     * @param int $offset
     * @param int $limit
     * @return Response
     */
    public function getSentRequests(int $offset, int $limit): Response
    {
        $requests = [];
        foreach ($this->repository->getSentRequests($this->user->getGuid(), $offset, $limit) as $supermindRequest) {
            $requests[] = $supermindRequest;
        }

        return new Response($requests);
    }

    /**
     * @param string $supermindRequestId
     * @return SupermindRequest
     * @throws SupermindNotFoundException
     */
    public function getRequest(string $supermindRequestId): SupermindRequest
    {
        return $this->repository->getSupermindRequest($supermindRequestId) ?? throw new SupermindNotFoundException();
    }

    /**
     * @return bool
     * @throws ForbiddenException
     */
    public function expireRequests(): bool
    {
        if (php_sapi_name() !== "cli") {
            throw new ForbiddenException();
        }

        $this->repository->expireSupermindRequests(SupermindRequest::SUPERMIND_EXPIRY_THRESHOLD);
        return true;
    }


    // ojmtodo
    /**
     * @return bool
     * @throws ForbiddenException
     */
    public function triggerExpiringSoonEvents(): bool
    {
        // Run job every minute
        $secondsBetweenJobs = SupermindRequest::SUPERMIND_EXPIRING_SOON_JOB_INTERVAL;

        // 24 hrs until expiry
        $secondsUntilExpiry = SupermindRequest::SUPERMIND_EXPIRING_SOON_THRESHOLD;

        // Get all requests where expiration will occur between 24hrs -> 24hrs+59sec
        $minCreateTimeInSeconds = time() + $secondsUntilExpiry;
        $maxCreateTimeInSeconds = $minCreateTimeInSeconds + ($secondsBetweenJobs - 1);

        $requestsExpiringSoon = $this->repository->getRequestsExpiringSoon($minCreateTimeInSeconds, $maxCreateTimeInSeconds);

        // For all valid requests that have been created but not yet accepted
        //
        // foreach ($requestsExpiringSoon as $request)
        // {
        // if ($request->getStatus === 1) {
            // triggerEventHere($request);
            // OR

            // ojm ask how/what delegates to use for this
            // SEND EMAIL
            // $this->supermindEmailer
            // ->setSupermindRequest($supermindRequest);
            // ->setTopic('supermind_request_expiring_soon')
            // ->send();

            // SEND NOTIFICATIONS
        // }

        // }

        }







        return true;
    }

}
