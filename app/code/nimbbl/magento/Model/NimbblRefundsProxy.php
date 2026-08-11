<?php

namespace Nimbbl\Magento\Model;

/**
 * Refunds service proxy for NimbblCurlClient.
 *
 * Mirrors the interface the full Nimbbl PHP SDK exposes so all callers can use:
 *   $client->refunds()->initiateRefund(['transaction_id' => $id, 'refund_amount' => $amount, ...])
 * regardless of whether the Composer SDK or the lightweight cURL adapter is in use.
 *
 * Method name and endpoint match the SDK exactly:
 *   SDK class:  Nimbbl\Api\Services\Refund::initiateRefund()
 *   Endpoint:   POST /api/v3/refund
 *
 * Required payload keys:
 *   transaction_id  — Nimbbl transaction ID (nimbbl_payment_id on the OrderLink row)
 *   refund_amount   — float, same unit as original payment (e.g. 100.00 for ₹100)
 *   currency        — ISO 4217 code, e.g. "INR"
 *   reason          — short string, e.g. "merchant_initiated"
 *
 * Response fields used by PaymentMethod::refund():
 *   refund_id       — Nimbbl's unique refund identifier
 *   status          — "pending" / "success" / "failed"
 */
class NimbblRefundsProxy
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
     * Initiate a refund against an existing Nimbbl transaction.
     *
     * Mirrors SDK: Nimbbl\Api\Services\Refund::initiateRefund($attributes)
     * Endpoint:    POST /api/v3/refund
     *
     * Auto-generates a merchant token; retries once on 401.
     *
     * @param  array       $attributes  Refund payload — see class docblock for required keys.
     * @param  string|null $token       Optional pre-generated token (ignored; always generates fresh).
     * @return array                    API response array
     * @throws \RuntimeException        On HTTP or token errors
     */
    public function initiateRefund(array $attributes = [], ?string $token = null): array
    {
        $url = $this->client->getApiBase() . '/refund';

        $body = json_encode($attributes);

        $token    = $this->client->generateToken();
        $response = $this->client->post($url, $body, $token);

        // Retry once on 401 (token race)
        if (($response['_http_code'] ?? 0) === 401) {
            $token    = $this->client->generateToken();
            $response = $this->client->post($url, $body, $token);
        }

        if (($response['_http_code'] ?? 0) >= 400) {
            $txnId = (string) ($attributes['transaction_id'] ?? '');
            $msg   = $response['message'] ?? $response['error'] ?? json_encode($response);
            throw new \RuntimeException(
                'Nimbbl refund failed (HTTP ' . ($response['_http_code'] ?? '?') .
                ') txn_id=' . $txnId . ': ' . $msg
            );
        }

        unset($response['_http_code']);
        return $response;
    }
}
