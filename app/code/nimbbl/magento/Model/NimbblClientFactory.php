<?php

namespace Nimbbl\Magento\Model;

use Nimbbl\Magento\Model\Config;

/**
 * Factory for Nimbbl API client (v3).
 *
 * Follows the Razorpay Magento plugin pattern:
 *  - Composer install  → nimbbl/nimbbl-sdk is autoloaded; returns a real NimbblClient.
 *  - Zip/manual install → SDK not present; returns a lightweight cURL adapter that
 *    speaks the same interface used by Order.php (orders()->createOrder()).
 *
 * No bundled libraries required — zero extra steps for merchants.
 */
class NimbblClientFactory
{
    const API_BASE = 'https://api.nimbbl.tech/api/v3';

    /**
     * @var Config
     */
    protected $config;

    /**
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Returns an object with an orders() method that exposes createOrder().
     *
     * @return \Nimbbl\Api\RestClient\NimbblClient|NimbblCurlClient
     */
    public function create()
    {
        $keyId     = $this->config->getKeyId();
        $keySecret = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);

        if (class_exists(\Nimbbl\Api\RestClient\NimbblClient::class)) {
            // Composer install — use the full SDK (v3 by default)
            return new \Nimbbl\Api\RestClient\NimbblClient($keyId, $keySecret);
        }

        // Manual/zip install — lightweight cURL adapter (v3 endpoints)
        return new NimbblCurlClient($keyId, $keySecret, self::API_BASE);
    }
}
