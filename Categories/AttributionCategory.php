<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelAttribution\Categories;

use Piwik\Category\Category;

class AttributionCategory extends Category
{
    public const ID = 'RebelAttribution_Attribution';

    protected $id = self::ID;
    protected $order = 13;
    protected $icon = 'rebelAttributionIcon';
}
