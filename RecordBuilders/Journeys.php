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
use Piwik\Plugins\RebelAttribution\Models;
use Piwik\Site;
use Piwik\Tracker\GoalManager;

/**
 * For each goal, from the journeys of its conversions: the visits the visitor made in the days
 * before converting, the oldest first.
 * - The journeys by the channels of their visits.
 * - The channels with what each model gives them and the roles they took, and under a channel
 *   the same for each of its names: the campaign, search engine, website and so on.
 * - How many visits and days the conversions took.
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

    public const ROLES = ['nb_introduced', 'nb_assisted', 'nb_closed', 'nb_only'];

    private const MAX_JOURNEYS = 1000;
    private const MAX_NAMES = 100;

    public function __construct()
    {
        parent::__construct(self::MAX_JOURNEYS, self::MAX_NAMES, 'nb_conversions');
    }

    public static function getJourneysRecord(int $idGoal): string
    {
        return 'RebelAttribution_journeys_' . $idGoal;
    }

    /**
     * In this record nb_conversions is the conversions a channel took part in. What a model gives
     * it is in nb_conversions_<model> and revenue_<model>.
     */
    public static function getChannelsRecord(int $idGoal): string
    {
        return 'RebelAttribution_channels_' . $idGoal;
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
            $records[] = Record::make(Record::TYPE_BLOB, self::getChannelsRecord($idGoal));
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
            $tables[self::getChannelsRecord($idGoal)] = new DataTable();
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

        // ponytail: a row for each visit of each journey, sorted by the database. Read the visits
        // of a batch of visitors at a time if websites with very many conversions make this slow.
        $cursor = $logAggregator->getDb()->query(
            'SELECT c.idvisit AS idvisit, c.idgoal AS idgoal, c.buster AS buster, c.revenue AS revenue,
                    v.referer_type AS type, v.referer_name AS name,
                    TIMESTAMPDIFF(SECOND, v.visit_first_action_time, c.server_time) AS seconds_before
               FROM (' . $conversions['sql'] . ') AS c
               JOIN ' . Common::prefixTable('log_visit') . ' AS v
                 ON v.idsite = ? AND v.idvisitor = c.idvisitor
                    AND v.visit_last_action_time >= DATE_SUB(c.server_time, INTERVAL ' . self::DAYS . ' DAY)
                    AND v.visit_first_action_time <= c.server_time
              ORDER BY c.idvisit, c.idgoal, c.buster, v.visit_first_action_time, v.idvisit',
            array_merge($conversions['bind'], [$idSite])
        );

        $conversion = null;
        $visits = [];
        while ($row = $cursor->fetch()) {
            $key = $row['idvisit'] . '.' . $row['idgoal'] . '.' . $row['buster'];
            if ($conversion !== null && $key !== $conversion['key']) {
                $this->addJourney($tables, $conversion, $visits);
                $visits = [];
            }
            $conversion = ['key' => $key, 'idGoal' => (int) $row['idgoal'], 'revenue' => (float) $row['revenue']];
            $visits[] = [(int) $row['type'], (string) $row['name'], (int) $row['seconds_before'] / 86400];
        }
        $cursor->closeCursor();
        if ($conversion !== null) {
            $this->addJourney($tables, $conversion, $visits);
        }

        return $tables;
    }

    /**
     * @param array<string, DataTable> $tables
     * @param array{idGoal: int, revenue: float} $conversion
     * @param array<int, array{0: int, 1: string, 2: float}> $visits the channel type, the name and
     *        the days before the conversion of each visit of its journey, the oldest first
     */
    private function addJourney(array $tables, array $conversion, array $visits): void
    {
        $idGoal = $conversion['idGoal'];
        if (!isset($tables[self::getJourneysRecord($idGoal)])) {
            return;
        }

        $counted = ['nb_conversions' => 1, 'revenue' => $conversion['revenue']];

        $time = $tables[self::getTimeRecord($idGoal)];
        $time->sumRowWithLabel(self::VISITS . self::group(count($visits), self::VISIT_GROUPS), $counted);
        $time->sumRowWithLabel(self::DAYS_TAKEN . self::group((int) floor($visits[0][2]), self::DAY_GROUPS), $counted);

        $visits = array_slice($visits, -self::MAX_VISITS);
        $journey = array_column($visits, 0);
        $daysBefore = array_column($visits, 2);
        $last = count($visits) - 1;
        $tables[self::getJourneysRecord($idGoal)]->sumRowWithLabel(implode(self::SEPARATOR, $journey), $counted);

        // What the channels, and the names of a channel, get of this conversion.
        $channels = [];
        $names = [];
        foreach (Models::getAll() as $model) {
            foreach (Models::share($model, $journey, $daysBefore) as $index => $share) {
                [$type, $name] = $visits[$index];
                // A channel has a role once in a conversion, however many of its visits had it.
                $role = $last === 0 ? 'nb_only' : ($index === 0 ? 'nb_introduced' : ($index === $last ? 'nb_closed' : 'nb_assisted'));

                $channels[$type] = self::credit($channels[$type] ?? [], $role, $model, $share, $conversion['revenue']);
                $names[$type][$name] = self::credit($names[$type][$name] ?? [], $role, $model, $share, $conversion['revenue']);
            }
        }

        foreach ($channels as $type => $columns) {
            $row = $tables[self::getChannelsRecord($idGoal)]->sumRowWithLabel((string) $type, $columns);
            foreach ($names[$type] as $name => $nameColumns) {
                // A direct entry has no name.
                if ((string) $name !== '') {
                    $row->sumRowWithLabelToSubtable((string) $name, $nameColumns);
                }
            }
        }
    }

    /**
     * @param array<string, int|float> $columns what a channel has of a conversion so far
     * @return array<string, int|float> the same with a share of the conversion added
     */
    private static function credit(array $columns, string $role, string $model, float $share, float $revenue): array
    {
        $columns += array_fill_keys(self::ROLES, 0) + ['nb_conversions_' . $model => 0, 'revenue_' . $model => 0];
        $columns['nb_conversions'] = 1;
        $columns[$role] = 1;
        $columns['nb_conversions_' . $model] += $share;
        $columns['revenue_' . $model] += $share * $revenue;

        return $columns;
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
