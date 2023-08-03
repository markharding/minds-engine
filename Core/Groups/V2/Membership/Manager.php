<?php
namespace Minds\Core\Groups\V2\Membership;

use DateTime;
use Minds\Core\EntitiesBuilder;
use Minds\Core\Groups\Membership as LegacyMembership;
use Minds\Core\Groups\V2\Membership\Enums\GroupMembershipLevelEnum;
use Minds\Core\Router\Exceptions\ForbiddenException;
use Minds\Core\Experiments;
use Minds\Core\Recommendations\Algorithms\SuggestedGroups\SuggestedGroupsRecommendationsAlgorithm;
use Minds\Core\Security\ACL;
use Minds\Entities\Group;
use Minds\Entities\User;
use Minds\Exceptions\GroupOperationException;
use Minds\Exceptions\NotFoundException;
use Minds\Exceptions\UserErrorException;

class Manager
{
    private bool $readFromLegacy;

    public function __construct(
        protected Repository $repository,
        protected EntitiesBuilder $entitiesBuilder,
        protected ACL $acl,
        protected LegacyMembership $legacyMembership,
        protected Experiments\Manager $experimentsManager,
        protected SuggestedGroupsRecommendationsAlgorithm $groupRecsAlgo,
    ) {
    }

    /**
     * Returns the membership model
     */
    public function getMembership(Group $group, User $user): Membership
    {
        /**
         * Legacy read
         */
        if ($this->shouldReadFromLegacy()) {
            $this->legacyMembership->setGroup($group)->setActor($user);
            $isMember = $this->legacyMembership->isMember($user);
            if (!$isMember) {
                throw new NotFoundException();
            }

            $membershipLevel = GroupMembershipLevelEnum::MEMBER;

            if ($group->isModerator($user)) {
                $membershipLevel = GroupMembershipLevelEnum::MODERATOR;
            }

            if ($group->isOwner($user)) {
                $membershipLevel = GroupMembershipLevelEnum::OWNER;
            }

            return new Membership(
                groupGuid: $group->getGuid(),
                userGuid: $user->getGuid(),
                createdTimestamp: new DateTime(), // irrelevant to legacy api
                membershipLevel: $membershipLevel,
            );
        }

        /**
         * Vitess read
         */
        return $this->repository->get($group->getGuid(), $user->getGuid());
    }

    /**
     * Returns a count of members
     */
    public function getMembersCount(Group $group): int
    {
        /**
         * Legacy read
         */
        if ($this->shouldReadFromLegacy()) {
            return $this->legacyMembership->setGroup($group)->getMembersCount();
        }

        /**
         * Vitess read
         */
        return $this->repository->getCount($group->getGuid());
    }

    /**
     * Get a groups members.
     * @param Group $group - group to get members for.
     * @param GroupMembershipLevelEnum $membershipLevel - filter by membership level, defaults to only members.
     * @param int $limit - limit the number of results.
     * @param int $offset - offset the results.
     * @param int|string &$loadNext - passed reference to a $loadNext variable.
     * @return iterable<Membership>
     */
    public function getMembers(
        Group $group,
        GroupMembershipLevelEnum $membershipLevel = null,
        int $limit = 12,
        int $offset = 0,
        int|string &$loadNext = 0
    ): iterable {
        /**
         * Legacy read
         */
        if ($this->shouldReadFromLegacy()) {
            $members = array_map(function ($user) use ($group) {
                $membership = new Membership(
                    groupGuid: $group->getGuid(),
                    userGuid: $user->getGuid(),
                    createdTimestamp: new DateTime("@{$group->getTimeCreated()}"), // irrelevant to legacy api
                    membershipLevel: GroupMembershipLevelEnum::MEMBER,
                );

                if ($group->isModerator($user)) {
                    $membership->membershipLevel = GroupMembershipLevelEnum::MODERATOR;
                }

                if ($group->isOwner($user)) {
                    $membership->membershipLevel = GroupMembershipLevelEnum::OWNER;
                }

                $membership->setUser($user);

                return $membership;
            }, $this->legacyMembership->setGroup($group)->getMembers([
                'limit' => $limit,
                'offset' => $offset,
            ]));

            $clonedMembers = $members;
            $loadNext = (string) end($clonedMembers)->userGuid;

            yield from $members;

            return;
        }

        /**
         * Vitess read
         */
        foreach ($this->repository->getList(
            groupGuid: $group->getGuid(),
            limit: $limit,
            offset: $offset,
            membershipLevel: $membershipLevel
        ) as $membership) {
            $user = $this->buildUser($membership->userGuid);

            if (!$user) {
                continue;
            }

            $membership->setUser($user);

            $loadNext = ++$offset;
            yield $membership;
        }
    }

