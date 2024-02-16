<?php

namespace Minds\Core\Notifications\Push\ManualSend\Validators;

use Minds\Core\Notifications\Push\ManualSend\Enums\PushNotificationPlatformEnum;
use Minds\Core\Notifications\Push\ManualSend\Interfaces\ManualSendPayloadValidatorInterface;
use Minds\Entities\ValidationError;
use Minds\Entities\ValidationErrorCollection;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Payload validator for manual send push notification requests.
 */
class ManualSendPayloadValidator implements ManualSendPayloadValidatorInterface
{
    private ?ValidationErrorCollection $errors;

    /**
     * Reset errors.
     * @return void
     */
    private function reset(): void
    {
        $this->errors = new ValidationErrorCollection();
    }

    /**
     * Validate that a request is valid.
     * @param array|ServerRequestInterface $dataToValidate - data to be validated.
     * @return bool true on success.
     */
    public function validate(array|ServerRequestInterface $dataToValidate): bool
    {
        $this->reset();

        if (empty($dataToValidate['user_guid'])) {
            $this->errors->add(new ValidationError("user_guid", "The platform field must be provided."));
        }

        if (!PushNotificationPlatformEnum::tryFrom($dataToValidate['platform'])) {
            $this->errors->add(new ValidationError("platform", "The token field must be provided."));
        }

        if (empty($dataToValidate['token'])) {
            $this->errors->add(new ValidationError("token", "The title field must be provided."));
        }

        if (empty($dataToValidate['title'])) {
            $this->errors->add(new ValidationError("title", "The subtitle field must be provided."));
        }

        if (empty($dataToValidate['body'])) {
            $this->errors->add(new ValidationError("body", "The body field must be provided."));
        }

        return $this->errors->count() == 0;
    }

    /**
     * Get collection of errors, post validation check.
     * @return ValidationErrorCollection|null - collection of errors.
     */
    public function getErrors(): ?ValidationErrorCollection
    {
        return $this->errors;
    }
}
