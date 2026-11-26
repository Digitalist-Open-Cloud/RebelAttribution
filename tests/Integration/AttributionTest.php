<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelAttribution\tests\Integration;

use Piwik\API\Request;
use Piwik\NoAccessException;
use Piwik\CronArchive\ReArchiveList;
use Piwik\Plugins\RebelAttribution\API;
use Piwik\Plugins\RebelAttribution\MeasurableSettings;
use Piwik\Plugins\RebelAttribution\tests\Fixtures\SiteWithJourneys;
use Piwik\Plugins\RebelAttribution\Widgets\GetChannels;
use Piwik\Plugins\RebelAttribution\Widgets\GetJourneys;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group RebelAttribution
 * @group Plugins
 */
class AttributionTest extends IntegrationTestCase
{
    /**
     * @var SiteWithJourneys
     */
    public static $fixture;

    public function setUp(): void
    {
        parent::setUp();

        Fixture::loadAllTranslations();
    }

    public function testTheJourneysOfAGoalAreTheChannelsOfTheVisitsBeforeItsConversions(): void
    {
        $this->assertEquals([
            'Search Engines' => [2, 20.0],
            'Search Engines → Direct Entry' => [1, 10.0],
            'Social Networks → Search Engines → Direct Entry' => [1, 10.0],
        ], $this->rows('getJourneys', ['nb_conversions', 'revenue']));
    }

    public function testAVisitOlderThanTheDaysOfAJourneyIsNotPartOfIt(): void
    {
        SiteWithJourneys::journey(6, ['2024-02-01' => SiteWithJourneys::WEBSITE, SiteWithJourneys::DAY => SiteWithJourneys::DIRECT]);

        $this->assertSame([1], $this->rows('getJourneys', ['nb_conversions'])['Direct Entry']);
    }

    public function testTheDaysOfAJourneyAreASettingOfTheWebsite(): void
    {
        $settings = new MeasurableSettings(1);
        $settings->days->setValue(7);
        $settings->save();
        SiteWithJourneys::journey(7, ['2024-02-28' => SiteWithJourneys::WEBSITE, SiteWithJourneys::DAY => SiteWithJourneys::DIRECT]);

        $this->assertSame([1], $this->rows('getJourneys', ['nb_conversions'])['Direct Entry']);
        $this->assertSame(
            ['Less than a day', '1–3 days', '4–7 days'],
            array_keys($this->rows('getDaysToConversion', ['nb_conversions']))
        );
    }

    public function testChangingTheDaysOfAJourneyBuildsTheReportsAgain(): void
    {
        $settings = new MeasurableSettings(1);
        $settings->days->setValue(MeasurableSettings::DEFAULT_DAYS);
        $settings->save();
        $this->assertSame([], (new ReArchiveList())->getAll());

        $settings->days->setValue(60);
        $settings->save();

        $this->assertStringContainsString('RebelAttribution', implode('', (new ReArchiveList())->getAll()));
    }

    public function testTheDaysOfAJourneyHaveToBeOneOfTheChoices(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('must be one of');

        (new MeasurableSettings(1))->days->setValue(45);
    }

    public function testAVisitAfterTheConversionIsNotPartOfTheJourney(): void
    {
        SiteWithJourneys::journey(1, ['2024-03-11' => SiteWithJourneys::WEBSITE], false);

        $this->assertArrayHasKey('Social Networks → Search Engines → Direct Entry', $this->rows('getJourneys', ['nb_conversions']));
    }

    public function testChannelsGetTheConversionsOfTheLastVisitAndOfTheModel(): void
    {
        $columns = ['nb_conversions', 'nb_conversions_last', 'revenue', 'revenue_last'];

        $this->assertEquals([
            'Search Engines' => [3.0, 2, 30.0, 20.0],
            'Social Networks' => [1.0, 0, 10.0, 0.0],
            'Direct Entry' => [0.0, 2, 0.0, 20.0],
        ], $this->rows('getChannels', $columns, ['model' => 'first']));

        $this->assertEquals([
            'Search Engines' => [2.7, 2, 27.0, 20.0],
            'Direct Entry' => [0.9, 2, 9.0, 20.0],
            'Social Networks' => [0.4, 0, 4.0, 0.0],
        ], $this->rows('getChannels', $columns, ['model' => 'position']));
    }

    public function testChannelsHaveTheRolesTheyTookInTheJourneys(): void
    {
        $this->assertEquals([
            'Search Engines' => [4, 1, 1, 0, 2],
            'Direct Entry' => [2, 0, 0, 2, 0],
            'Social Networks' => [1, 1, 0, 0, 0],
        ], $this->rows('getChannels', ['nb_touched', 'nb_introduced', 'nb_assisted', 'nb_closed', 'nb_only']));
    }