    /**
     * @return iterable<User>
     */
    public function getRequests(
        Group $group,
        int $limit = 12,
        int $offset = 0,
        int &$loadNext = 0
    ): iterable {
        /**
         * Legacy read
         */
        if ($this->shouldReadFromLegacy()) {
            $requests = $this->legacyMembership->setGroup($group)->getRequests([
                'limit' => $limit,
                'offset' => $offset,
            ]);

            $clonedRequests = $requests;
            $loadNext = (string) end($clonedRequests)->userGuid;

            yield from $requests;

            return;
        }

        /**
         * Vitess read
         */
        foreach (
            $this->repository->getList(
                groupGuid: $group->getGuid(),
                membershipLevel: GroupMembershipLevelEnum::REQUESTED,
                limit: $limit,
                offset: $offset
            ) as $membership
        ) {
            $user = $this->buildUser($membership->userGuid);

            if (!$user) {
                continue;
            }

            $loadNext = ++$offset;
            yield $user;
        }
    }

    /**
     * Returns a list of groups a user is a member of
     * @return iterable<Group>
     */
    public function getGroups(
        User $user,
        int $limit = 12,
        int $offset = 0,
        int &$loadNext = 0
    ): iterable {
        /**
         * Legacy read
         */
        if ($this->shouldReadFromLegacy()) {
            $groups = array_map(function ($groupGuid) {
                return $this->buildGroup($groupGuid);
            }, $this->legacyMembership->getGroupsByMember([
                'user_guid' => $user->getGuid(),
                'limit' => $limit,
                'offset' => $offset,
            ]));

            $loadNext = $offset + count($groups);

            yield from $groups;

            return;
        }

        /**
         * Vitess read
         */
        foreach (
            $this->repository->getList(
                userGuid: $user->getGuid(),
                limit: $limit,
                offset: $offset
            ) as $membership
        ) {
            $group = $this->buildGroup($membership->groupGuid);

            if (!$group) {
                continue;
            }

            $loadNext = ++$offset;
            yield $group;
        }
    }

    /**
     * Returns all the guids for groups a user is a member of
     */
    public function getGroupGuids(User $user): array
    {
        /**
         * Legacy read
         */
        if ($this->shouldReadFromLegacy()) {
            return $this->legacyMembership->getGroupGuidsByMember([
                'user_guid' => $user->getGuid(),
                'limit' => 500,
            ]);
        }

        /**
         * Vitess read
         */
        return array_map(function ($membership) {
            return $membership->groupGuid;
        }, iterator_to_array($this->repository->getList(
            userGuid: $user->getGuid(),
            limit: 500
        )));
    }

    /**
     * Alters the users membership level. Use this for promoting users to moderator or owner.
     */
    public function modifyMembershipLevel(Group $group, User $user, User $actor, GroupMembershipLevelEnum $membershipLevel = null): bool
    {
        /**
         * Vitess write
         */
        // Get the users membership
        $userMembership = $this->repository->get($group->getGuid(), $user->getGuid());

        // Get the Actors membership level. They must be at least an owner
        $actorMembership = $this->repository->get($group->getGuid(), $actor->getGuid());

        if (!$actorMembership->isOwner()) {
            throw new ForbiddenException();
        }

        // Update the membership level to a member
        $userMembership->membershipLevel = $membershipLevel;

        return $this->repository->updateMembershipLevel($userMembership);
    }

    /**
     * Joins, or requests to join, a group
     */
    public function joinGroup(
        Group $group,
        User $user,
        GroupMembershipLevelEnum $membershipLevel = null
    ): bool {
        /**
         * Legacy write
         */
        $legacyJoined = $this->legacyMembership
            ->setGroup($group)
            ->join($user, [
                'force' => !$group->isPublic() && $membershipLevel === GroupMembershipLevelEnum::MEMBER,
                'isOwner' => $membershipLevel === GroupMembershipLevelEnum::OWNER,
            ]);
        if (!$legacyJoined) {
            return false;
        }

        /**
         * Vitess write
         */
        $membership = new Membership(
            groupGuid: $group->getGuid(),
            userGuid: $user->getGuid(),
            createdTimestamp: new DateTime(),
            membershipLevel: $membershipLevel ?:
                ($group->isPublic() ? GroupMembershipLevelEnum::MEMBER : GroupMembershipLevelEnum::REQUESTED),
        );

        $joined = $this->repository->add($membership);

        // Purge recs cache
        $this->groupRecsAlgo->setUser($user)->purgeCache();

        return $joined;
    }

    /**
     * Leaves a group and deletes the marker
     */
    public function leaveGroup(Group $group, User $user): bool
    {
        /**
         * Legacy write
         */
        try {
            $legacyLeft = $this->legacyMembership->setGroup($group)->leave($user);
            if (!$legacyLeft) {
                return false;
            }
        } catch (GroupOperationException $e) {
            if ($e->getMessage() === 'Error leaving group') {
                return false;
            }
        }

        /**
         * Vitess write
         */
        $membership = $this->repository->get($group->getGuid(), $user->getGuid());

        // Do not allow a banned user to leave
        if ($membership->membershipLevel === GroupMembershipLevelEnum::BANNED) {
            throw new UserErrorException("You can not leave a group that you have already been banned from");
        }

        return $this->repository->delete($membership);
    }

