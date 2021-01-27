<?php
namespace Minds\Core\Blockchain\Uniswap;

use Minds\Core\Di\Di;
use Minds\Core\Http;
use Minds\Core\Blockchain\Services\BlockFinder;

class Client
{
    /** @var Http\Curl\Json\Client */
    protected $http;

    /** @var BlockFinder */
    protected $blockFinder;

    /** @var string */
    protected $graphqlEndpoint = "https://api.thegraph.com/subgraphs/name/uniswap/uniswap-v2";

    public function __construct($http = null, BlockFinder $blockFinder = null)
    {
        $this->http = $http ?: Di::_()->get('Http\Json');
        $this->blockFinder = $blockFinder ?? Di::_()->get('Blockchain\Services\BlockFinder');
    }

    /**
     * Returns a user with their liquidity positions
     * @param string $id
     * @param int $asOf (optional) - Thie timestamp of the block
     * @return UniswapUserEntity
     */
    public function getUser(string $id, int $asOf = null): UniswapUserEntity
    {
        if (!$asOf) {
            $asOf = time() - 300; // 5 minutes (give blocks time to settle)
        }

        $query = '
            query($id: String!, $blockNumber: Int!) {
                user(id: $id, block: { number: $blockNumber }) {
                    id
                    liquidityPositions {
                        id
                        liquidityTokenBalance
                        pair {
                            id
                            totalSupply
                            reserve0
                            reserve1
                            reserveUSD
                        }
                    }
                    usdSwapped
                }
                mints(where: { to: $id}) {
                    id
                    to
                    amount0
                    amount1
                    amountUSD
                    liquidity
                    pair {
                      id
                      totalSupply
                      reserve0
                      reserve1
                      reserveUSD
                    }
                }
                burns(where: { to: $id}) {
                    id
                    to
                    amount0
                    amount1
                    amountUSD
                    liquidity
                    pair {
                      id
                      totalSupply
                      reserve0
                      reserve1
                      reserveUSD
                    }
                }
            }
        ';
        $variables = [
            'id' => strtolower($id),
            'blockNumber' => $this->blockFinder->getBlockByTimestamp($asOf),
        ];

        $response = $this->request($query, $variables);

        $uniswapUser = new UniswapUserEntity();
        $uniswapUser->setId($response['user']['id'])
            ->setUsdSwapped($response['user']['usdSwaped']);

        // Liquidity Positions

        $uniswapUser->setLiquidityPositions(array_map(function ($liquidityPosition) {
            return UniswapLiquidityPositionEntity::build($liquidityPosition);
        }, $response['user']['liquidityPositions'] ?? []));

        // Mints

        $uniswapUser->setMints(array_map(function ($mint) {
            return UniswapMintEntity::build($mint);
        }, $response['mints']));

        // Burns

        $uniswapUser->setBurns(array_map(function ($mint) {
            return UniswapBurnEntity::build($mint);
        }, $response['burns']));
        
        return $uniswapUser;
    }

    /**
     * Returns pairs based on an array of ids
     * @param string[] $ids
     * @return UniswapPairEntity[]
     */
    public function getPairs(array $ids = []): array
    {
        $query = '
            query($ids: [String!]) {
                pairs(where: { id_in: $ids }) {
                    id
                    totalSupply
                    reserve0
                    reserve1
                    reserveUSD
                }
            }
        ';
        $variables = [
            'ids' => array_map(function ($id) {
                return strtolower($id);
            }, $ids),
        ];

        $response = $this->request($query, $variables);

        $pairs = [];

        foreach ($response['pairs'] as $pair) {
            $pairs[] = UniswapPairEntity::build($pair);
        }

        return $pairs;
    }

    /**
     *
     */
    public function getMintsByPairIds(array $pairIds = []): array
    {
        $query = '
            query($ids: [String!]) {
                mints(where: { pair_in: $ids }) {
                    id
                    to
                    amount0
                    amount1
                    amountUSD
                    liquidity
                    pair {
                        id
                        totalSupply
                        reserve0
                        reserve1
                        reserveUSD
                    }
                } 
            }
        ';
        $variables = [
            'ids' => array_map(function ($id) {
                return strtolower($id);
            }, $pairIds),
        ];

        $response = $this->request($query, $variables);

        $mints = [];

        foreach ($response['mints'] as $mint) {
            $mints[] = UniswapMintEntity::build($mint);
        }

        return $mints;
    }

    /**
     * Requests query and validates response
     * @param string $query
     * @param array $variables
     * @return array
     */
    private function request($query, $variables): array
    {
        $response = $this->http->post(
            $this->graphqlEndpoint,
            [
                'query' => $query,
                'variables' => $variables,
        ],
            [
            'headers' => [
                'Content-Type: application/json'
            ]
        ]
        );

        if (!$response || !$response['data'] ?? null) {
            throw new \Exception("Invalid response");
        }

        return $response['data'];
    }
}
