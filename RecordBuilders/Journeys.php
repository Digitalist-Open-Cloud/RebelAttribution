<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelAttribution\RecordBuilders;

use Piwik\ArchiveProcessor;
use Piwik\ArchiveProcessor\Record;
use Piwik\ArchiveProcessor\RecordBuilder;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\Site;
use Piwik\Tracker\GoalManager;

/**
 * For each goal, the journeys of its conversions: the channels of the visits the visitor made
 * in the days before converting, the oldest first. And how many visits and days they took.
 */
class Journeys extends RecordBuilder
{
    // The visits of a visitor this many days before a conversion are its journey.
    public const DAYS = 30;

    // ponytail: a journey keeps its latest visits. A visitor with more loses the oldest, and with
    // them the visit that came first.
    public const MAX_VISITS = 10;

    // The label of a journey is the channel types of its visits, joined by this.
    public const SEPARATOR = ' ';

    // Rows of the record of visits and days: this and the lowest number of the group.
    public const VISITS = 'visits';
    public const DAYS_TAKEN = 'days';
    public const VISIT_GROUPS = [1, 2, 3, 4];
    public const DAY_GROUPS = [0, 1, 4, 8, 15];

    private const MAX_JOURNEYS = 1000;

    public function __construct()
    {
        parent::__construct(self::MAX_JOURNEYS, null, 'nb_conversions');
    }

    public static function getJourneysRecord(int $idGoal): string
    {
        return 'RebelAttribution_journeys_' . $idGoal;
    }

    public static function getTimeRecord(int $idGoal): string
    {
        return 'RebelAttribution_time_' . $idGoal;
    }

    /**
     * @return int[] the goals of a website that journeys are kept for
     */
    public static function getGoalIds(int $idSite): array
    {
        $idGoals = array_map('intval', GoalManager::getGoalIds($idSite));
        if (Site::isEcommerceEnabledFor($idSite)) {
            $idGoals[] = GoalManager::IDGOAL_ORDER;
        }

        return $idGoals;
    }

    public function getRecordMetadata(ArchiveProcessor $archiveProcessor): array
    {
        $records = [];
        foreach (self::getGoalIds((int) $archiveProcessor->getParams()->getSite()->getId()) as $idGoal) {
            $records[] = Record::make(Record::TYPE_BLOB, self::getJourneysRecord($idGoal));
            $records[] = Record::make(Record::TYPE_BLOB, self::getTimeRecord($idGoal));
        }

        return $records;
    }

    protected function aggregate(ArchiveProcessor $archiveProcessor): array
    {
        $idSite = (int) $archiveProcessor->getParams()->getSite()->getId();
        $logAggregator = $archiveProcessor->getLogAggregator();

        $tables = [];
        foreach (self::getGoalIds($idSite) as $idGoal) {
            $tables[self::getJourneysRecord($idGoal)] = new DataTable();
            $tables[self::getTimeRecord($idGoal)] = new DataTable();
        }

        // A segment picks the conversions by the visit they happened in.
        $conversions = $logAggregator->generateQuery(
            'log_conversion.idvisit, log_conversion.idgoal, log_conversion.buster, log_conversion.idvisitor, '
            . 'log_conversion.server_time, log_conversion.revenue',
            ['log_conversion'],
            $logAggregator->getWhereStatement('log_conversion', 'server_time') . ' AND log_conversion.idgoal >= 0',
            false,
            false
        );

        // The latest visits come first, so that a journey that is cut loses its oldest visits.
        // ponytail: a row for each conversion of the period. Group by journey in the database if
        // websites with very many conversions make this slow.
        $cursor = $logAggregator->getDb()->query(
            'SELECT c.idgoal AS idgoal, MAX(c.revenue) AS revenue, COUNT(*) AS visits,
                    TIMESTAMPDIFF(DAY, MIN(v.visit_first_action_time), MAX(c.server_time)) AS days,
                    SUBSTRING_INDEX(GROUP_CONCAT(v.referer_type ORDER BY v.visit_first_action_time DESC, v.idvisit DESC SEPARATOR \' \'), \' \', ' . self::MAX_VISITS . ') AS journey
               FROM (' . $conversions['sql'] . ') AS c
               JOIN ' . Common::prefixTable('log_visit') . ' AS v
                 ON v.idsite = ? AND v.idvisitor = c.idvisitor
                    AND v.visit_last_action_time >= DATE_SUB(c.server_time, INTERVAL ' . self::DAYS . ' DAY)
                    AND v.visit_first_action_time <= c.server_time
              GROUP BY c.idvisit, c.idgoal, c.buster',
            array_merge($conversions['bind'], [$idSite])
        );

        while ($row = $cursor->fetch()) {
            $idGoal = (int) $row['idgoal'];
            if (!isset($tables[self::getJourneysRecord($idGoal)])) {
                continue;
            }

            $columns = ['nb_conversions' => 1, 'revenue' => (float) $row['revenue']];
            $journey = implode(self::SEPARATOR, array_reverse(explode(' ', $row['journey'])));
            $this->add($tables[self::getJourneysRecord($idGoal)], $journey, $columns);

            $time = $tables[self::getTimeRecord($idGoal)];
            $this->add($time, self::VISITS . self::group((int) $row['visits'], self::VISIT_GROUPS), $columns);
            $this->add($time, self::DAYS_TAKEN . self::group((int) $row['days'], self::DAY_GROUPS), $columns);
        }
        $cursor->closeCursor();

        return $tables;
    }

    private function add(DataTable $table, string $label, array $columns): void
    {
        $row = $table->getRowFromLabel($label);
        if ($row) {
            $row->sumRow(new DataTable\Row([DataTable\Row::COLUMNS => $columns]));
            return;
        }

        $table->addRowFromSimpleArray(['label' => $label] + $columns);
    }

    /**
     * @param int[] $groups the lowest number of each group, rising
     */
    private static function group(int $number, array $groups): int
    {
        $found = $groups[0];
        foreach ($groups as $from) {
            if ($number >= $from) {
                $found = $from;
            }
        }

        return $found;
    }
}
