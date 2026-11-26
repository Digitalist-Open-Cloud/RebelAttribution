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
use Piwik\Date;
use Piwik\Metrics\Formatter;
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
     * when the last visit gets it all, and how often they took each role in a journey. The
     * subtable of a channel has the same for its names: the campaigns, search engines, websites
     * and so on.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param int $idGoal 0 for ecommerce orders
     * @param string $model first, linear, position, decay or lastNotDirect
     * @param string|bool $segment
     * @param bool $expanded
     * @param int|null $idSubtable
     * @return DataTableInterface
     */
    public function getChannels($idSite, $period, $date, $idGoal, $model = Models::POSITION, $segment = false, $expanded = false, $idSubtable = null)
    {
        if (!isset(Models::NAMES[$model])) {
            throw new \Exception(Piwik::translate('RebelAttribution_UnknownModel', implode(', ', array_keys(Models::NAMES))));
        }

        $channels = Archive::createDataTableFromArchive(Journeys::getChannelsRecord((int) $idGoal), $idSite, $period, $date, $segment, $expanded, false, $idSubtable);
        $isNames = !empty($idSubtable);
        $channels->filter(function (DataTable $table) use ($model, $isNames) {
            self::toModel($table, $model, !$isNames);
        });

        return $channels;
    }

    /**
     * Leaves the rows of the record of channels with what one model gives them.
     */
    private static function toModel(DataTable $table, string $model, bool $isChannels): void
    {
        foreach ($table->getRows() as $row) {
            $label = $row->getColumn('label');
            $columns = [
                'label' => $isChannels && $label !== DataTable::LABEL_SUMMARY_ROW ? self::getChannelName((int) $label) : $label,
                'nb_conversions' => round((float) $row->getColumn('nb_conversions_' . $model), 2),
                'revenue' => round((float) $row->getColumn('revenue_' . $model), 2),
                'nb_conversions_last' => (int) round((float) $row->getColumn('nb_conversions_' . Models::LAST)),
                'revenue_last' => round((float) $row->getColumn('revenue_' . Models::LAST), 2),
                'nb_touched' => (int) $row->getColumn('nb_conversions'),
            ];
            foreach (Journeys::ROLES as $role) {
                $columns[$role] = (int) $row->getColumn($role);
            }
            $row->setColumns($columns);

            if ($row->getSubtable()) {
                self::toModel($row->getSubtable(), $model, false);
            }
        }

        $table->filter('Sort', ['nb_conversions', 'desc']);
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
     * @param int|string $idSite one website, or several as for other reports
     * @param string $period
     * @param string $date
     * @param int $idGoal 0 for ecommerce orders
     * @param string|bool $segment
     * @return DataTableInterface
     */
    public function getDaysToConversion($idSite, $period, $date, $idGoal, $segment = false)
    {
        // The days of a journey are a setting of a website. Several websites at once get the default.
        $max = is_numeric($idSite) ? MeasurableSettings::getDays((int) $idSite) : MeasurableSettings::DEFAULT_DAYS;

        $groups = [];
        foreach (Journeys::DAY_GROUPS as $index => $days) {
            if ($days > $max) {
                break;
            }
            $to = min($max, (Journeys::DAY_GROUPS[$index + 1] ?? $max + 1) - 1);
            $groups[$days] = $days === 0 ? Piwik::translate('RebelAttribution_LessThanADay') : Piwik::translate('RebelAttribution_DaysRange', [$days, $to]);
        }

        return $this->getTime(Journeys::DAYS_TAKEN, $groups, $idSite, $period, $date, $idGoal, $segment);
    }

    /**
     * When the reports of a goal were last archived for the period, as a sentence for the pages.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param int $idGoal 0 for ecommerce orders
     * @param string|bool $segment
     * @return string empty when nothing is archived
     */
    public function getReportGenerated($idSite, $period, $date, $idGoal, $segment = false)
    {
        $journeys = Archive::createDataTableFromArchive(Journeys::getJourneysRecord((int) $idGoal), $idSite, $period, $date, $segment);

        $archived = [];
        foreach ($journeys instanceof DataTable ? [$journeys] : $journeys->getDataTables() as $table) {
            $archived[] = (string) $table->getMetadata(DataTable::ARCHIVED_DATE_METADATA_NAME);
        }
        $archived = array_filter($archived);
        if (!$archived) {
            return '';
        }

        // The same words as under the reports of Matomo.
        $date = Date::factory(max($archived));
        if ($date->getTimestamp() > Date::today()->getTimestamp()) {
            return Piwik::translate('CoreHome_ReportGeneratedXAgo', (new Formatter())->getPrettyTimeFromSeconds(time() - $date->getTimestamp(), true));
        }

        return Piwik::translate('CoreHome_ReportGeneratedOn', $date->getLocalized(Date::DATE_FORMAT_SHORT)) . ' (UTC)';
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
