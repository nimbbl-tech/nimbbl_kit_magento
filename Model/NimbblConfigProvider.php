<?php

namespace Nimbbl\Magento\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Store\Model\StoreManagerInterface;
use Nimbbl\Magento\Helper\Data;

/**
 * Class NimbblConfigProvider
 * @package Nimbbl\Magento\Model
 */
class NimbblConfigProvider implements ConfigProviderInterface
{
    /**
     * @var Data
     */
    protected $helper;
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var array
     */
    protected $methodCodes = ['nimbbl'];
    /**
     * @var array
     */
    protected $methods = [];

    /**
     * NimbblConfigProvider constructor.
     * @param Data $helper
     * @param PaymentHelper $paymentHelper
     * @param StoreManagerInterface $storeManager
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function __construct(Data $helper, PaymentHelper $paymentHelper, StoreManagerInterface $storeManager)
    {
        $this->helper = $helper;
        $this->storeManager = $storeManager;
        foreach ($this->methodCodes as $code) {
            $this->methods[$code] = $paymentHelper->getMethodInstance($code);
        }
    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getConfig()
    {
        $redirectUrl = $this->storeManager->getStore()->getBaseUrl() . 'nimbbl/payment/redirect';
        $showLogo = $this->helper->showLogo();
        $imageUrl = $this->helper->getPaymentLogo();

        $config = [];
        $config['payment']['nimbbl_payment']['imageurl'] = ($showLogo) ? $imageUrl : '';
        $config['payment']['nimbbl_payment']['is_active'] = $this->helper->isActive();
        $config['payment']['nimbbl_payment']['payment_instruction'] = trim($this->helper->getPaymentInstructions());
        $config['payment']['nimbbl_payment']['redirect_url'] = $redirectUrl;

        return $config;
    }
}
