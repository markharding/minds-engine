<?php

/**
 * Votes Manager
 *
 * @author emi
 */

namespace Minds\Core\Votes;

use Minds\Common\Repository\IterableEntity;
use Minds\Core\Captcha\FriendlyCaptcha\Classes\DifficultyScalingType;
use Minds\Core\Captcha\FriendlyCaptcha\Exceptions\InvalidSolutionException;
use Minds\Core\Captcha\FriendlyCaptcha\Exceptions\PuzzleExpiredException;
use Minds\Core\Captcha\FriendlyCaptcha\Exceptions\PuzzleReusedException;
use Minds\Core\Captcha\FriendlyCaptcha\Exceptions\SignatureMismatchException;
use Minds\Core\Captcha\FriendlyCaptcha\Exceptions\SolutionAlreadySeenException;
use Minds\Core\Captcha\FriendlyCaptcha\Manager as FriendlyCaptchaManager;
use Minds\Core\Config\Config;
use Minds\Core\Di\Di;
use Minds\Core\Events\Dispatcher;
use Minds\Core\Experiments\Manager as ExperimentsManager;
use Minds\Core\Router\Exceptions\UnverifiedEmailException;
use Minds\Core\Security\ACL;
use Minds\Core\Votes\Enums\VoteEnum;
use Minds\Helpers;
use Minds\Entities\EntityInterface;
use Minds\Entities\User;
use Minds\Exceptions\StopEventException;

class Manager
{
    protected $counters;
    protected $indexes;

    protected $entity;
    protected $actor;

    protected $acl;

    /** @var Dispatcher */
    protected $eventsDispatcher;

    private User $user;

