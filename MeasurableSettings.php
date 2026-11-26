<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelAttribution;

use Exception;
use Piwik\Archive\ArchiveInvalidator;
use Piwik\Container\StaticContainer;
use Piwik\Piwik;
use Piwik\Settings\FieldConfig;
use Piwik\Settings\Setting;

class MeasurableSettings extends \Piwik\Settings\Measurable\MeasurableSettings
{
    public const DAYS = [7, 14, 30, 60, 90];
    public const DEFAULT_DAYS = 30;

    /**
     * @var Setting
     */
    public $days;

    private int $savedDays = self::DEFAULT_DAYS;

    protected function init()
    {
        $this->days = $this->makeSetting('days', self::DEFAULT_DAYS, FieldConfig::TYPE_INT, function (FieldConfig $field) {
            $field->title = Piwik::translate('RebelAttribution_SettingDays');
            $field->inlineHelp = Piwik::translate('RebelAttribution_SettingDaysHelp');
            $field->uiControl = FieldConfig::UI_CONTROL_SINGLE_SELECT;
            foreach (self::DAYS as $days) {
                $field->availableValues[$days] = Piwik::translate('RebelAttribution_NDays', $days);
            }
            $field->validate = function ($value) {
                if (!in_array((int) $value, self::DAYS, true)) {
                    throw new Exception(Piwik::translate('RebelAttribution_ErrorDays', implode(', ', self::DAYS)));
                }
            };
        });
        $this->savedDays = (int) $this->days->getValue();
    }

    /**
     * @return int how many days before a conversion the visits of a visitor are its journey
     */
    public static function getDays(int $idSite): int
    {
        return (int) (new self($idSite))->days->getValue();
    }

    public function save()
    {
        parent::save();

        // The archived journeys were built with the days there were before.
        if ((int) $this->days->getValue() !== $this->savedDays) {
            $this->savedDays = (int) $this->days->getValue();
            StaticContainer::get(ArchiveInvalidator::class)->scheduleReArchiving([$this->idSite], 'RebelAttribution');
        }
    }
}
