<?php

namespace Nimbbl\Magento\Model;

use Nimbbl\Magento\Model\Config;

/**
 * Factory for Nimbbl API client (v3).
 *
 * The Nimbbl PHP SDK is bundled under lib/nimbbl-php-sdk/ and autoloaded via
 * registration.php — no Composer install required. Always returns a real
 * NimbblClient with full support for AES-GCM payload encryption, structured
 * logging, and automatic token refresh.
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
     * Returns a NimbblClient exposing the three Nimbbl service accessors:
     *   orders()->createOrder($payload)
     *   transactions()->transactionEnquiry(['nimbbl_transaction_id' => $id])
     *   refunds()->initiateRefund(['transaction_id' => $id, 'refund_amount' => $amount, ...])
     *
     * The API base URL is resolved from the admin "API Environment" setting:
     *   Production → https://api.nimbbl.tech/api/v3
     *   QA         → https://api-qa1.nimbbl.tech/api/v3
     *
     * @return \Nimbbl\Api\RestClient\NimbblClient
     */
    public function create(): \Nimbbl\Api\RestClient\NimbblClient
    {
        $keyId          = $this->config->getKeyId();
        $keySecret      = $this->config->getKeySecret();
        $apiBase        = $this->config->getApiBase();
        $encryptPayload = $this->config->isEncryptPayload();
        $debugMode      = $this->config->isDebugEnabled();

        // Write SDK logs to var/log/nimbbl.log only when debug mode is on.
        // BP is Magento's base-path constant (always defined at runtime).
        $logFile = $debugMode ? BP . '/var/log/nimbbl.log' : null;

        // SDK constructor: (accessKey, secretKey, apiUrl, logFile, encryptPayload, debugLogging)
        return new \Nimbbl\Api\RestClient\NimbblClient(
            $keyId,
            $keySecret,
            $apiBase,
            $logFile,
            $encryptPayload,
            $debugMode
        );
    }
}
