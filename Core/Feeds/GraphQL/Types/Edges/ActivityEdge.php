<?php
namespace Minds\Core\Feeds\GraphQL\Types\Edges;

use Minds\Core\GraphQL\Types\EdgeInterface;
use Minds\Core\GraphQL\Types\NodeInterface;
use Minds\Entities\Activity;
use TheCodingMachine\GraphQLite\Annotations\Type;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Types\ID;

/**
 * The ActivityEdge contains the ActivityNode (entity) and the cursor (for pagination).
 * Further information can be provided here, such as relationships or other contexts.
 */
#[Type]
class ActivityEdge implements EdgeInterface
{
    public function __construct(protected Activity $activity, protected string $cursor)
    {
        $this->activity = $activity;
    }

    #[Field]
    public function getId(): ID
    {
        return new ID("activity-" . $this->activity->getGuid());
    }

    #[Field]
    public function getType(): string
    {
        return "activity";
    }

    #[Field]
    public function getCursor(): string
    {
        return $this->cursor;
    }

    #[Field]
    public function getNode(): ActivityNode
    {
        return new ActivityNode($this->activity);
    }
}
