<?php

namespace Nimbbl\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Source model for Nimbbl checkout mode selector.
 *
 * Popup    → Opens Nimbbl payment overlay inline on the checkout page (default).
 * Redirect → Redirects the customer to the Nimbbl hosted checkout page.
 */
class CheckoutMode implements OptionSourceInterface
{
    const POPUP    = 'popup';
    const REDIRECT = 'redirect';

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::POPUP,    'label' => __('Popup (overlay on checkout page)')],
            ['value' => self::REDIRECT, 'label' => __('Redirect (Nimbbl hosted page)')],
        ];
    }
}
