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
     * Token generation and retry-on-401 are handled by NimbblCurlClient::postAuthenticated().
     *
     * @param  array       $attributes  Refund payload — see class docblock for required keys.
     * @param  string|null $token       Unused; accepted for interface parity with the SDK.
     * @return array                    API response array
     * @throws \RuntimeException        On HTTP or token errors
     */
    public function initiateRefund(array $attributes = [], ?string $token = null): array
    {
        return $this->client->postAuthenticated(
            $this->client->getApiBase() . '/refund',
            json_encode($attributes)
        );
    }
}
