<?php

namespace Minds\Core\Boost;

use Exception;
use Minds\Core;
use Minds\Core\Blockchain\Services\RatesInterface;
use Minds\Core\Blockchain\Services;
use Minds\Core\Config;
use Minds\Core\Di\Di;
use Minds\Core\Payments;
use Minds\Core\Util\BigNumber;
use Minds\Entities\Boost\Peer;
use Minds\Entities\User;
use Minds\Core\Data\Locks\LockFailedException;

class Payment
{
    /** @var Payments\Stripe\Stripe */
    protected $stripePayments;

    /** @var Config */
    protected $config;

    /** @var Core\Blockchain\Transactions\Manager */
    protected $txManager;

    /** @var Core\Blockchain\Transactions\Repository */
    protected $txRepository;

    /** @var Pending */
    protected $boostPending;

    /** @var Lock */
    protected $locks;

    /** @var Services\Ethereum */
    protected $eth;

    public function __construct(
        $stripePayments = null,
        $eth = null,
        $txManager = null,
        $txRepository = null,
        $config = null,
        $locks = null
    ) {
        $this->stripePayments = $stripePayments ?: Di::_()->get('StripePayments');
        $this->eth = $eth ?: Di::_()->get('Blockchain\Services\Ethereum');
        $this->txManager = $txManager ?: Di::_()->get('Blockchain\Transactions\Manager');
        $this->txRepository = $txRepository ?: Di::_()->get('Blockchain\Transactions\Repository');
        $this->config = $config ?: Di::_()->get('Config');
        $this->locks = $locks ?: Di::_()->get('Database\Locks');
    }

    private function getMindsBoostWalletUser(): User
    {
        return Di::_()->get('EntitiesBuilder')->single($this->config->get('boost')['offchain-wallet-guid']);
    }

