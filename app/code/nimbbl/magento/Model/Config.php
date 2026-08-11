<?php

namespace Nimbbl\Magento\Model;

use \Magento\Framework\App\Config\ScopeConfigInterface;

class Config
{
    const KEY_ALLOW_SPECIFIC = 'allowspecific';
    const KEY_SPECIFIC_COUNTRY = 'specificcountry';
    const KEY_ACTIVE = 'active';
    const KEY_PUBLIC_KEY = 'key_id';
    const KEY_PRIVATE_KEY = 'key_secret';
    const KEY_MERCHANT_NAME_OVERRIDE = 'merchant_name_override';
    const KEY_PAYMENT_ACTION = 'payment_action';
    const ENABLE_WEBHOOK = 'enable_webhook';
    const KEY_ENVIRONMENT = 'environment';
    const KEY_CHECKOUT_MODE = 'checkout_mode';
    const KEY_EXPRESS_CHECKOUT = 'express_checkout';
    const KEY_DEBUG_MODE = 'debug_mode';
    const KEY_ENCRYPT_PAYLOAD = 'encrypt_payload';
    const KEY_CHECKOUT_HOST   = 'checkout_host';
    const CHECKOUT_HOST_DEFAULT = 'https://sonic.nimbbl.tech';

    const API_BASE_PRODUCTION = 'https://api.nimbbl.tech/api/v3';
    const API_BASE_QA         = 'https://api-qa1.nimbbl.tech/api/v3';

    /**
     * @var string
     */
    protected $methodCode = 'nimbbl';

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var int
     */
    protected $storeId = null;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @return string
     */
    public function getMerchantNameOverride()
    {
        return $this->getConfigData(self::KEY_MERCHANT_NAME_OVERRIDE);
    }

    /**
     * Returns the API access key.
     *
     * @return string
     */
    public function getKeyId()
    {
        return $this->getConfigData(self::KEY_PUBLIC_KEY);
    }

    /**
     * Returns the API key secret.
     *
     * @return string
     */
    public function getKeySecret(): string
    {
        return (string) $this->getConfigData(self::KEY_PRIVATE_KEY);
    }

    public function isWebhookEnabled()
    {
        return (bool) (int) $this->getConfigData(self::ENABLE_WEBHOOK, $this->storeId);
    }

    public function getPaymentAction()
    {
        return $this->getConfigData(self::KEY_PAYMENT_ACTION);
    }

    /**
     * Returns the configured API base URL (free-form text field in admin).
     *
     * Falls back to the production URL if the value is empty.
     *
     * @return string  e.g. https://api.nimbbl.tech/api/v3
     */
    public function getApiBase(): string
    {
        $url = trim((string) $this->getConfigData(self::KEY_ENVIRONMENT));
        return $url ?: self::API_BASE_PRODUCTION;
    }

    /**
     * Returns the scheme + host portion of the configured API base URL.
     *
     * P3: Mirrors WooCommerce's api_host token returned to the JS checkout layer.
     * Used to tell the Sonic JS checkout module which API endpoint to use when the
     * store is pointed at QA or a custom host rather than the default production URL.
     *
     * @return string  e.g. https://api.nimbbl.tech
     */
    public function getApiHost(): string
    {
        $apiBase = $this->getApiBase();
        $parsed  = parse_url($apiBase);
        $scheme  = $parsed['scheme'] ?? 'https';
        $host    = $parsed['host']   ?? 'api.nimbbl.tech';
        return $scheme . '://' . $host;
    }

    /**
     * Returns the configured checkout mode: 'popup' or 'redirect'.
     *
     * @return string
     */
    public function getCheckoutMode(): string
    {
        return (string) $this->getConfigData(self::KEY_CHECKOUT_MODE) ?: 'popup';
    }

    /**
     * Returns whether express checkout is enabled (skip Magento address form).
     *
     * @return bool
     */
    public function isExpressCheckout(): bool
    {
        return (bool) (int) $this->getConfigData(self::KEY_EXPRESS_CHECKOUT);
    }

    /**
     * Returns whether verbose debug logging is enabled.
     *
     * When false, $logger->debug() calls are suppressed, reducing log volume in production.
     *
     * @return bool
     */
    public function isDebugEnabled(): bool
    {
        return (bool) (int) $this->getConfigData(self::KEY_DEBUG_MODE);
    }

    /**
     * Returns the Sonic JS checkout host URL.
     *
     * G4: Mirrors WooCommerce's checkout_host setting. Defaults to the canonical Sonic URL;
     * should only be changed when instructed by Nimbbl support (e.g. to a staging host).
     *
     * @return string  e.g. https://sonic.nimbbl.tech
     */
    public function getCheckoutHost(): string
    {
        $host = trim((string) $this->getConfigData(self::KEY_CHECKOUT_HOST));
        return $host !== '' ? rtrim($host, '/') : self::CHECKOUT_HOST_DEFAULT;
    }

    /**
     * Returns whether AES-GCM payload encryption is enabled for outbound API calls.
     *
     * Must match the "Encrypt Payload" toggle in the Nimbbl dashboard.
     * Encryption is handled by the SDK client (NimbblClient); the lightweight cURL
     * fallback (NimbblCurlClient) accepts the flag but does not perform encryption —
     * payloads are still protected by TLS.
     *
     * @return bool
     */
    public function isEncryptPayload(): bool
    {
        return (bool) (int) $this->getConfigData(self::KEY_ENCRYPT_PAYLOAD);
    }

    /**
     * Returns true when the payment mode string represents Cash on Delivery.
     *
     * COD payments are collected physically on delivery; a confirmed COD event
     * means the merchant should prepare to ship, but the order must not be
     * marked complete until cash is physically received.
     * Mirrors WooCommerce's is_cod_payment_mode().
     */
    public static function isCodPaymentMode(string $mode): bool
    {
        return strtolower(trim($mode)) === 'cash on delivery';
    }

    /**
     * Normalise an amount (float or string) to exactly 2 decimal places.
     *
     * Used when building HMAC signature strings that must match the format
     * Nimbbl uses server-side. Strips any thousand-separator commas first.
     * Mirrors WooCommerce format_amount().
     *
     * @param  float|string $amount
     * @return string  e.g. "1234.56"
     */
    public static function normalizeAmount($amount): string
    {
        return number_format((float) str_replace(',', '', (string) $amount), 2, '.', '');
    }

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId($storeId)
    {
        $this->storeId = $storeId;
        return $this;
    }

    /**
     * Retrieve information from payment configuration
     *
     * @param string $field
     * @param null|string $storeId
     *
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        if ($storeId == null) {
            $storeId = $this->storeId;
        }

        $code = $this->methodCode;

        $path = 'payment/' . $code . '/' . $field;
        return $this->scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @return bool
     */
    public function isActive()
    {
        return (bool) (int) $this->getConfigData(self::KEY_ACTIVE, $this->storeId);
    }

    /**
     * To check billing country is allowed for the payment method
     *
     * @param string $country
     * @return bool
     */
    public function canUseForCountry($country)
    {
        /*
        for specific country, the flag will set up as 1
        */
        if ($this->getConfigData(self::KEY_ALLOW_SPECIFIC) == 1) {
            $availableCountries = explode(',', $this->getConfigData(self::KEY_SPECIFIC_COUNTRY));
            if (!in_array($country, $availableCountries)) {
                return false;
            }
        }

        return true;
    }
}
