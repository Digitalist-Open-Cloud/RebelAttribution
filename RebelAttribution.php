<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelAttribution;

use Piwik\Category\Subcategory;
use Piwik\Plugin;
use Piwik\Plugins\RebelAttribution\Categories\AttributionCategory;

class RebelAttribution extends Plugin
{
    public function registerEvents()
    {
        return [
            'AssetManager.getStylesheetFiles' => 'getStylesheetFiles',
            'AssetManager.getJavaScriptFiles' => 'getJavaScriptFiles',
            'Category.addSubcategories' => 'addSubcategories',
        ];
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = 'plugins/RebelAttribution/stylesheets/menuIcon.less';
        $stylesheets[] = 'plugins/RebelAttribution/stylesheets/attribution.less';
    }

    public function getJavaScriptFiles(&$files)
    {
        $files[] = 'plugins/RebelAttribution/javascripts/attributionPage.js';
    }

    public function addSubcategories(&$subcategories)
    {
        foreach (AttributionPage::PAGES as $order => $id) {
            $subcategory = new Subcategory();
            $subcategory->setCategoryId(AttributionCategory::ID);
            $subcategory->setId($id);
            $subcategory->setName($id);
            $subcategory->setOrder($order + 1);
            $subcategories[] = $subcategory;
        }
    }
}
