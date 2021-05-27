<?php
namespace Minds\Core\Blockchain\Wallets\OffChain;

use Minds\Core\Di\Di;
use Minds\Core\Util\BigNumber;
use Minds\Entities\User;

class Balance
{
    /** @var Sums */
    private $sums;

    /** @var User */
    private $user;

    /** @var Withholding\Sums */
    protected $withholdingSums;

    public function __construct($sums = null, $withholdingSums = null)
    {
        $this->sums = $sums ?: new Sums;
        $this->withholdingSums = $withholdingSums ?: Di::_()->get('Blockchain\Wallets\OffChain\Withholding\Sums');
    }

    /**
     * Sets the user
     * @param User $user
     * @return $this
     */
    public function setUser($user)
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Return the balance
     * @return double
     */
    public function get()
    {
        return $this->sums
            ->setUser($this->user)
            ->getBalance();
    }

    /**
     * Return a count of transactions
     * @return int
     */
    public function count()
    {
        return $this->sums
            ->setUser($this->user)
            ->getCount();
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function getAvailable()
    {
        $balance = $this->get();
        $withholdTotal = $this->withholdingSums
            ->setUserGuid($this->user)
            ->get();

        $available = BigNumber::_($balance)->sub($withholdTotal);

        if ($available->lt(0)) {
            return '0';
        }

        return (string) $available;
    }

    public function getByContract($contract, $ts = null, $onlySpend = false)
    {
        return $this->sums
            ->setUser($this->user)
            ->setTimestamp($ts)
            ->getContractBalance($contract, $onlySpend);
    }

    /**
     * Aggregate total of all offchain transactions to a receiver in a given timeframe.
     * @return BigNumber
     */
    public function countByReceiver($ts = null, $receiver = null): BigNumber
    {
        return $this->sums
            ->setUser($this->user)
            ->setReceiver($receiver)
            ->setTimestamp($ts)
            ->countByReceiver();
    }
}
