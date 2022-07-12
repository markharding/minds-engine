<?php

namespace Minds\Core\Nostr;

use Exception;
use Minds\Common\Urn;
use Minds\Core\Config\Config;
use Minds\Core\Di\Di;
use Minds\Core\Entities\Resolver as EntitiesResolver;
use Minds\Core\EntitiesBuilder;
use Minds\Core\Feeds\Elastic\Manager as ElasticSearchManager;
use Minds\Core\Feeds\FeedSyncEntity;
use Minds\Core\Log\Logger;
use Minds\Entities\Activity;
use Minds\Entities\EntityInterface;
use Minds\Entities\User;
use Minds\Exceptions\NotFoundException;
use Minds\Exceptions\ServerErrorException;

class Manager
{
    /** @var \WebSocket\Client[] */
    protected array $clients = [];
    private Logger $logger;

    public function __construct(
        protected ?Config             $config = null,
        protected ?EntitiesBuilder    $entitiesBuilder = null,
        protected ?Keys               $keys = null,
        array                         $clients = [],
        private ?Repository           $repository = null,
        private ?EntitiesResolver     $entitiesResolver = null,
        private ?ElasticSearchManager $elasticSearchManager = null
    ) {
        $this->config ??= Di::_()->get('Config');
        $this->entitiesBuilder ??= Di::_()->get('EntitiesBuilder');
        $this->keys ??= new Keys();
        $this->clients = $clients;
        $this->repository ??= new Repository();
        $this->entitiesResolver ??= new EntitiesResolver();
        $this->elasticSearchManager ??= Di::_()->get("Feeds\Elastic\Manager");
        $this->logger = Di::_()->get("Logger");
    }

    /**
     * @param string $username
     * @return string - Returns a base32 public key
     */
    public function getPublicKeyFromUsername(string $username): string
    {
        $user = $this->entitiesBuilder->getByUserByIndex($username);

        if (!$user) {
            throw new NotFoundException("User with username '$username' not found");
        }

        return $this->getPublicKeyFromUser($user);
    }

    /**
     * @param string $username
     * @return string - Returns a base32 public key
     */
    public function getPublicKeyFromUser(User $user): string
    {
        $publicKey = $this->keys->withUser($user)->getSecp256k1PublicKey();
        return $publicKey;
    }

    /**
     * Will build a signed Nostr event
     * https://github.com/nostr-protocol/nips/blob/master/01.md#events-and-signatures
     * @param EntityInterface $entity
     * @return NostrEvent
     */
    public function buildNostrEvent(EntityInterface $entity): NostrEvent
    {
        $owner = ($entity instanceof User) ? $entity : $this->entitiesBuilder->single($entity->getOwnerGuid());
        if (!$owner instanceof User) {
            throw new ServerErrorException("Entity with no owner passed. We can not sign this");
        }
        $publicKey = $this->keys->withUser($owner)->getSecp256k1PublicKey();

        $kind = 1; // Text_note
        $content = '';
        $createdAt = 0;

        // Want to use a switch but php spec doesn't pass through the correct class name
        // and we want to use instanceof...
        // switch (get_class($entity)) {

        if ($entity instanceof Activity) {
            /** @var Activity */
            $activity = $entity;
            $content = (string)$activity->getMessage();

            if (
                $activity->getEntityGuid()
                || $activity->isRemind()
                || $activity->isQuotedPost()
            ) {
                $content .= ' ' . $activity->getURL();
            }

            $createdAt = $activity->getTimeCreated();
        } elseif ($entity instanceof User) {
            /** @var User */
            $user = $entity;
            $kind = 0; // set_metadata
            $content = json_encode([
                'name' => $user->getUsername() . '@' . $this->getDomain(),
                'about' => (string)$user->briefdescription,
                'picture' => $user->getIconURL('medium'),
            ], JSON_UNESCAPED_SLASHES);
            // $createdAt = $user->getTimeCreated();
            $createdAt = (int) $user->icontime;
        } else {
            throw new ServerErrorException("Unsupported entity type " . get_class($entity));
        }

        $id = hash('sha256', json_encode([// sha256 hash
            0,
            strtolower($publicKey), // <pubkey, as a (lowercase) hex string>,
            $createdAt, //  <created_at, as a number>,
            $kind, // kind
            [], // <tags, as an array of arrays of strings>,
            $content, // <content, as a string>
        ], JSON_UNESCAPED_SLASHES));

        $ctx = secp256k1_context_create(SECP256K1_CONTEXT_SIGN);

        $schnorrKeypair = null;
        $sig64 = null;
        $auxRand = null;

        secp256k1_keypair_create($ctx, $schnorrKeypair, $this->keys->withUser($owner)->getSecp256k1PrivateKey());

        secp256k1_schnorrsig_sign($ctx, $sig64, pack('H*', $id), $schnorrKeypair, 'secp256k1_nonce_function_bip340', $auxRand);

        $nostrEvent = new NostrEvent();
        $nostrEvent->setId($id)
            ->setPubKey($publicKey)
            ->setCreated_at($createdAt)
            ->setKind($kind)
            ->setTags([])
            ->setContent($content)
            ->setSig(unpack("H*", (string)$sig64)[1]);

        return $nostrEvent;
    }

