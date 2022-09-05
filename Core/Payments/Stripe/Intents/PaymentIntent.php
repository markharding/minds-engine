<?php
/**
 * Stripe Payment Intent
 */
namespace Minds\Core\Payments\Stripe\Intents;

use Minds\Traits\MagicAttributes;

/**
 * @method int getAmount()
 * @method PaymentIntent getQuantity(): int
 * @method PaymentIntent getCurrency(): string
 * @method PaymentIntent getConfirm(): bool
 * @method PaymentIntent getOffSession(): bool
 * @method PaymentIntent getServiceFeePct(): int
 * @method PaymentIntent setCaptureMethod($method)
 * @method bool isOffSession()
 * @method string getCaptureMethod()
 * @method array getMetadata()
 * @method self setMetadata(array $metadata)
 */
class PaymentIntent extends Intent
{
    use MagicAttributes;

    /** @var int $amount */
    private $amount = 0;

    /** @var int $quantity */
    private $quantity = 1;

    /** @var string $currency */
    private $currency = 'usd';

    /** @var boolean $confirm */
    private $confirm = false;

    /** @var string */
    private $captureMethod = 'automatic';

    /** @var bool $offSession */
    private $offSession = false;

    /** @var int $serviceFeePct */
    private $serviceFeePct = 0;

    private array $metadata = [];

    /**
     * Return the service
     * @return int
     */
    public function getServiceFee(): int
    {
        return round($this->amount * ($this->serviceFeePct / 100));
    }

    /**
     * Expose to the public apis
     * @param array $extend
     * @return array
     */
    public function export(array $extend = []) : array
    {
        return [
        ];
    }
}
