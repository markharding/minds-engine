<?php
namespace Minds\Core\Boost\V3\Ranking;

use Cassandra\Timeuuid;
use Minds\Core\Boost\V3\Manager as BoostManager;
use Minds\Core\Boost\V3\Enums\BoostTargetAudiences;
use Minds\Core\Boost\V3\Enums\BoostTargetLocation;
use Minds\Core\Data\Cassandra\Scroll;
use Minds\Core\Data\Cassandra\Prepared;
use Minds\Core\Di\Di;
use Minds\Core\Log\Logger;
use Minds\Exceptions\ServerErrorException;

class Manager
{
    const TIME_WINDOW_SECS = 3600; // 1 hour

    /** @var int[] - the total views for the time window */
    protected $totalViews = [
        BoostTargetLocation::NEWSFEED . '_' . BoostTargetAudiences::OPEN => 0,
        BoostTargetLocation::NEWSFEED . '_' . BoostTargetAudiences::SAFE => 0,
        BoostTargetLocation::SIDEBAR . '_' . BoostTargetAudiences::OPEN => 0,
        BoostTargetLocation::SIDEBAR . '_' . BoostTargetAudiences::SAFE => 0,
    ];

    /** @var int[] - the views for the timewindow, split by key value boostGuid=>totalViews */
    protected $viewsByBoostGuid = [];

    /** @var Timeuuid - the uuid of the oldest view record we hold */
    protected $minScanUuid;

    /** @var Timeuuid - the uuid of the newest view record we hold */
    protected $maxScanUuid;

    /** @var BoostShareRatio */
    protected $activeBoostsCache = [];

    public function __construct(
        protected ?Repository $repository = null,
        protected ?Scroll $scroll = null,
        protected ?Logger $logger = null
    ) {
        $this->repository ??= Di::_()->get('Boost\V3\Ranking\Repository');
        $this->scroll ??= Di::_()->get('Database\Cassandra\Cql\Scroll');
        $this->logger ??= Di::_()->get('Logger');
    }

    /**
     * Call this function periodically to update the rankings of the boosts
     * This function will also store the boosts to the ranking table
     */
    public function calculateRanks()
    {
        /**
         * Grab all the boosts are scheduled to be delivered
         */
        foreach ($this->repository->getBoostShareRatios() as $boostShareRatio) {
            $this->activeBoostsCache[$boostShareRatio->getGuid()] = $boostShareRatio;
        }

        /**
         * Update our view memory
         */
        $this->collectAllViews();

        /**
         * Loop through all of our active boosts(that we just built above) and collect their bid ration
         */
        foreach ($this->activeBoostsCache as $boost) {
            $targetAudiences = [
                BoostTargetAudiences::OPEN => $boost->getTargetAudienceShare(BoostTargetAudiences::OPEN), // Will always go to open audience
                BoostTargetAudiences::SAFE => $boost->getTargetAudienceShare(BoostTargetAudiences::SAFE), // Only safe boosts will go here
            ];

            $ranking = new BoostRanking($boost->getGuid());
        
            foreach ($targetAudiences as $targetAudience => $shareOfBids) {

                // This is our ideal target
                $targetKey = $boost->getTargetLocation() . '_' . $targetAudience;
                $totalViews = $this->totalViews[$targetKey];
                $viewsTarget = $totalViews * $shareOfBids; // ie. 250

                // What we've actual had in the time window
                $viewsActual =  $this->viewsByBoostGuid[$boost->getGuid()] ?? 0; // ie. 125

                // Work out the rank
                $rank = $viewsTarget / max($viewsActual, 1);

                $ranking->setRank($targetAudience, $rank);

                $this->logger->info("Setting {$boost->getGuid()} rank to $rank", [
                    'totalViews' => $totalViews,
                    'target' => $viewsTarget,
                    'actual' => $viewsActual,
                    'share' => $shareOfBids,
                ]);
            }

            $this->repository->addBoostRanking($ranking);
        }
    }

