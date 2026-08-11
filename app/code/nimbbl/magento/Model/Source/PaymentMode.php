<?php

namespace Nimbbl\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for the Payment Mode admin field (G3: test/live key pair split).
 *
 * 'sandbox'    → uses test_key_id / test_key_secret
 * 'production' → uses live_key_id / live_key_secret
 */
class PaymentMode implements OptionSourceInterface
{
    /**
     * @return array[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'sandbox',    'label' => __('Sandbox (Test)')],
            ['value' => 'production', 'label' => __('Production (Live)')],
        ];
    }
}
