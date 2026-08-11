<?php

namespace Nimbbl\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for Nimbbl API environment selector.
 *
 * Production  → https://api.nimbbl.tech/api/v3
 * QA          → https://api-qa1.nimbbl.tech/api/v3
 */
class Environment implements OptionSourceInterface
{
    const PRODUCTION = 'production';
    const QA         = 'qa';

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::PRODUCTION, 'label' => __('Production (api.nimbbl.tech)')],
            ['value' => self::QA,         'label' => __('QA (api-qa1.nimbbl.tech)')],
        ];
    }
}
