<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelAttribution\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Common;
use Piwik\Plugins\RebelAttribution\Models;

/**
 * @group RebelAttribution
 * @group Plugins
 */
class ModelsTest extends TestCase
{
    private const SOCIAL = Common::REFERRER_TYPE_SOCIAL_NETWORK;
    private const SEARCH = Common::REFERRER_TYPE_SEARCH_ENGINE;
    private const DIRECT = Common::REFERRER_TYPE_DIRECT_ENTRY;

    /**
     * @dataProvider getShares
     */
    public function testAModelSharesAConversionBetweenTheVisits(string $model, array $journey, array $expected, array $daysBefore = []): void
    {
        $shares = Models::share($model, $journey, $daysBefore ?: array_fill(0, count($journey), 0));

        $this->assertEqualsWithDelta($expected, $shares, 0.0001);
        $this->assertEqualsWithDelta(1, array_sum($shares), 0.0001);
    }

    public function getShares(): array
    {
        $four = [self::SOCIAL, self::SEARCH, self::SEARCH, self::DIRECT];

        return [
            'last' => [Models::LAST, $four, [0, 0, 0, 1]],
            'first' => [Models::FIRST, $four, [1, 0, 0, 0]],
            'linear' => [Models::LINEAR, $four, [0.25, 0.25, 0.25, 0.25]],
            'position' => [Models::POSITION, $four, [0.4, 0.1, 0.1, 0.4]],
            'position, two visits' => [Models::POSITION, [self::SOCIAL, self::DIRECT], [0.5, 0.5]],
            'decay' => [Models::DECAY, [self::SOCIAL, self::SEARCH, self::DIRECT], [1 / 7, 2 / 7, 4 / 7], [14, 7, 0]],
            'decay, on one day' => [Models::DECAY, [self::SOCIAL, self::DIRECT], [0.5, 0.5], [0.2, 0.2]],
            'last not direct' => [Models::LAST_NOT_DIRECT, [self::SOCIAL, self::SEARCH, self::DIRECT, self::DIRECT], [0, 1, 0, 0]],
            'last not direct, only direct' => [Models::LAST_NOT_DIRECT, [self::DIRECT, self::DIRECT], [0, 1]],
            'one visit' => [Models::POSITION, [self::SEARCH], [1]],
        ];
    }
}
