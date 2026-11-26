<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelAttribution;

use Piwik\Archive;
use Piwik\DataTable;
use Piwik\DataTable\DataTableInterface;
use Piwik\Piwik;
use Piwik\Plugins\RebelAttribution\RecordBuilders\Journeys;

require_once PIWIK_INCLUDE_PATH . '/plugins/Referrers/functions.php';

/**
 * Which channels the conversions of a goal came by, counting the visits a visitor made in the
 * days before converting and not only the last one.
 *
 * @method static \Piwik\Plugins\RebelAttribution\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    // What joins the channels of a journey in its label.
    public const ARROW = ' → ';

    /**
     * The journeys that ended in a conversion of a goal: the channels of the visits before it,
     * the oldest first, with the conversions and revenue of each journey. The most common first.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param int $idGoal 0 for ecommerce orders
     * @param string|bool $segment
     * @return DataTableInterface
     */
    public function getJourneys($idSite, $period, $date, $idGoal, $segment = false)
    {
        $journeys = Archive::createDataTableFromArchive(Journeys::getJourneysRecord((int) $idGoal), $idSite, $period, $date, $segment);

        $journeys->filter(function (DataTable $table) {
            foreach ($table->getRows() as $row) {
                $row->setColumn('label', implode(self::ARROW, array_map([self::class, 'getChannelName'], self::toJourney($row))));
            }
        });
        $journeys->filter('Sort', ['nb_conversions', 'desc']);

        return $journeys;
    }

    /**
     * The channels with the conversions and revenue a model gives them, next to what they get
     * when the last visit gets it all, and how often they took each role in a journey.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param int $idGoal 0 for ecommerce orders
     * @param string $model first, linear, position, decay or lastNotDirect
     * @param string|bool $segment
     * @return DataTableInterface
     */
    public function getChannels($idSite, $period, $date, $idGoal, $model = Models::POSITION, $segment = false)
    {
        if (!isset(Models::NAMES[$model])) {
            throw new \Exception(Piwik::translate('RebelAttribution_UnknownModel', implode(', ', array_keys(Models::NAMES))));
        }

        $journeys = Archive::createDataTableFromArchive(Journeys::getJourneysRecord((int) $idGoal), $idSite, $period, $date, $segment);

        return $this->rebuild($journeys, function (DataTable $table) use ($model): array {
            $channels = [];
            foreach ($table->getRows() as $row) {
                $journey = self::toJourney($row);
                $last = count($journey) - 1;
                $conversions = (int) $row->getColumn('nb_conversions');
                $revenue = (float) $row->getColumn('revenue');

                $add = [];
                foreach (Models::share($model, $journey) as $index => $share) {
                    $add[$journey[$index]]['nb_conversions'] = ($add[$journey[$index]]['nb_conversions'] ?? 0) + $share * $conversions;
                    $add[$journey[$index]]['revenue'] = ($add[$journey[$index]]['revenue'] ?? 0) + $share * $revenue;

                    // A channel has a role once in a conversion, however many of its visits had it.
                    $role = $last === 0 ? 'nb_only' : ($index === 0 ? 'nb_introduced' : ($index === $last ? 'nb_closed' : 'nb_assisted'));
                    $add[$journey[$index]][$role] = $conversions;
                    $add[$journey[$index]]['nb_touched'] = $conversions;
                }
                $add[$journey[$last]]['nb_conversions_last'] = $conversions;
                $add[$journey[$last]]['revenue_last'] = $revenue;

                foreach ($add as $channel => $columns) {
                    $channels[$channel] ??= [
                        'nb_conversions' => 0, 'revenue' => 0, 'nb_conversions_last' => 0, 'revenue_last' => 0,
                        'nb_touched' => 0, 'nb_introduced' => 0, 'nb_assisted' => 0, 'nb_closed' => 0, 'nb_only' => 0,
                    ];
                    foreach ($columns as $column => $value) {
                        $channels[$channel][$column] += $value;
                    }
                }
            }

            uasort($channels, fn (array $a, array $b) => $b['nb_conversions'] <=> $a['nb_conversions']);

            $rows = [];
            foreach ($channels as $channel => $columns) {
                $columns['nb_conversions'] = round($columns['nb_conversions'], 2);
                $columns['revenue'] = round($columns['revenue'], 2);
                $columns['revenue_last'] = round($columns['revenue_last'], 2);
                $rows[] = ['label' => self::getChannelName($channel)] + $columns;
            }

            return $rows;
        });
    }

    /**
     * How many visits the visitors made, the converting one included, before they converted a goal.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param int $idGoal 0 for ecommerce orders
     * @param string|bool $segment
     * @return DataTableInterface
     */
    public function getVisitsToConversion($idSite, $period, $date, $idGoal, $segment = false)
    {
        $groups = [];
        foreach (Journeys::VISIT_GROUPS as $visits) {
            $groups[$visits] = $visits === 1 ? Piwik::translate('General_OneVisit') : Piwik::translate('General_NVisits', $visits);
        }
        $groups[$visits] = Piwik::translate('RebelAttribution_VisitsOrMore', $visits);

        return $this->getTime(Journeys::VISITS, $groups, $idSite, $period, $date, $idGoal, $segment);
    }

    /**
     * How many days passed from the first visit of a journey until the conversion of a goal.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param int $idGoal 0 for ecommerce orders
     * @param string|bool $segment
     * @return DataTableInterface
     */
    public function getDaysToConversion($idSite, $period, $date, $idGoal, $segment = false)
    {
        $groups = [];
        foreach (Journeys::DAY_GROUPS as $index => $days) {
            $to = (Journeys::DAY_GROUPS[$index + 1] ?? Journeys::DAYS + 1) - 1;
            $groups[$days] = $days === 0 ? Piwik::translate('RebelAttribution_LessThanADay') : Piwik::translate('RebelAttribution_DaysRange', [$days, $to]);
        }

        return $this->getTime(Journeys::DAYS_TAKEN, $groups, $idSite, $period, $date, $idGoal, $segment);
    }

    /**
     * @param array<int, string> $groups the lowest number of a group => its label
     */
    private function getTime(string $kind, array $groups, $idSite, $period, $date, $idGoal, $segment): DataTableInterface
    {
        $time = Archive::createDataTableFromArchive(Journeys::getTimeRecord((int) $idGoal), $idSite, $period, $date, $segment);

        return $this->rebuild($time, function (DataTable $table) use ($kind, $groups): array {
            $rows = [];
            foreach ($groups as $from => $label) {
                $row = $table->getRowFromLabel($kind . $from);
                $rows[] = [
                    'label' => $label,
                    'nb_conversions' => $row ? (int) $row->getColumn('nb_conversions') : 0,
                    'revenue' => $row ? round((float) $row->getColumn('revenue'), 2) : 0,
                ];
            }

            return $rows;
        });
    }

    /**
     * A table, or each table of a set, with other rows in its place.
     *
     * @param callable(DataTable): array<int, array<string, mixed>> $toRows
     */
    private function rebuild(DataTableInterface $tables, callable $toRows): DataTable|DataTable\Map
    {
        if (!$tables instanceof DataTable) {
            /** @var DataTable\Map $tables */
            $rebuilt = $tables->getEmptyClone();
            foreach ($tables->getDataTables() as $label => $table) {
                $rebuilt->addTable($this->rebuild($table, $toRows), $label);
            }

            return $rebuilt;
        }

        $rebuilt = new DataTable();
        $rebuilt->setAllTableMetadata($tables->getAllTableMetadata());
        $rebuilt->addRowsFromSimpleArray($toRows($tables));

        return $rebuilt;
    }

    /**
     * @return int[] the channel types of the journey of a row, the oldest first. The row of the
     *         journeys that did not fit is one visit of no known channel.
     */
    private static function toJourney(DataTable\Row $row): array
    {
        $label = (string) $row->getColumn('label');

        return $label === (string) DataTable::LABEL_SUMMARY_ROW ? [0] : array_map('intval', explode(Journeys::SEPARATOR, $label));
    }

    private static function getChannelName(int $type): string
    {
        return \Piwik\Plugins\Referrers\getReferrerTypeLabel($type);
    }
}
