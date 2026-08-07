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
     * Auto-generates a merchant token; retries once on 401.
     *
     * @param  array $attributes  Order payload (snake_case fields)
     * @param  string|null $token Optional pre-generated token (ignored here; token is always fresh)
     * @return array              API response array
     * @throws \RuntimeException  on HTTP or token error
     */
    public function createOrder(array $attributes, ?string $token = null): array
    {
        $url   = $this->client->getApiBase() . '/create-order';
        $body  = json_encode($attributes);
        $token = $this->client->generateToken();

        $response = $this->client->post($url, $body, $token);

        // Retry once if token expired (shouldn't happen, but defensive)
        if (($response['_http_code'] ?? 0) === 401) {
            $token    = $this->client->generateToken();
            $response = $this->client->post($url, $body, $token);
        }

        if (($response['_http_code'] ?? 0) >= 400) {
            throw new \RuntimeException(
                'Nimbbl create-order failed (HTTP ' . ($response['_http_code'] ?? '?') . '): ' . json_encode($response)
            );
        }

        unset($response['_http_code']);
        return $response;
    }
}
