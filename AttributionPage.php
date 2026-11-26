<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelAttribution;

use Piwik\API\Request as ApiRequest;
use Piwik\Common;
use Piwik\Piwik;
use Piwik\Plugins\RebelAttribution\Categories\AttributionCategory;
use Piwik\Plugins\RebelAttribution\RecordBuilders\Journeys;
use Piwik\Request;
use Piwik\Site;
use Piwik\Tracker\GoalManager;
use Piwik\Widget\Widget;
use Piwik\Widget\WidgetConfig;

/**
 * A page of the attribution of a goal. The page holds the goals to pick from and its script
 * asks the API for what the picked goal has.
 */
abstract class AttributionPage extends Widget
{
    // The pages by the ID of their subcategory, in the order of the menu.
    public const PAGES = ['RebelAttribution_Channels', 'RebelAttribution_Journeys'];

    // The ID of the subcategory the page is, set by each page.
    protected const PAGE = '';

    public static function configure(WidgetConfig $config)
    {
        $config->setCategoryId(AttributionCategory::ID);
        $config->setSubcategoryId(static::PAGE);
        $config->setName(static::PAGE);
        $config->setIsWide();
        $config->setOrder(1);
    }

    public function render()
    {
        $idSite = Request::fromRequest()->getIntegerParameter('idSite', 0);
        Piwik::checkUserHasViewAccess($idSite);

        $goals = [];
        foreach (ApiRequest::processRequest('Goals.getGoals', ['idSite' => $idSite, 'filter_limit' => -1], []) as $goal) {
            $goals[(int) $goal['idgoal']] = Common::unsanitizeInputValue($goal['name']);
        }
        if (Site::isEcommerceEnabledFor($idSite)) {
            $goals[GoalManager::IDGOAL_ORDER] = Piwik::translate('RebelAttribution_EcommerceOrders');
        }

        $models = [];
        foreach (Models::NAMES as $model => $name) {
            $models[$model] = ['name' => Piwik::translate($name), 'help' => Piwik::translate($name . 'Help')];
        }

        return $this->renderTemplate('attributionPage', [
            'page' => static::PAGE,
            'goals' => $goals,
            'models' => $models,
            'days' => Journeys::DAYS,
            'currency' => Site::getCurrencySymbolFor($idSite),
            'texts' => [
                'noData' => Piwik::translate('CoreHome_ThereIsNoDataForThisReport'),
                'loading' => Piwik::translate('General_LoadingData'),
                'oneConversion' => Piwik::translate('RebelAttribution_OneConversion'),
                'conversions' => Piwik::translate('RebelAttribution_NConversions'),
                'notCredited' => Piwik::translate('RebelAttribution_NotCreditedToday'),
                'showNames' => Piwik::translate('RebelAttribution_ShowNames'),
                'times' => Piwik::translate('RebelAttribution_VisitsInARow'),
                'roles' => [
                    'nb_introduced' => Piwik::translate('RebelAttribution_RoleIntroduced'),
                    'nb_assisted' => Piwik::translate('RebelAttribution_RoleAssisted'),
                    'nb_closed' => Piwik::translate('RebelAttribution_RoleClosed'),
                    'nb_only' => Piwik::translate('RebelAttribution_RoleOnly'),
                ],
            ],
        ]);
    }
}
