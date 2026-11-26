<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelAttribution\tests\Fixtures;

use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Tests\Framework\Fixture;

/**
 * A website with the goal "Signed up" and four visitors who convert it on one day:
 * - one came from a social network five days before, from a search engine two days before, and directly;
 * - one came from a search engine two days before, and directly;
 * - two came from a search engine and converted in that visit.
 * A fifth visitor came from a website and did not convert.
 */
class SiteWithJourneys extends Fixture
{
    public const DAY = '2024-03-10';

    public const SOCIAL = 'http://www.facebook.com/somewhere';
    public const SEARCH = 'http://www.google.com/search?q=attribution';
    public const WEBSITE = 'http://blog.example.com/a-post';
    public const DIRECT = '';

    public function setUp(): void
    {
        if (!self::siteCreated(1)) {
            self::createWebsite('2024-01-01 00:00:00', 0, 'Journeys', 'http://example.org');
        }

        if (!GoalsApi::getInstance()->getGoals(1)) {
            GoalsApi::getInstance()->addGoal(1, 'Signed up', 'url', '/signup', 'contains', false, 10);
        }

        self::journey(1, ['2024-03-05' => self::SOCIAL, '2024-03-08' => self::SEARCH, self::DAY => self::DIRECT]);
        self::journey(2, ['2024-03-08' => self::SEARCH, self::DAY => self::DIRECT]);
        self::journey(3, [self::DAY => self::SEARCH]);
        self::journey(4, [self::DAY => self::SEARCH]);
        self::journey(5, [self::DAY => self::WEBSITE], false);
    }

    public function tearDown(): void
    {
    }

    /**
     * The visits of one visitor, who converts in the last one.
     *
     * @param array<string, string> $visits day => the referrer the visit came by
     */
    public static function journey(int $visitor, array $visits, bool $converts = true): void
    {
        $tracker = self::getTracker(1, self::DAY . ' 10:00:00', true, true);
        $tracker->setTokenAuth(self::getTokenAuth());
        $tracker->setVisitorId(sprintf('%016x', $visitor));
        $tracker->setIp(long2ip(ip2long('10.0.0.0') + $visitor));

        $lastDay = array_key_last($visits);
        foreach ($visits as $day => $referrer) {
            $tracker->setForceVisitDateTime($day . ' 10:00:00');
            $tracker->setUrlReferrer($referrer);
            $tracker->setUrl('http://example.org/');
            self::checkResponse($tracker->doTrackPageView('Start'));

            if ($converts && $day === $lastDay) {
                $tracker->setForceVisitDateTime($day . ' 10:05:00');
                $tracker->setUrlReferrer('http://example.org/');
                $tracker->setUrl('http://example.org/signup');
                self::checkResponse($tracker->doTrackPageView('Sign up'));
            }
        }
    }
}