    public function testAChannelHasItsNamesUnderIt(): void
    {
        $channels = Request::processRequest('RebelAttribution.getChannels', [
            'idSite' => 1, 'period' => 'day', 'date' => SiteWithJourneys::DAY, 'idGoal' => 1, 'model' => 'first',
        ], []);
        $idSubtable = $channels->getRowFromLabel('Search Engines')->getIdSubDataTable();

        $this->assertEquals(
            ['Google' => [3.0, 2, 4, 1, 1, 0, 2]],
            $this->rows('getChannels', ['nb_conversions', 'nb_conversions_last', 'nb_touched', 'nb_introduced', 'nb_assisted', 'nb_closed', 'nb_only'], ['model' => 'first', 'idSubtable' => $idSubtable])
        );
        $this->assertNull($channels->getRowFromLabel('Direct Entry')->getIdSubDataTable());
    }

    public function testCloserCountsMoreGoesByTheDaysBeforeTheConversion(): void
    {
        // Search engines: two conversions alone, a visit two days before one, and one two days
        // before another that a visit five days before it shares.
        $this->assertEqualsWithDelta(
            ['Search Engines' => [2.79], 'Direct Entry' => [0.96], 'Social Networks' => [0.25]],
            $this->rows('getChannels', ['nb_conversions'], ['model' => 'decay']),
            0.006
        );
    }

    public function testAnUnknownModelIsRefused(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown attribution model');

        $this->rows('getChannels', ['nb_conversions'], ['model' => 'best']);
    }

    public function testTheVisitsAndDaysToAConversionAreGrouped(): void
    {
        $this->assertSame(
            ['1 visit' => [2], '2 visits' => [1], '3 visits' => [1], '4 visits or more' => [0]],
            $this->rows('getVisitsToConversion', ['nb_conversions'])
        );
        $this->assertSame(
            ['Less than a day' => [2], '1–3 days' => [1], '4–7 days' => [1], '8–14 days' => [0], '15–30 days' => [0]],
            $this->rows('getDaysToConversion', ['nb_conversions'])
        );
    }

    public function testThePagesCanTellWhenTheReportsWereArchived(): void
    {
        $parameters = ['idSite' => 1, 'period' => 'day', 'date' => SiteWithJourneys::DAY, 'idGoal' => 1];

        $this->assertStringStartsWith('Report generated ', Request::processRequest('RebelAttribution.getReportGenerated', $parameters, []));
        $this->assertSame('', Request::processRequest('RebelAttribution.getReportGenerated', ['date' => '2023-01-01'] + $parameters, []));
    }

    public function testASegmentPicksTheConversionsByTheVisitTheyHappenedIn(): void
    {
        $this->assertSame(
            ['Search Engines' => [2]],
            $this->rows('getJourneys', ['nb_conversions'], ['segment' => 'referrerType==search'])
        );
    }

    public function testAGoalWithoutConversionsHasNoJourneys(): void
    {
        $this->assertSame([], $this->rows('getJourneys', ['nb_conversions'], ['idGoal' => 99]));
    }

    /**
     * @dataProvider getApiMethods
     */
    public function testTheReportsNeedViewAccessToTheWebsite(string $method): void
    {
        FakeAccess::clearAccess(false, [], [2]);

        $this->expectException(NoAccessException::class);

        API::getInstance()->$method(1, 'day', SiteWithJourneys::DAY, 1);
    }

    public function getApiMethods(): array
    {
        return [['getJourneys'], ['getChannels'], ['getVisitsToConversion'], ['getDaysToConversion'], ['getReportGenerated']];
    }

    public function testThePagesListTheGoalsOfTheWebsite(): void
    {
        $_GET['idSite'] = 1;

        foreach ([GetChannels::class, GetJourneys::class] as $page) {
            $html = (new $page())->render();

            $this->assertStringContainsString('<option value="1">Signed up</option>', $html);
            // A text that was not translated shows as its key. The keys in attributes are for the script.
            $this->assertSame(0, preg_match('/>[^<]*RebelAttribution_[A-Z]/', $html));
        }
    }

    public function testThePagesNeedViewAccessToTheWebsite(): void
    {
        $_GET['idSite'] = 1;
        FakeAccess::clearAccess(false, [], [2]);

        $this->expectException(NoAccessException::class);

        (new GetChannels())->render();
    }

    /**
     * @param string[] $columns
     * @return array<string, array> label => the columns asked for
     */
    private function rows(string $method, array $columns, array $parameters = []): array
    {
        $table = Request::processRequest('RebelAttribution.' . $method, $parameters + [
            'idSite' => 1,
            'period' => 'day',
            'date' => SiteWithJourneys::DAY,
            'idGoal' => 1,
        ], []);

        $rows = [];
        foreach ($table->getRows() as $row) {
            $rows[$row->getColumn('label')] = array_map(fn (string $column) => $row->getColumn($column), $columns);
        }

        return $rows;
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);
        $fixture->createSuperUser = true;
    }

    public function provideContainerConfig()
    {
        return ['Piwik\Access' => new FakeAccess()];
    }
}

AttributionTest::$fixture = new SiteWithJourneys();
