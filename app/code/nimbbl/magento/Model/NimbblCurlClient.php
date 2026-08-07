<?php

namespace Nimbbl\Magento\Model;

/**
 * Lightweight Nimbbl API v3 cURL adapter.
 *
 * Used when nimbbl/nimbbl-sdk is not installed via Composer (zip/manual installs).
 * Exposes the same interface as NimbblClient so Order.php can call:
 *
 *   $client->orders()->createOrder($payload)
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
     * @param string $keyId
     * @param string $keySecret
     * @param string $apiBase  e.g. https://api.nimbbl.tech/api/v3
     */
    public function __construct(string $keyId, string $keySecret, string $apiBase)
    {
        $this->keyId    = $keyId;
        $this->keySecret = $keySecret;
        $this->apiBase  = rtrim($apiBase, '/');
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
     * POST $url with JSON $body, optionally authenticated.
     *
     * @param  string      $url
     * @param  string      $body
     * @param  string|null $token
     * @return array
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

        $raw  = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $decoded = json_decode((string) $raw, true) ?? [];
        $decoded['_http_code'] = $code;

        return $decoded;
    }

    public function getApiBase(): string  { return $this->apiBase; }
    public function getKeyId(): string    { return $this->keyId; }
    public function getKeySecret(): string { return $this->keySecret; }
}
