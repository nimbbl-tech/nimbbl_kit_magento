<?php

namespace Nimbbl\Magento\Model;

/**
 * Transactions service proxy for NimbblCurlClient.
 *
 * Mirrors the interface the full Nimbbl PHP SDK exposes so all callers can use:
 *   $client->transactions()->transactionEnquiry(['nimbbl_transaction_id' => $id])
 * regardless of whether the Composer SDK or the lightweight cURL adapter is in use.
 *
 * Method name and endpoint match the SDK exactly:
 *   SDK class:  Nimbbl\Api\Services\Transaction::transactionEnquiry()
 *   Endpoint:   POST /api/v3/transaction-enquiry
 *
 * Response fields used by PaymentMethod::verifyTransactionWithApi():
 *   payment_status  — "success", "succeeded", "failed", "pending", "authorized" …
 *   total_amount    — float (e.g. 100.0  for ₹100.00)
 *   currency        — "INR"
 *   payment_mode    — e.g. "upi", "card"
 */
class NimbblTransactionsProxy
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
     * Fetch a transaction via the Nimbbl Transaction Enquiry endpoint.
     *
     * Mirrors SDK: Nimbbl\Api\Services\Transaction::transactionEnquiry($attributes)
     * Endpoint:    POST /api/v3/transaction-enquiry
     *
     * Token generation and retry-on-401 are handled by NimbblCurlClient::postAuthenticated().
     *
     * @param  array       $attributes  Must include 'nimbbl_transaction_id' key.
     * @param  string|null $token       Unused; accepted for interface parity with the SDK.
     * @return array                    API response array
     * @throws \RuntimeException        On HTTP or token errors
     */
    public function transactionEnquiry(array $attributes = [], ?string $token = null): array
    {
        return $this->client->postAuthenticated(
            $this->client->getApiBase() . '/transaction-enquiry',
            json_encode($attributes)
        );
    }
}
