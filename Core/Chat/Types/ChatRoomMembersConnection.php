<?php
namespace Minds\Core\Chat\Types;

use Minds\Core\GraphQL\Types\Connection;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Annotations\Type;

#[Type]
class ChatRoomMembersConnection extends Connection
{
    /**
     * @return ChatRoomMemberEdge[]
     */
    #[Field]
    public function getEdges(): array
    {
        return $this->edges;
    }

}