    /**
     * @param Network|Peer $boost
     * @param $payload
     * @return null
     * @throws Exception
     */
    public function pay($boost, $payload)
    {
        $currency = method_exists($boost, 'getMethod') ?
            $boost->getMethod() : $boost->getBidType();

        switch ($currency) {
            case 'usd':
            case 'money':
                if ($boost->getHandler() === 'peer') {
                    throw new Exception('Money P2P boosts are not supported');
                }

                $customer = (new Payments\Customer())
                    ->setUser($boost->getOwner());

                $source = $payload;

                if (!$customer->getId()) {
                    $customer->setPaymentToken($payload);
                    $customer = $this->stripePayments->createCustomer($customer);

                    // Token already consumed to set default payment method, let's use the
                    // customer itself
                    $source = $customer->getId();
                }

                $sale = (new Payments\Sale())
                    ->setOrderId('boost-' . $boost->getGuid())
                    ->setAmount($boost->getBid())
                    ->setCustomerId($customer->getId())
                    ->setCustomer($customer)
                    ->setSource($source)
                    ->setSettle(false);

                if ($boost->getOwner()->referrer) {
                    $referrer = new User($boost->getOwner()->referrer);
                    $sale->setMerchant($referrer)
                        ->setFee(0.75); //payout 25% to referrer
                }

                return $this->stripePayments->setSale($sale);

            case 'tokens':
                switch ($payload['method']) {
                    case 'offchain':
                        if ($boost->getHandler() === 'peer' && !$boost->getDestination()->getPhoneNumberHash()) {
                            throw new Exception('Boost target should participate in the Rewards program.');
                        }

                        /** @var Core\Blockchain\Wallets\OffChain\Cap $cap */
                        $cap = Di::_()->get('Blockchain\Wallets\OffChain\Cap')
                            ->setUser($boost->getOwner())
                            ->setContract('boost');

                        if (!$cap->isAllowed($boost->getBid())) {
                            throw new Exception('You are not allowed to spend that amount of coins.');
                        }

                        $txData = [
                            'amount' => (string) $boost->getBid(),
                            'guid' => (string) $boost->getGuid(),
                            'handler' => (string) $boost->getHandler(),
                        ];

                        $targetUser = $this->getMindsBoostWalletUser();
                        if ($boost->getHandler() === 'peer') {
                            $txData['sender_guid'] = (string) $boost->getOwner()->guid;
                            $txData['receiver_guid'] = (string) $boost->getDestination()->guid;
                            $targetUser = $boost->getDestination();
                        } else {
                            $txData['receiver_guid'] = (string) $this->config->get('boost')['offchain-wallet-guid'];
                        }

                        /** @var Core\Blockchain\Wallets\OffChain\Transactions $sendersTx */
                        $sendersTx = Di::_()->get('Blockchain\Wallets\OffChain\Transactions');
                        list($paymentReceiverTx, $paymentSenderTx) = $sendersTx
                            ->setAmount((string) BigNumber::_($boost->getBid()))
                            ->setType('boost')
                            ->setUser($targetUser)
                            ->setData($txData)
                            ->transferFrom($boost->getOwner(), true);

                        return $paymentSenderTx;

                    case 'creditcard':
                        if ($boost->getHandler() === 'peer' && !$boost->getDestination()->getPhoneNumberHash()) {
                            throw new Exception('Boost target should participate in the Rewards program.');
                        }

                        //charge the card
                        $customer = (new Core\Payments\Customer())
                            ->setUser($boost->getOwner());

                        $source = $payload['token'];
                        // if customer doesn't exist on Stripe, create it
                        if (!$this->stripePayments->getCustomer($customer) || !$customer->getId()) {
                            $customer->setPaymentToken($source);
                            $customer = $this->stripePayments->createCustomer($customer);
                            $source = $customer->getId();
                        }

                        $currencyId = $this->config->get('blockchain')['token_symbol'];

                        /** @var RatesInterface $rates */
                        $rates = Di::_()->get('Blockchain\Rates');
                        $exRate = $rates
                            ->setCurrency($currencyId)
                            ->get();

                        $usd = round(BigNumber::fromPlain($boost->getBid(), 18)
                            ->mul($exRate)
                            ->mul(100)
                            ->toDouble()); //*100 for $ -> cents

                        $sale = new Core\Payments\Sale();
                        $sale
                            ->setOrderId('boost-' . $boost->getGuid())
                            ->setAmount($usd)
                            ->setCustomerId($customer->getId())
                            ->setCustomer($customer)
                            ->setSource($source)
                            ->setSettle(false);

                        $tx = 'creditcard:' . $this->stripePayments->setSale($sale);

                        $txData = [
                            'amount' => (string) $boost->getBid(),
                            'guid' => (string) $boost->getGuid(),
                            'handler' => (string) $boost->getHandler()
                        ];

                        if ($boost->getHandler() === 'peer') {
                            $txData['sender_guid'] = (string) $boost->getOwner()->guid;
                            $txData['receiver_guid'] = (string) $boost->getDestination()->guid;
                        }

                        $sendersTx = new Core\Blockchain\Transactions\Transaction();
                        $sendersTx
                            ->setTx($tx)
                            ->setContract('boost')
                            ->setWalletAddress('creditcard')
                            ->setAmount((string) BigNumber::_($boost->getBid())->neg())
                            ->setTimestamp(time())
                            ->setUserGuid($boost->getOwner()->guid)
                            ->setCompleted(true)
                            ->setData($txData);
                        $this->txManager->add($sendersTx);

                        return $tx;

                    case 'onchain':
                        if ($boost->getHandler() === 'peer' && !$boost->getDestination()->getEthWallet()) {
                            throw new Exception('Boost target should participate in the Rewards program.');
                        }

                        $txData = [
                            'amount' => (string) $boost->getBid(),
                            'guid' => (string) $boost->getGuid(),
                            'handler' => (string) $boost->getHandler()
                        ];

                        if ($boost->getHandler() === 'peer') {
                            $txData['sender_guid'] = (string) $boost->getOwner()->guid;
                            $txData['receiver_guid'] = (string) $boost->getDestination()->guid;
                        }

                        $sendersTx = new Core\Blockchain\Transactions\Transaction();
                        $sendersTx
                            ->setUserGuid($boost->getOwner()->guid)
                            ->setWalletAddress($payload['address'])
                            ->setContract('boost')
                            ->setTx($payload['txHash'])
                            ->setAmount((string) BigNumber::_($boost->getBid())->neg())
                            ->setTimestamp(time())
                            ->setCompleted(false)
                            ->setData($txData);
                        $this->txManager->add($sendersTx);

                        if ($boost->getHandler() === 'peer') {
                            $receiversTx = new Core\Blockchain\Transactions\Transaction();
                            $receiversTx
                                ->setUserGuid($boost->getDestination()->guid)
                                ->setWalletAddress($payload['address'])
                                ->setContract('boost')
                                ->setTx($payload['txHash'])
                                ->setAmount($boost->getBid())
                                ->setTimestamp(time())
                                ->setCompleted(false)
                                ->setData([
                                    'amount' => (string) $boost->getBid(),
                                    'guid' => (string) $boost->getGuid(),
                                    'handler' => (string) $boost->getHandler(),
                                    'sender_guid' => (string) $boost->getOwner()->guid,
                                    'receiver_guid' => (string) $boost->getDestination()->guid,
                                ]);
                            $this->txManager->add($receiversTx);
                        }

                        return $payload['txHash'];
                }
        }

        throw new Exception('Payment Method not supported');
    }

