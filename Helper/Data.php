<?php

namespace Nimbbl\Magento\Helper;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Session\SessionManager;
use Magento\Framework\View\Asset\Repository;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Filesystem;

/**
 * Class Data
 * @package Nimbbl\Magento\Helper
 */
class Data extends AbstractHelper
{
    const CONFIG_NIMBBL_ACTIVE = 'payment/nimbbl/active';
    const CONFIG_NIMBBL_MODE = 'payment/nimbbl/mode';
    const CONFIG_NIMBBL_LOGO = 'payment/nimbbl/show_logo';
    const CONFIG_NIMBBL_INSTRUCTIONS = 'payment/nimbbl/instructions';
    const CONFIG_NIMBBL_SANDBOX_ACCESS_KEY = 'payment/nimbbl/sandbox_access_key';
    const CONFIG_NIMBBL_LIVE_ACCESS_KEY = 'payment/nimbbl/live_access_key';
    const CONFIG_NIMBBL_SANDBOX_ACCESS_SECRET = 'payment/nimbbl/sandbox_access_secret';
    const CONFIG_NIMBBL_LIVE_ACCESS_SECRET = 'payment/nimbbl/live_access_secret';
    const CONFIG_NIMBBL_SANDBOX_GATEWAY_URL = 'payment/nimbbl/sandbox_gateway_url';
    const CONFIG_NIMBBL_LIVE_GATEWAY_URL = 'payment/nimbbl/live_gateway_url';
    const CONFIG_NIMBBL_SANDBOX_TOKEN_URL = 'payment/nimbbl/sandbox_token_url';
    const CONFIG_NIMBBL_LIVE_TOKEN_URL = 'payment/nimbbl/live_token_url';
    const CONFIG_NIMBBL_INVOICE = 'payment/nimbbl/allow_invoice';
    const CONFIG_NIMBBL_DEBUG = 'payment/nimbbl/debug';

    /**
     * @var DirectoryList
     */
    protected $directoryList;
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var Http
     */
    protected $request;
    /**
     * @var EncryptorInterface
     */
    protected $encryptor;
    /**
     * @var SessionManager
     */
    protected $sessionManager;
    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;
    /**
     * @var Repository
     */
    protected $repository;
    /**
     * @var Filesystem
     */
    protected $fileSystem;

    /**
     * Data constructor.
     * @param Context $context
     * @param EncryptorInterface $encryptor
     * @param DirectoryList $directoryList
     * @param StoreManagerInterface $storeManager
     * @param Http $request
     * @param SessionManager $sessionManager
     * @param Repository $repository
     * @param CheckoutSession $checkoutSession
     * @param Filesystem $fileSystem
     */
    public function __construct(
        Context $context,
        EncryptorInterface $encryptor,
        DirectoryList $directoryList,
        StoreManagerInterface $storeManager,
        Http $request,
        SessionManager $sessionManager,
        Repository $repository,
        CheckoutSession $checkoutSession,
        Filesystem $fileSystem
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
        $this->directoryList = $directoryList;
        $this->storeManager = $storeManager;
        $this->request = $request;
        $this->sessionManager = $sessionManager;
        $this->repository = $repository;
        $this->checkoutSession = $checkoutSession;
        $this->fileSystem = $fileSystem;
    }

    /**
     * @return mixed
     */
    public function isDebug()
    {
        return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_DEBUG, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return mixed
     */
    public function isAutoInvoice()
    {
        return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_INVOICE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return mixed
     */
    public function isActive()
    {
        return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_ACTIVE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return mixed
     */
    public function getPaymentInstructions()
    {
        return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_INSTRUCTIONS, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return mixed
     */
    public function getAccessKey()
    {
        if ($this->getMode()) {
            return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_SANDBOX_ACCESS_KEY,
                ScopeInterface::SCOPE_STORE);
        } else {
            return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_LIVE_ACCESS_KEY,
                ScopeInterface::SCOPE_STORE);
        }
    }

    /**
     * @return string
     */
    public function getAccessSecret()
    {
        if ($this->getMode()) {
            return $this->encryptor->decrypt($this->scopeConfig->getValue(self::CONFIG_NIMBBL_SANDBOX_ACCESS_SECRET,
                ScopeInterface::SCOPE_STORE));
        } else {
            return $this->encryptor->decrypt($this->scopeConfig->getValue(self::CONFIG_NIMBBL_LIVE_ACCESS_SECRET,
                ScopeInterface::SCOPE_STORE));
        }
    }

    /**
     * @return mixed
     */
    public function getMode()
    {
        return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_MODE, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return mixed
     */
    public function getGatewayUrl()
    {
        if ($this->getMode()) {
            return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_SANDBOX_GATEWAY_URL,
                ScopeInterface::SCOPE_STORE);
        } else {
            return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_LIVE_GATEWAY_URL, ScopeInterface::SCOPE_STORE);
        }
    }

    /**
     * @return mixed
     */
    public function getTokenUrl()
    {
        if ($this->getMode()) {
            return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_SANDBOX_TOKEN_URL,
                ScopeInterface::SCOPE_STORE);
        } else {
            return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_LIVE_TOKEN_URL, ScopeInterface::SCOPE_STORE);
        }
    }

    /**
     * @return mixed
     */
    public function showLogo()
    {
        return $this->scopeConfig->getValue(self::CONFIG_NIMBBL_LOGO, ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getPaymentLogo()
    {
        $params = ['_secure' => $this->request->isSecure()];
        return $this->repository->getUrlWithParams('Nimbbl_Magento::images/nimbbl-logo.png', $params);
    }

    public function getErrorImgUrl()
    {
        $params = ['_secure' => $this->request->isSecure()];
        return $this->repository->getUrlWithParams('Nimbbl_Magento::images/Box_Illustration.png', $params);
    }

    /**
     * @param $message
     * @param $data
     * @throws \Zend_Log_Exception
     */
    public function logger($message, $data)
    {
        if ($this->isDebug()) {
            $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/nimbbl.log');
            $logger = new \Zend_Log();
            $logger->addWriter($writer);
            if (!is_array($data)) {
                $data = (array)$data;
            }
            $logger->info($message);
            $logger->info(print_r($data, true));
        }
    }
}