    /**
     * Accepts a user into a group
     */
    public function acceptUser(Group $group, User $user, User $actor): bool
    {
        /**
         * Legacy write
         */
        $this->legacyMembership->setGroup($group)->setActor($actor);
        if (!$this->legacyMembership->isAwaiting($user)) {
            return false;
        }
        $legacyAccepted = $this->legacyMembership->setGroup($group)->setActor($actor)->join($user, ['force' => true]);
        if (!$legacyAccepted) {
            return false;
        }

        /**
         * Vitess write
         */
        // Get the users membership
        $userMembership = $this->repository->get($group->getGuid(), $user->getGuid());

        if ($userMembership->membershipLevel !== GroupMembershipLevelEnum::REQUESTED) {
            throw new UserErrorException("User is not in the REQUESTED membership state");
        }

        // Get the Actors membership level. They must be at least a moderator
        $actorMembership = $this->repository->get($group->getGuid(), $actor->getGuid());

        if (!$actorMembership->isModerator()) {
            throw new ForbiddenException();
        }

        // Update the membership level to a member
        $userMembership->membershipLevel = GroupMembershipLevelEnum::MEMBER;

        return $this->repository->updateMembershipLevel($userMembership);
    }

    /**
     * Removes a user from a group
     */
    public function removeUser(Group $group, User $user, User $actor): bool
    {
        /**
         * Legacy write
         */
        $this->legacyMembership->setGroup($group)->setActor($actor);
        if ($this->legacyMembership->isAwaiting($user)) {
            $legacyRemoved = $this->legacyMembership->setGroup($group)->setActor($actor)->cancelRequest($user);
        } else {
            $legacyRemoved = $this->legacyMembership->setGroup($group)->setActor($actor)->kick($user);
        }
        if (!$legacyRemoved) {
            return false;
        }

        /**
         * Vitess write
         */
        // Get the users membership
        $userMembership = $this->repository->get($group->getGuid(), $user->getGuid());

        // Get the Actors membership level. They must be at least a moderator
        $actorMembership = $this->repository->get($group->getGuid(), $actor->getGuid());

        if (!$actorMembership->isModerator()) {
            throw new ForbiddenException();
        }

        return $this->repository->delete($userMembership);
    }

    /**
     * Bans a user from a group
     */
    public function banUser(Group $group, User $user, User $actor): bool
    {
        /**
         * Legacy write
         */
        $legacyBanned = $this->legacyMembership->setGroup($group)->setActor($actor)->ban($user);
        if (!$legacyBanned) {
            return false;
        }

        /**
         * Vitess write
         */
        // Get the users membership
        $userMembership = $this->repository->get($group->getGuid(), $user->getGuid());

        // Get the Actors membership level. They must be at least a moderator
        $actorMembership = $this->repository->get($group->getGuid(), $actor->getGuid());

        if (!$actorMembership->isModerator()) {
            throw new ForbiddenException();
        }

        // Set to banned
        $userMembership->membershipLevel = GroupMembershipLevelEnum::BANNED;

        return $this->repository->updateMembershipLevel($userMembership);
    }

    /**
     * Removes the ban, resets the user back to a member
     */
    public function unbanUser(Group $group, User $user, User $actor): bool
    {
        /**
         * Legacy write
         */
        $legacyUnbanned = $this->legacyMembership->setGroup($group)->setActor($actor)->unban($user);
        if (!$legacyUnbanned) {
            return false;
        }

        /**
         * Vitess write
         */
        // Get the users membership
        $userMembership = $this->repository->get($group->getGuid(), $user->getGuid());

        if ($userMembership->membershipLevel !== GroupMembershipLevelEnum::BANNED) {
            throw new UserErrorException("User is not banned");
        }

        // Get the Actors membership level. They must be at least a moderator
        $actorMembership = $this->repository->get($group->getGuid(), $actor->getGuid());

        if (!$actorMembership->isModerator()) {
            throw new ForbiddenException();
        }

        // Set to banned
        $userMembership->membershipLevel = GroupMembershipLevelEnum::MEMBER;

        return $this->repository->updateMembershipLevel($userMembership);
    }

    /**
     * Helper function to build a user entity
     */
    private function buildUser(int $userGuid): ?User
    {
        $user = $this->entitiesBuilder->single($userGuid);

        if (!$user instanceof User || !$this->acl->read($user)) {
            return null;
        }

        return $user;
    }

    /**
     * Helper function to build a group entity
     */
    private function buildGroup(int $groupGuid): ?Group
    {
        $group = $this->entitiesBuilder->single($groupGuid);

        if (!$group instanceof Group || !$this->acl->read($group)) {
            return null;
        }

        return $group;
    }

    /**
     * Helper function to check the feature flag (avoid doing this in the constructor)
     */
    private function shouldReadFromLegacy(): bool
    {
        return $this->readFromLegacy ??= !$this->experimentsManager->isOn('engine-2591-groups-memberships');
    }
}
