<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelAttribution;

use Piwik\Common;

/**
 * How a conversion is shared between the visits that came before it.
 */
class Models
{
    public const LAST = 'last';
    public const FIRST = 'first';
    public const LINEAR = 'linear';
    public const POSITION = 'position';
    public const DECAY = 'decay';
    public const LAST_NOT_DIRECT = 'lastNotDirect';

    // The models to compare with the last visit, with the key of their name.
    public const NAMES = [
        self::POSITION => 'RebelAttribution_ModelPosition',
        self::FIRST => 'RebelAttribution_ModelFirst',
        self::LINEAR => 'RebelAttribution_ModelLinear',
        self::DECAY => 'RebelAttribution_ModelDecay',
        self::LAST_NOT_DIRECT => 'RebelAttribution_ModelLastNotDirect',
    ];

    // With "decay" a visit counts half as much for every this many days it came before the conversion.
    public const HALF_LIFE_DAYS = 7;

    /**
     * @return string[] every model, the one of Matomo first
     */
    public static function getAll(): array
    {
        return array_merge([self::LAST], array_keys(self::NAMES));
    }

    /**
     * @param int[] $journey the channel of each visit, the oldest first
     * @param float[] $daysBefore how many days before the conversion each visit started
     * @return float[] the share of the conversion each visit gets, in the same order
     */
    public static function share(string $model, array $journey, array $daysBefore): array
    {
        $count = count($journey);
        $last = $count - 1;

        switch ($model) {
            case self::FIRST:
                return self::allTo(0, $count);
            case self::LINEAR:
                return array_fill(0, $count, 1 / $count);
            case self::POSITION:
                if ($count < 3) {
                    return array_fill(0, $count, 1 / $count);
                }
                $shares = array_fill(0, $count, 0.2 / ($count - 2));
                $shares[0] = $shares[$last] = 0.4;
                return $shares;
            case self::DECAY:
                $weights = array_map(fn ($days) => 0.5 ** ($days / self::HALF_LIFE_DAYS), $daysBefore);
                return array_map(fn (float $weight) => $weight / array_sum($weights), $weights);
            case self::LAST_NOT_DIRECT:
                for ($index = $last; $index >= 0; $index--) {
                    if ($journey[$index] !== Common::REFERRER_TYPE_DIRECT_ENTRY) {
                        return self::allTo($index, $count);
                    }
                }
                return self::allTo($last, $count);
            default:
                return self::allTo($last, $count);
        }
    }

    /**
     * @return float[]
     */
    private static function allTo(int $index, int $count): array
    {
        $shares = array_fill(0, $count, 0.0);
        $shares[$index] = 1.0;

        return $shares;
    }
}