    /**
     * Manager constructor.
     */
    public function __construct(
        $counters = null,
        $indexes = null,
        $acl = null,
        $eventsDispatcher = null,
        private ?FriendlyCaptchaManager $friendlyCaptchaManager = null,
        private ?ExperimentsManager $experimentsManager = null,
        private ?MySqlRepository $mySqlRepository = null,
        private ?Config $config = null,
    ) {
        $this->counters = $counters ?: Di::_()->get('Votes\Counters');
        $this->indexes = $indexes ?: Di::_()->get('Votes\Indexes');
        $this->acl = $acl ?: ACL::_();
        $this->eventsDispatcher = $eventsDispatcher ?: Di::_()->get('EventsDispatcher');
        $this->friendlyCaptchaManager ??= Di::_()->get('FriendlyCaptcha\Manager');
        $this->experimentsManager ??= new ExperimentsManager();
        $this->config ??= Di::_()->get(Config::class);
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Casts a vote
     * @param Vote $vote
     * @param array $options
     * @return bool
     * @throws PuzzleReusedException
     * @throws UnverifiedEmailException
     * @throws InvalidSolutionException
     * @throws PuzzleExpiredException
     * @throws SignatureMismatchException
     * @throws SolutionAlreadySeenException
     * @throws \SodiumException
     */
    public function cast($vote, VoteOptions $options = new VoteOptions())
    {
        if (!$this->acl->interact($vote->getEntity(), $vote->getActor(), "vote{$vote->getDirection()}")) {
            throw new \Exception('Actor cannot interact with entity');
        }

        $done = $this->eventsDispatcher->trigger('vote:action:cast', $vote->getEntity()->type, [
            'vote' => $vote
        ], null);

        if ($done === null && !$this->isMultiTenant()) {
            //update counts
            $this->counters->update($vote);

            //update indexes
            $done = $this->indexes->insert($vote);
        } else {
            $done = true;
        }

        // Save to MySQL
        $done = $this->getMySqlRepository()->add($vote) && $done;

        $this->experimentsManager->setUser($this->user);
        $eventOptions = [
            'vote' => $vote
        ];

        /**
         * @deprecated To be removed as FF has been inactive for 4 months
         */
        if ($vote->getDirection() === "up" && $this->experimentsManager->isOn("minds-3119-captcha-for-engagement")) {
            $isPuzzleValid = false;
            try {
                $isPuzzleValid = $this->friendlyCaptchaManager->verify(
                    $options['puzzleSolution'],
                    DifficultyScalingType::DIFFICULTY_SCALING_VOTE_UP
                );
            } catch (InvalidSolutionException $e) {
            }

            $eventOptions['isFriendlyCaptchaPuzzleValid'] = $isPuzzleValid;
        }

        $eventOptions['client_meta'] = $options->clientMeta;

        if ($done && $options->events) {
            $this->eventsDispatcher->trigger('vote', $vote->getDirection(), $eventOptions);

            if (isset($eventOptions['isFriendlyCaptchaPuzzleValid']) && !$eventOptions['isFriendlyCaptchaPuzzleValid']) {
                throw new InvalidSolutionException();
            }
        }

        return $done;
    }

    /**
     * Cancels a vote
     * @param $vote
     * @param array $options
     * @return bool
     * @throws UnverifiedEmailException
     * @throws StopEventException
     */
    public function cancel($vote, VoteOptions $options = new VoteOptions())
    {
        $done = $this->eventsDispatcher->trigger('vote:action:cancel', $vote->getEntity()->type, [
            'vote' => $vote
        ], null);

        if ($done === null && !$this->isMultiTenant()) {
            //update counts
            $this->counters->update($vote, -1);

            //update indexes
            $done = $this->indexes->remove($vote);
        } else {
            $done = true;
        }
        
        // Save to MySQL
        $done = $this->getMySqlRepository()->delete($vote) && $done;

        if ($done && $options->events) {
            $this->eventsDispatcher->trigger('vote:cancel', $vote->getDirection(), [
                'vote' => $vote
            ]);
        }

        return $done;
    }

    /**
     * Returns a boolean stating if actor voted on the entity
     * @param $vote
     * @return bool
     * @throws StopEventException
     */
    public function has($vote)
    {
        $value = $this->eventsDispatcher->trigger('vote:action:has', $vote->getEntity()->type, [
            'vote' => $vote
        ], null);

        if ($value === null) {
            // Checks the entity model (works for multi tenant and host)
            $value = $this->indexes->exists($vote);
        }

        return $value;
    }

    /**
     * Toggles a vote (cancels if exists, votes if doesn't) [wrapper]
     * @param $vote
     * @param array $options
     * @return bool
     * @throws StopEventException
     * @throws UnverifiedEmailException
     */
    public function toggle($vote, VoteOptions $options = new VoteOptions())
    {
        if (!$this->has($vote)) {
            return $this->cast($vote, $options);
        } else {
            return $this->cancel($vote, $options);
        }
    }

    /**
     * Returns a count of votes
     */
    public function count(EntityInterface $entity): int
    {
        if ($this->isMultiTenant()) {
            return $this->counters->get($entity, 'up');
        }

        return Helpers\Counters::get($entity->getGuid(), 'thumbs:up');
    }

    /**
     * @return iterable<IterableEntity>
     */
    public function getList(VoteListOpts $opts): iterable
    {
        if ($this->isMultiTenant()) {
            foreach (
                $this->getMySqlRepository()->getList(
                    userGuid: null,
                    entityGuid: (int) $opts->getEntityGuid(),
                    direction: VoteEnum::UP,
                ) as $item
            ) {
                $iterableEntity = new IterableEntity($item, null);
                yield $iterableEntity;
            }
        } else {
            yield from $this->indexes->getList($opts);
        }
    }

    public function getVotesFromRelationalRepository(
        User $user,
    ): iterable {
        foreach (
            $this->getMySqlRepository()->getList(
                (int) $user->getGuid(),
                VoteEnum::UP
            ) as $item
        ) {
            yield $item;
        }
    }

    /**
     * Not ideal, but avoid connecting to mysql when we might not need to
     */
    private function getMySqlRepository(): MySqlRepository
    {
        return $this->mySqlRepository ??= Di::_()->get(MySqlRepository::class);
    }

    private function isMultiTenant(): bool
    {
        return !!$this->config->get('tenant_id');
    }
}
