<?php
/**
 * This subscription will listen for changes in entities (create, update, delete) and update elasticsearch
 * You can test by running `php cli.php EventStreams --subscription=Core\\Search\\SearchIndexerSubscription`
 */
namespace Minds\Core\Search;

use Minds\Common\Urn;
use Minds\Core\Blogs\Blog;
use Minds\Core\Di\Di;
use Minds\Core\Entities\Ops\EntitiesOpsEvent;
use Minds\Core\Entities\Ops\EntitiesOpsTopic;
use Minds\Core\Entities\Resolver;
use Minds\Core\EventStreams\EventInterface;
use Minds\Core\EventStreams\SubscriptionInterface;
use Minds\Core\EventStreams\Topics\TopicInterface;
use Minds\Core\Hashtags\WelcomeTag\Manager as WelcomeTagManager;
use Minds\Core\Log\Logger;
use Minds\Entities\Activity;
use Minds\Entities\Group;
use Minds\Entities\Image;
use Minds\Entities\User;
use Minds\Entities\Video;

class SearchIndexerSubscription implements SubscriptionInterface
{
    public function __construct(
        protected ?Index $index = null,
        protected ?Resolver $entitiesResolver = null,
        protected ?Logger $logger = null,
        protected ?WelcomeTagManager $welcomeTagManager = null
    ) {
        $this->index ??= Di::_()->get(Index::class);
        $this->entitiesResolver ??= new Resolver();
        $this->logger ??= Di::_()->get('Logger');
        $this->welcomeTagManager ??= Di::_()->get(WelcomeTagManager::class);
    }

    /**
     * @return string
     */
    public function getSubscriptionId(): string
    {
        return 'search-indexer';
    }

    /**
     * @return TopicInterface
     */
    public function getTopic(): TopicInterface
    {
        return new EntitiesOpsTopic();
    }

    /**
     * @return string
     */
    public function getTopicRegex(): string
    {
        return EntitiesOpsTopic::TOPIC_NAME;
    }

    /**
     * Called when there is a new event
     * @param EventInterface $event
     * @return bool
     */
    public function consume(EventInterface $event): bool
    {
        if (!$event instanceof EntitiesOpsEvent) {
            return false;
        }

        $entity = $this->entitiesResolver->setOpts([
            'cache' => false
        ])->single(new Urn($event->getEntityUrn()));

        if (!$entity) {
            // Entity not found
            return true; // Acknowledge as its likely this entity has been deleted
        }

        // We are only concerned to index the following
        switch (get_class($entity)) {
            case Activity::class:
                $this->patchActivity($entity, $event->getOp());
                break;
            case Image::class:
            case Blog::class:
            case Video::class:
            case User::class:
            case Group::class:
                break;
            default:
                return true; // Will not index anything else
        }

        switch ($event->getOp()) {
            case EntitiesOpsEvent::OP_CREATE:
            case EntitiesOpsEvent::OP_UPDATE:
                return $this->index->index($entity);
                break;
            case EntitiesOpsEvent::OP_DELETE:
                return $this->index->remove($entity);
                break;
        }
       
        return true; // Return true to acknowledge the event from the stream (stop it being redelivered)
    }

    /**
     * Applies patches to activity.
     * @param Activity $activity - activity to patch.
     * @param string $opsEventType - entity operation string e.g. `EntitiesOpsEvent::OP_CREATE`.
     * @return void
     */
    private function patchActivity(Activity &$activity, string $opsEventType): void
    {
        try {
            // strip any existing tags - do not allow users to manually set.
            $activity = $this->welcomeTagManager->strip($activity);

            if (
                $opsEventType === EntitiesOpsEvent::OP_CREATE &&
                $this->welcomeTagManager->shouldAppend($activity)
            ) {
                $activity = $this->welcomeTagManager->append($activity);
            }
        } catch (\Exception $e) {
            $this->logger->error($e);
        }
    }
}
