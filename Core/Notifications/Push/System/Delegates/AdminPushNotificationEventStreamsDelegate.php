<?php
namespace Minds\Core\Notifications\Push\System\Delegates;

use Exception;
use Minds\Core\EntitiesBuilder;
use Minds\Core\EventStreams\ActionEvent;
use Minds\Core\EventStreams\Topics\ActionEventsTopic;
use Minds\Core\Notifications\Push\System\Models\AdminPushNotificationRequest;
use Minds\Entities\User;

/**
 *
 */
class AdminPushNotificationEventStreamsDelegate
{
    private ?ActionEventsTopic $actionEventsTopic;

    public function __construct(
        ?ActionEventsTopic $actionEventsTopic = null
    ) {
        $this->actionEventsTopic = $actionEventsTopic;
    }

    /**
     * @param AdminPushNotificationRequest $notification
     * @return void
     * @throws Exception
     */
    public function onAdd(AdminPushNotificationRequest $notification): void
    {
        $notificationEvent = new ActionEvent();

        /**
         * @var User
         */
        $user = (new EntitiesBuilder())->single($notification->getAuthorId());

        $notificationEvent
            ->setAction(ActionEvent::ACTION_SYSTEM_PUSH_NOTIFICATION)
            ->setActionData($notification->export())
            ->setEntity($notification)
            ->setUser($user);

        $this->getTopic()->send($notificationEvent);
    }

    /**
     * @return ActionEventsTopic
     */
    protected function getTopic(): ActionEventsTopic
    {
        if (!$this->actionEventsTopic) {
            $this->actionEventsTopic = new ActionEventsTopic();
        }
        
        return $this->actionEventsTopic;
    }
}