    public function charge($boost)
    {
        $currency = method_exists($boost, 'getMethod') ?
            $boost->getMethod() : $boost->getBidType();

        switch ($currency) {
            case 'points':
                return true; // Already charged
            case 'tokens':
                $method = '';
                $txIdMeta = '';

                if (stripos($boost->getTransactionId(), '0x') === 0) {
                    $method = 'onchain';
                } elseif (stripos($boost->getTransactionId(), 'oc:') === 0) {
                    $method = 'offchain';
                } elseif (stripos($boost->getTransactionId(), 'creditcard:') === 0) {
                    $method = 'creditcard';
                    $txIdMeta = explode(':', $boost->getTransactionId(), 2)[1];
                }

                switch ($method) {
                    case 'onchain':
                        $eth = Di::_()->get('Blockchain\Services\Ethereum');
                        $receipt = $eth->request('eth_getTransactionReceipt', [ $boost->getTransactionId() ]);

                        if (!$receipt || !isset($receipt['status'])) {
                            return false; //too soon
                        }

                        if ($receipt['status'] === '0x1') {
                            $guid = (string) BigNumber::fromHex($receipt['logs'][3]['data']);
                            return $boost->getGuid() === $guid;
                        }
                        return false;
                        break;
                    case 'offchain':
                        if ($boost->getHandler() === 'peer') {
                            /** @var Core\Blockchain\Wallets\OffChain\Transactions $receiversTx */
                            $receiversTx = Di::_()->get('Blockchain\Wallets\OffChain\Transactions');
                            $receiversTx
                                ->setAmount($boost->getBid())
                                ->setType('boost')
                                ->setUser($boost->getDestination())
                                ->setData([
                                    'amount' => (string) $boost->getBid(),
                                    'guid' => (string) $boost->getGuid(),
                                    'sender_guid' => (string) $boost->getOwner()->guid,
                                    'receiver_guid' => (string) $boost->getDestination()->guid,
                                ])
                                ->create();
                        }

                        break;
                }

                return true; // Already charged
        }

        throw new Exception('Payment Method not supported');
    }