    /**
     * Emit event to nostr
     * @param NostrEvent $nostrEvent
     * @return void
     */
    public function emitEvent(NostrEvent $nostrEvent): void
    {
        $jsonPayload = json_encode(
            [
                "EVENT",
                $nostrEvent->export(),
            ],
            JSON_UNESCAPED_SLASHES
        );

        if (!$this->verifyEvent($jsonPayload)) {
            throw new ServerErrorException("Error in signing event");
        }

        foreach ($this->getClients() as $client) {
            try {
                $client->text($jsonPayload);
                //echo $client->receive(); // Do we care?
                //$client->close();
            } catch (\WebSocket\ConnectionException $e) {
                //var_dump($jsonPayload);
            }
        }
    }

    /**
     * Unsubscribes from clients
     */
    public function __destruct()
    {
        if ($this->clients) {
            foreach ($this->clients as $client) {
                try {
                    $client->close();
                } catch (\WebSocket\ConnectionException $e) {
                }
            }
        }
    }

    /**
     * Returns the clients, constructs them if empty
     * @return \WebSocket\Client[]
     */
    protected function getClients(): array
    {
        if ($this->clients) {
            return $this->clients;
        }
        $relays = $this->config->get('nostr')['relays'] ?? [
                'wss://nostr-relay.untethr.me',
                'wss://nostr.bitcoiner.social',
                'wss://nostr-relay.wlvs.space',
                'wss://nostr-pub.wellorder.net'
            ];

        $this->clients = [];

        foreach ($relays as $relay) {
            $this->clients[] = new \WebSocket\Client($relay, [
                'headers' => [
                    'Host' => ltrim($relay, 'wss://'),
                ]
            ]);
        }
        return $this->clients;
    }

    /**
     * Verifies than an event is correctly signed
     * @param string $jsonPayload
     * @return bool
     */
    protected function verifyEvent(string $jsonPayload): bool
    {
        $event = json_decode($jsonPayload, true);

        $ctx = secp256k1_context_create(SECP256K1_CONTEXT_VERIFY);

        secp256k1_xonly_pubkey_parse($ctx, $xonlyPubKey, pack('H*', $event[1]['pubkey']));

        $result = secp256k1_schnorrsig_verify(
            $ctx,
            pack('H*', $event[1]['sig']),
            pack('H*', $event[1]['id']),
            $xonlyPubKey
        );

        return (bool)$result;
    }

    /**
     * @return string
     */
    protected function getDomain(): string
    {
        return urlencode($this->config->get('nostr')['domain'] ?? '');
    }

    /**
     * @param array $authors
     * @return NostrEvent[]
     * @throws NotFoundException
     * @throws ServerErrorException
     * @throws Exception
     */
    public function getNostrEventsForAuthors(array $authors): array
    {
        $userGuids = [];
        $events = [];
        /**
         * @var User $user
         */
        foreach ($this->repository->getUserGuidsFromAuthors($authors) as $user) {
            if ($user) {
                $userGuids[] = $user->getGuid();
                $events[] = $this->buildNostrEvent($user);
            }
        }
        
        $activities = $this->elasticSearchManager->getList([
            'container_guid' => $userGuids,
            'period' => 'all',
            'algorithm' => 'latest',
            'type' => 'activity',
            'limit' => 12,
            'single_owner_threshold' => 0,
            'access_id' => 2,
            'as_activities' => true
        ]);

        /**
         * @var FeedSyncEntity $activity
         */
        foreach ($activities as $activity) {
            $events[] = $this->buildNostrEvent($activity->getEntity());
        }

        return $events;
    }

    /**
     * @param Urn $entityUrn
     * @return bool
     */
    public function addNostrHashLinkToEntity(Urn $entityUrn): bool
    {
        $entity = $this->entitiesResolver?->setOpts(['cache' => false])
            ->single($entityUrn);

        if ($entity?->getType() !== 'user') {
            $this->logger->addWarning("Entity {$entityUrn->getUrn()} is not a supported entity type");
            return true;
        }

        try {
            $nostrEvent = $this->buildNostrEvent($entity);
        } catch (Exception $e) {
            $this->logger->addWarning("Entity {$entityUrn->getUrn()} is not a supported entity type");
            return false;
        }

        $this->logger->addInfo("Nostr hash for entity {$entityUrn->getUrn()} is {$nostrEvent->getId()}");

        $result = $this->repository->addNewCorrelation($nostrEvent->getPubKey(), $entityUrn->getUrn());

        $result
            ? $this->logger->addInfo("Nostr hash {$nostrEvent->getId()} correctly linked to entity {$entityUrn->getUrn()}")
            : $this->logger->addError("Nostr hash {$nostrEvent->getId()} failed to be linked to entity {$entityUrn->getUrn()}");

        return $result;
    }
}
