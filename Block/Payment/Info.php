<?php

namespace Nimbbl\Magento\Block\Payment;

use Magento\Payment\Block\ConfigurableInfo;

/**
 * Class Info
 * @package Nimbbl\Magento\Block\Payment
 */
class Info extends ConfigurableInfo
{
    /**
     * @var string
     */
    protected $_template = 'Nimbbl_Magento::info.phtml';

    /**
     * @param string $field
     * @return \Magento\Framework\Phrase|string
     */
    public function getLabel($field)
    {
        switch ($field) {
            case 'payment_partner':
                return __('Payment Partner');
            case 'masked_card':
                return __('Masked Card');
            case 'transaction_amount':
                return __('Transaction Amount');
            case 'payment_mode':
                return __('Payment Mode');
            case 'status':
                return __('Status');
            case 'transaction_id':
                return __('Transaction ID');
            default:
                return __($field);
                break;
        }
    }
}
