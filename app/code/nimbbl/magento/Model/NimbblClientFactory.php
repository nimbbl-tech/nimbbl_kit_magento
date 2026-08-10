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
     * The API base URL is resolved from the admin "API Environment" setting:
     *   Production → https://api.nimbbl.tech/api/v3
     *   QA         → https://api-qa1.nimbbl.tech/api/v3
     *
     * @return \Nimbbl\Api\RestClient\NimbblClient|NimbblCurlClient
     */
    public function create()
    {
        $keyId          = $this->config->getKeyId();
        $keySecret      = $this->config->getKeySecret();            // BUG-1 fix: was getConfigData(KEY_PRIVATE_KEY), bypassed G3 test/live key selection
        $apiBase        = $this->config->getApiBase();
        $encryptPayload = $this->config->isEncryptPayload();
        $debugMode      = $this->config->isDebugEnabled();

        // P5: Configure SDK log file when debug mode is on — mirrors WooCommerce NimbblClient::setLogFile().
        // BP is Magento's base path constant (always defined). Pass null in production to avoid file I/O.
        $logFile = $debugMode ? BP . '/var/log/nimbbl.log' : null;

        if (class_exists(\Nimbbl\Api\RestClient\NimbblClient::class)) {
            // Composer install — SDK v4 constructor:
            //   (accessKey, secretKey, apiUrl, logFile, encryptPayload, debugLogging)
            return new \Nimbbl\Api\RestClient\NimbblClient(
                $keyId,
                $keySecret,
                $apiBase,
                $logFile,        // P5: SDK writes to var/log/nimbbl.log when debug is on
                $encryptPayload, // G1: AES-GCM encryption of outbound payloads
                $debugMode
            );
        }

        // Manual/zip install — lightweight cURL adapter (v3 endpoints).
        // G1: encrypt_payload flag is stored but AES-GCM encryption is not applied in
        // the fallback path (requires the full SDK for format compatibility). Payloads
        // remain protected by TLS. Upgrade to Composer install to enable encryption.
        return new NimbblCurlClient($keyId, $keySecret, $apiBase, $encryptPayload);
    }
}
