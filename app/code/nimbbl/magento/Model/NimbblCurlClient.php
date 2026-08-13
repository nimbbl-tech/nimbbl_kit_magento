<?php

namespace Nimbbl\Magento\Model;

/**
 * Lightweight Nimbbl API v3 cURL adapter.
 *
 * Used when nimbbl/nimbbl-sdk is not installed via Composer (zip/manual installs).
 * Exposes the same interface as NimbblClient so callers can use:
 *
 *   $client->orders()->createOrder($payload)
 *   $client->transactions()->transactionEnquiry(['nimbbl_transaction_id' => $id])
 *   $client->refunds()->initiateRefund(['transaction_id' => $id, 'refund_amount' => $amount, ...])
 *
 * Token generation and retry-on-401 are handled internally, matching the SDK behaviour.
 */
class NimbblCurlClient
{
    /** @var string */
    protected $keyId;

    /** @var string */
    protected $keySecret;

    /** @var string */
    protected $apiBase;

    /**
     * Whether the caller requested AES-GCM payload encryption.
     *
     * NOTE: The cURL fallback does NOT apply encryption — it is only stored so callers can
     * detect the mismatch. Full encryption support requires the Composer SDK (NimbblClient).
     *
     * @var bool
     */
    protected $encryptPayload;

    /**
     * @param string $keyId
     * @param string $keySecret
     * @param string $apiBase        e.g. https://api.nimbbl.tech/api/v3
     * @param bool   $encryptPayload Stored but not applied; see property docblock.
     */
    public function __construct(string $keyId, string $keySecret, string $apiBase, bool $encryptPayload = false)
    {
        $this->keyId          = $keyId;
        $this->keySecret      = $keySecret;
        $this->apiBase        = rtrim($apiBase, '/');
        $this->encryptPayload = $encryptPayload;
    }

    /**
     * Returns an Orders service proxy bound to this client.
     *
     * @return NimbblOrdersProxy
     */
    public function orders(): NimbblOrdersProxy
    {
        return new NimbblOrdersProxy($this);
    }

    /**
     * Returns a Transactions service proxy bound to this client.
     *
     * @return NimbblTransactionsProxy
     */
    public function transactions(): NimbblTransactionsProxy
    {
        return new NimbblTransactionsProxy($this);
    }

    /**
     * Returns a Refunds service proxy bound to this client.
     *
     * @return NimbblRefundsProxy
     */
    public function refunds(): NimbblRefundsProxy
    {
        return new NimbblRefundsProxy($this);
    }

    /**
     * Generate a Nimbbl merchant token (v3).
     *
     * @return string
     * @throws \RuntimeException on failure
     */
    public function generateToken(): string
    {
        $url  = $this->apiBase . '/generate-token';
        $body = json_encode(['access_key' => $this->keyId, 'access_secret' => $this->keySecret]);

        $response = $this->post($url, $body);

        if (empty($response['token'])) {
            throw new \RuntimeException('Nimbbl: failed to generate auth token. Response: ' . json_encode($response));
        }

        return $response['token'];
    }

    /**
     * POST $url with JSON $body, generating a fresh token and retrying once on 401.
     *
     * This is the primary method for all authenticated API calls. It handles the
     * token lifecycle internally so callers never touch `_http_code`.
     *
     * @param  string $url   Full endpoint URL.
     * @param  string $body  JSON-encoded request body.
     * @return array         Decoded response body (no `_http_code` key).
     * @throws \RuntimeException On HTTP >= 400 after retry.
     */
    public function postAuthenticated(string $url, string $body): array
    {
        $token    = $this->generateToken();
        $response = $this->post($url, $body, $token);

        // Retry once on 401 (token race between order creation and callback)
        if (($response['_http_code'] ?? 0) === 401) {
            $token    = $this->generateToken();
            $response = $this->post($url, $body, $token);
        }

        $code = $response['_http_code'] ?? 0;
        unset($response['_http_code']);

        if ($code >= 400) {
            throw new \RuntimeException(
                'Nimbbl API POST ' . $url . ' failed (HTTP ' . $code . '): ' . json_encode($response)
            );
        }

        return $response;
    }

    /**
     * POST $url with JSON $body, optionally authenticated.
     *
     * Prefer {@see postAuthenticated()} for all token-gated endpoints.
     *
     * @param  string      $url
     * @param  string      $body
     * @param  string|null $token
     * @return array       Decoded JSON response with '_http_code' key appended
     */
    public function post(string $url, string $body, ?string $token = null): array
    {
        $headers = ['Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);

        return $this->executeCurl($curl);
    }

    /**
     * GET $url, optionally authenticated with a Bearer token.
     *
     * @param  string      $url
     * @param  string|null $token
     * @return array       Decoded JSON response with '_http_code' key appended
     */
    public function get(string $url, ?string $token = null): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);

        return $this->executeCurl($curl);
    }

    /**
     * Execute a curl handle, decode the JSON response, and append `_http_code`.
     *
     * Centralises the curl lifecycle (exec → getinfo → close → decode) so post()
     * and get() share a single implementation path.
     *
     * @param  \CurlHandle $curl  A configured handle (options already set).
     * @return array              Decoded body with `_http_code` appended.
     */
    private function executeCurl(\CurlHandle $curl): array
    {
        $raw  = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $decoded = json_decode((string) $raw, true) ?? [];
        $decoded['_http_code'] = $code;

        return $decoded;
    }

    public function getApiBase(): string       { return $this->apiBase; }
    public function getKeyId(): string         { return $this->keyId; }
    public function getKeySecret(): string     { return $this->keySecret; }
    public function isEncryptPayload(): bool   { return $this->encryptPayload; }
}