    /**
     * Collects all the view data and stores them in memory
     * Each time this function is called it will resume from the last position
     * @return void
     */
    public function collectAllViews()
    {
        /**
         * If there is no min value set, set to TIME_WINDOW_SECS ago (ie. 1 hour ago)
         */
        if (!$this->minScanUuid) {
            $this->minScanUuid = new Timeuuid((time() - self::TIME_WINDOW_SECS) * 1000);
        }
        if (!$this->maxScanUuid) {
            $this->maxScanUuid = $this->minScanUuid;
        }
       
        /**
         * Scan for views since the last scan position
         */
        $query = $this->prepareQuery(
            gtTimeuuid: $this->maxScanUuid, // Scan for views greater than our last run
        );
        foreach ($this->scroll->request($query) as $row) {
            // Set the maxScanUuid to be the last item we see, as we will query from here on next run
            $this->maxScanUuid = $row['uuid'];

            $campaign = $row['campaign'];

            if ($campaign && $boost = $this->getBoostByCampaign($campaign)) {
                $this->updateViews($boost, val: 1); // Increment in-memory views
            }
        }

        /**
         * Prune views outside of the valid time window
         */
        $query = $this->prepareQuery(
            ltTimeuuid: new Timeuuid((time() - self::TIME_WINDOW_SECS) * 1000), // find those less than one hour
            gtTimeuuid: $this->minScanUuid, // but greater than our last min scan
        );
        foreach ($this->scroll->request($query) as $row) {
            // Set the minScanUuid to be the last item we see, as the next prune will look for GreaterThan this uuid
            $this->minScanUuid = $row['uuid'];

            $campaign = $row['campaign'];

            if ($campaign && $boost = $this->getBoostByCampaign($campaign)) {
                $this->updateViews($boost, val: -1); // Decrement in-memory views
            }
        }
    }

    /**
     * Prepares the query for our scans
     * TODO: support for overlapping parititions. ie. midnight should include previous day partition
     * @param null|\Cassandra\Timeuuid $gtTimeuuid
     * @param null|\Cassandra\Timeuuid $ltTimeuuid
     * @return Prepared\Custom
     */
    protected function prepareQuery(
        \Cassandra\Timeuuid $gtTimeuuid = null,
        \Cassandra\Timeuuid $ltTimeuuid = null
    ): Prepared\Custom {
        $statement = "SELECT * FROM views WHERE ";
        $values = [];

        if (!($gtTimeuuid || $ltTimeuuid)) {
            throw new ServerErrorException("You must provide at least one timeuuid");
        }

        $dateTime = $gtTimeuuid ? $gtTimeuuid->toDateTime() : $ltTimeuuid->toDateTime();
    
        // Year implode(', ', array_fill(0, count($years), '?'));
        
        $statement .= 'year=? ';
        $values[] = (int) $dateTime->format('Y');

        // Month
        $statement .= 'AND month=? ';
        $values[] = new \Cassandra\Tinyint((int) $dateTime->format('m'));

        // Day
        $statement .= 'AND day=? ';
        $values[] = new \Cassandra\Tinyint((int) $dateTime->format('d'));
        
        // Timeuuid

        if ($gtTimeuuid) {
            $statement .= 'AND uuid>? ';
            $values[] = $gtTimeuuid;
        }
        if ($ltTimeuuid) {
            $statement .= 'AND uuid<? ';
            $values[] = $ltTimeuuid;
        }

        $statement .= "ORDER BY month,day,uuid ASC";

        $query = new Prepared\Custom();
        $query->query($statement, $values);

        $query->setOpts([
            'page_size' => 2500,
            'consistency' => \Cassandra::CONSISTENCY_ONE,
        ]);

        return $query;
    }

    /**
     * Safe boosts will go to Open targets.
     * Open boots will ONLY go to Open targets, never safe.
     * @param Boost $boost
     * @param int $val - negative to decrement
     * @return void
     */
    protected function updateViews(BoostShareRatio $boost, int $val = 1): void
    {
        $targetLocation = $boost->getTargetLocation();

        $this->totalViews[$targetLocation . '_' . BoostTargetAudiences::OPEN] += $val;

        if ($boost->isSafe()) {
            $this->totalViews[$targetLocation . '_' . BoostTargetAudiences::SAFE] += $val;
        }

        if (!isset($this->viewsByBoostGuid[$boost->getGuid()])) {
            $this->viewsByBoostGuid[$boost->getGuid()] = $val;
        } else {
            $this->viewsByBoostGuid[$boost->getGuid()] += $val;
        }
    }

    /**
     * Will return a boost from its campaign id
     * @param string $campaign
     * @return null|boost
     */
    protected function getBoostByCampaign(string $campaign): ?BoostShareRatio
    {
        if (strpos($campaign, 'urn:boost:', 0) === false) {
            return null;
        }

        $guid = str_replace('urn:boost:newsfeed:', '', $campaign);
        return $this->repository->getBoostShareRatiosByGuid($guid);
    }
}
