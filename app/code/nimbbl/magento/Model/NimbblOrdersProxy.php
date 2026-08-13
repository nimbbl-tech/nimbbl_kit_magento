<?php

namespace Nimbbl\Magento\Model;

/**
 * Orders service proxy for NimbblCurlClient.
 *
 * Provides the same interface as the SDK's Order service so Order.php can call
 *   $client->orders()->createOrder($payload)
 * regardless of whether the full SDK or the cURL adapter is in use.
 */
class NimbblOrdersProxy
{
    /** @var NimbblCurlClient */
    protected $client;

    /**
     * @param NimbblCurlClient $client
     */
    public function __construct(NimbblCurlClient $client)
    {
        $this->client = $client;
    }

    /**
     * Create an order via Nimbbl v3 API.
     *
     * Token generation and retry-on-401 are handled by NimbblCurlClient::postAuthenticated().
     *
     * @param  array       $attributes  Order payload (snake_case fields)
     * @param  string|null $token       Unused; accepted for interface parity with the SDK.
     * @return array                    API response array
     * @throws \RuntimeException        On HTTP or token error
     */
    public function createOrder(array $attributes, ?string $token = null): array
    {
        return $this->client->postAuthenticated(
            $this->client->getApiBase() . '/create-order',
            json_encode($attributes)
        );
    }
}
