<?php

namespace Minds\Core\Discovery\Validators;

use Minds\Entities\ValidationErrorCollection;
use Minds\Interfaces\ValidatorInterface;

class GetDiscoveryForYouRequestValidator implements ValidatorInterface
{
    private ?ValidationErrorCollection $errors;

    private function resetErrors(): void
    {
        $this->errors = new ValidationErrorCollection();
    }

    /**
     * @inheritDoc
     */
    public function validate(array $dataToValidate): bool
    {
        $this->resetErrors();



        return $this->errors->count() == 0;
    }

    /**
     * @inheritDoc
     */
    public function getErrors(): ?ValidationErrorCollection
    {
        return $this->errors;
    }
}