    /**
     * @param Network|Peer $boost
     * @return null
     * @throws Exception
     */
    public function refund($boost)
    {
        $currency = method_exists($boost, 'getMethod') ?
            $boost->getMethod() : $boost->getBidType();

        if (in_array($currency, [ 'onchain', 'offchain' ], true)) {
            $currency = 'tokens';
        }

        switch ($currency) {
            case 'points':
                return true;
                break;
            case 'usd':
            case 'money':
                $sale = (new Payments\Sale())
                    ->setId($boost->getTransactionId());

                if ($boost->getOwner()->referrer) {
                    $referrer = new User($boost->getOwner()->referrer);
                    $sale->setMerchant($referrer);
                }

                return $this->stripePayments->voidOrRefundSale($sale, true);

            case 'tokens':
                $method = '';
                $txIdMeta = '';

                if (stripos($boost->getTransactionId(), '0x') === 0) {
                    $method = 'onchain';
                } elseif (stripos($boost->getTransactionId(), 'oc:') === 0) {
                    $method = 'offchain';
                } elseif (stripos($boost->getTransactionId(), 'creditcard:') === 0) {
                    $method = 'creditcard';
                    $txIdMeta = explode(':', $boost->getTransactionId(), 2)[1];
                }

                switch ($method) {
                    case 'onchain':
                        if ($boost->getHandler() === 'peer') {
                            // Already refunded
                            return true;
                        }

                        //get the transaction
                        $boostTransaction = $this->txRepository->get($boost->getOwner()->guid, $boost->getTransactionId());

                        //send the tokens back to the booster
                        $txHash = $this->eth->sendRawTransaction($this->config->get('blockchain')['boost_wallet_pkey'], [
                            'from' => $this->config->get('blockchain')['boost_wallet_address'],
                            'to' => $this->config->get('blockchain')['boost_address'],
                            'gasLimit' => BigNumber::_(200000)->toHex(true),
                            'data' => $this->eth->encodeContractMethod('reject(uint256)', [
                                BigNumber::_($boost->getGuid())->toHex(true)
                            ])
                        ]);

                        $refundTransaction = new Core\Blockchain\Transactions\Transaction();
                        $refundTransaction
                            ->setUserGuid($boost->getOwner()->guid)
                            ->setWalletAddress($boostTransaction->getWalletAddress())
                            ->setContract('boost')
                            ->setTx($txHash)
                            ->setAmount((string) BigNumber::_($boostTransaction->getAmount())->neg())
                            ->setTimestamp(time())
                            ->setCompleted(false)
                            ->setData([
                                'amount' => (string) $boost->getBid(),
                                'guid' => (string) $boost->getGuid(),
                                'handler' => (string) $boost->getHandler(),
                                'refund' => true,
                            ]);

                        $this->txManager->add($refundTransaction);
                        break;

                    case 'offchain':

                        $this->locks->setKey("boost:refund:{$boost->getGuid()}");
                        if ($this->locks->isLocked()) {
                            throw new LockFailedException();
                        }

                        $this->locks
                            ->setTTL(86400) //lock for 1 day
                            ->lock();

                        $txData = [
                            'amount' => (string) $boost->getBid(),
                            'guid' => (string) $boost->getGuid(),
                        ];

                        $sender = $this->getMindsBoostWalletUser();
                        if ($boost->getHandler() === 'peer') {
                            $txData['sender_guid'] = (string) $boost->getOwner()->guid;
                            $txData['receiver_guid'] = (string) $boost->getDestination()->guid;
                            $sender = $boost->getDestination();
                        }

                        /** @var Core\Blockchain\Wallets\OffChain\Transactions $sendersTx */
                        $sendersTx = Di::_()->get('Blockchain\Wallets\OffChain\Transactions');
                        $sendersTx
                            ->setAmount($boost->getBid())
                            ->setType('boost_refund')
                            ->setUser($boost->getOwner())
                            ->setData($txData)
                            ->transferFrom($sender);

                        break;

                    case 'creditcard':
                        $sale = (new Payments\Sale())
                            ->setId($txIdMeta);

                        return $this->stripePayments->voidOrRefundSale($sale, true);
                }



                return true;
        }

        throw new Exception('Payment Method not supported');
    }
}
