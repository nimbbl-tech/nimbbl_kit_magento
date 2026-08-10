<?php

namespace Nimbbl\Magento\Controller\Payment;

use Nimbbl\Magento\Model\Config;
use Nimbbl\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\Order as SalesOrder;

/**
 * Nimbbl Webhook Controller (v3 API).
 *
 * Ported from the WooCommerce plugin (wc-nimbbl-payment-gateway.php → process_webhook /
 * process_webhook_by_event_type) to Magento patterns.
 *
 * Verification flow (mirrors WooCommerce):
 *   1. SDK \Nimbbl\Api\Common\SignatureVerifier::verifyWebhook() when the full SDK is installed —
 *      handles v4 signed-envelope, encrypted, legacy formats transparently.
 *   2. Fallback: json_decode + HMAC-SHA256 (v2/v3 signature strings) when SDK is absent.
 *
 * Supported events:
 *   payment_success   → sets order to Processing / Closed (COD-aware future extension)
 *   payment_failed    → cancels order
 *   refund_success    → closes order
 *   refund_failed     → adds order note
 *   payment_authorized → puts order on hold (pre-auth; capture fires payment_success later)
 *
 * Always returns HTTP 200 to prevent Nimbbl retry loops.
 */
class Webhook extends \Nimbbl\Magento\Controller\BaseController
{
    /** @var \Magento\Quote\Model\QuoteRepository */
    protected $quoteRepository;

    /** @var \Magento\Quote\Model\QuoteManagement */
    protected $quoteManagement;

    /** @var \Magento\Sales\Api\OrderRepositoryInterface */
    protected $orderRepository;

    /** @var \Magento\Store\Model\StoreManagerInterface */
    protected $storeManagement;

    /** @var \Magento\Customer\Api\CustomerRepositoryInterface */
    protected $customerRepository;

    /** @var \Magento\Framework\App\CacheInterface */
    protected $cache;

    /** @var \Magento\Framework\Event\ManagerInterface */
    protected $eventManager;

    /** @var \Psr\Log\LoggerInterface */
    protected $logger;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Nimbbl\Magento\Model\Config $config,
        \Magento\Quote\Model\QuoteRepository $quoteRepository,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Store\Model\StoreManagerInterface $storeManagement,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct($context, $customerSession, $checkoutSession, $config);

        $this->quoteRepository     = $quoteRepository;
        $this->quoteManagement     = $quoteManagement;
        $this->orderRepository     = $orderRepository;
        $this->storeManagement     = $storeManagement;
        $this->customerRepository  = $customerRepository;
        $this->cache               = $cache;
        $this->eventManager        = $eventManager;
        $this->logger              = $logger;
    }

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    public function execute()
    {
        $payload = $this->getRawBody();

        if (empty($payload)) {
            $this->logger->error('Nimbbl Webhook: empty payload.');
            return $this->sendResponse(200);
        }

        if (!$this->config->isWebhookEnabled()) {
            $this->logger->info('Nimbbl Webhook: webhook disabled in admin config.');
            return $this->sendResponse(200);
        }

        $webhookData = null;
        $verified    = false;

        // Webhook verification uses the dedicated webhook_secret, not the API key_secret.
        // Using the API key here is wrong: Nimbbl signs webhook payloads with a separate
        // secret configured in the Nimbbl dashboard. $this->key_secret (API private key)
        // is only for on-demand API requests, not for webhook envelope verification.
        $webhookSecret = (string) $this->config->getWebhookSecret();

        if ($webhookSecret === '') {
            // An empty secret produces a deterministic HMAC that any attacker can
            // pre-compute. Reject all incoming webhooks until the secret is configured
            // rather than allowing forged payloads through.
            $this->logger->critical(
                'Nimbbl Webhook: webhook_secret is not configured — all incoming webhooks ' .
                'are rejected. Set the Webhook Secret in Stores → Config → Payment → Nimbbl.'
            );
            return $this->sendResponse(200);
        }

        // ----- Primary: Nimbbl SDK SignatureVerifier -----
        if (class_exists(\Nimbbl\Api\Common\SignatureVerifier::class)) {
            try {
                $this->debugLog('Nimbbl Webhook: using SDK SignatureVerifier::verifyWebhook().');
                $verifier = new \Nimbbl\Api\Common\SignatureVerifier();
                $verify   = $verifier->verifyWebhook($payload, $webhookSecret);
                $verified = is_array($verify) && !empty($verify['success']);
                $webhookData = ($verified && isset($verify['payload']) && is_array($verify['payload']))
                    ? $verify['payload']
                    : null;

                if (!$verified) {
                    $this->logger->error('Nimbbl Webhook: SDK verifyWebhook failed: ' .
                        ($verify['message'] ?? 'unknown reason'));
                }
            } catch (\Exception $e) {
                $this->logger->error('Nimbbl Webhook: SDK verify exception: ' . $e->getMessage());
                return $this->sendResponse(200);
            }
        } else {
            // ----- Fallback: json_decode + HMAC-SHA256 -----
            $this->debugLog('Nimbbl Webhook: SDK absent — using HMAC fallback verification.');
            $webhookData = json_decode($payload, true);
            if (is_array($webhookData)) {
                $verified = $this->verifyNimbblPaymentSignature($webhookData, $webhookSecret);
            }
        }

        if (!is_array($webhookData)) {
            $this->logger->error('Nimbbl Webhook: payload could not be parsed.');
            return $this->sendResponse(200);
        }

        // ----- Reject unverified payloads before any order lookup -----
        // Doing the order lookup first would let an attacker with a known invoice_id
        // probe which order IDs are active and pollute order history with noise.
        if (!$verified) {
            $this->logger->error('Nimbbl Webhook: signature mismatch — discarding payload.');
            return $this->sendResponse(200);
        }

        // ----- Resolve Magento order (only after signature is confirmed) -----
        $order = $this->getOrderFromWebhookData($webhookData);

        // If no order exists yet (customer closed browser before landing on success page):
        // attempt to create it from the still-active quote — same safety net the old code had,
        // but only for verified, actionable events.
        if (!$order) {
            $order = $this->createOrderFromWebhook($webhookData);
        }

        if (!$order) {
            $this->logger->info('Nimbbl Webhook: no matching Magento order found — ' .
                json_encode($webhookData));
            return $this->sendResponse(200);
        }

        // ----- Dispatch by event_type -----
        $eventType           = strtolower(trim((string) ($webhookData['event_type'] ?? '')));
        $transaction         = is_array($webhookData['transaction'] ?? null) ? $webhookData['transaction'] : [];
        $nimbblTransactionId = (string) ($webhookData['nimbbl_transaction_id'] ?? ($transaction['transaction_id'] ?? ''));
        $message             = (string) ($webhookData['message'] ?? '');

        $this->logger->info('Nimbbl Webhook: event_type=' . $eventType .
            ' order_increment=' . $order->getIncrementId());

        $this->processWebhookByEventType($order, $webhookData, $eventType, $transaction, $nimbblTransactionId, $message);

        return $this->sendResponse(200);
    }

    // -------------------------------------------------------------------------
    // Event dispatcher (mirrors WooCommerce process_webhook_by_event_type)
    // -------------------------------------------------------------------------

    protected function processWebhookByEventType(
        $order,
        array  $webhookData,
        string $eventType,
        array  $transaction,
        string $nimbblTransactionId,
        string $message
    ): void {
        $orderState  = $order->getState();

        // Common fields available across all event types.
        $paymentMode = (string) ($transaction['payment_mode'] ?? '');
        $invoiceId   = (string) ($webhookData['order']['invoice_id'] ?? '');

        switch ($eventType) {

            // -----------------------------------------------------------------
            case 'payment_success':
                $txnStatus = strtolower(trim((string) (
                    $transaction['payment_status'] ?? $transaction['status'] ?? ''
                )));
                $isSuccess = in_array($txnStatus, ['succeeded', 'success'], true);

                $alreadyProcessed = in_array($orderState, [
                    SalesOrder::STATE_CANCELED,
                    SalesOrder::STATE_CLOSED,
                    SalesOrder::STATE_PROCESSING,
                    SalesOrder::STATE_COMPLETE,
                ], true);

                if ($isSuccess && $alreadyProcessed) {
                    // Nimbbl retries webhooks on network timeouts. Skip silently once the
                    // order is already in an actionable or terminal state to prevent
                    // duplicate history comments and unnecessary DB writes.
                    $this->logger->info('Nimbbl Webhook: payment_success ignored — order already ' .
                        $orderState . ' (duplicate delivery) order=' . $order->getIncrementId());
                    break; // no $order->save() — nothing changed
                }

                if ($isSuccess) {
                    $payment = $order->getPayment();
                    $payment->setLastTransId($nimbblTransactionId)
                            ->setTransactionId($nimbblTransactionId)
                            ->setIsTransactionClosed(true)
                            ->setShouldCloseParentTransaction(true);

                    // Persist payment metadata from the webhook payload.
                    // $payment->save() is called explicitly because $order->save() does not
                    // reliably cascade to flush the additional_information blob in all 2.4.x
                    // minor versions — the payment resource model manages it separately.
                    if (!empty($paymentMode)) {
                        $payment->setAdditionalInformation('nimbbl_payment_mode', $paymentMode);
                    }
                    if (!empty($invoiceId)) {
                        $payment->setAdditionalInformation('nimbbl_invoice_id', $invoiceId);
                    }
                    $payment->save();

                    // All payment modes stay in STATE_PROCESSING.
                    // Magento requires an invoice to exist before an order can reach
                    // STATE_COMPLETE; forcing that state without an invoice breaks
                    // financial reports, credit-memo creation, and third-party integrations.
                    // Merchants who want orders auto-completed should enable automatic
                    // invoicing in Stores → Configuration → Sales → Sales → Order Processing.
                    $configStatus = $this->config->getConfigData('order_status') ?: 'processing';
                    $order->setState(SalesOrder::STATE_PROCESSING)->setStatus($configStatus);

                    $modeLabel = $paymentMode ? ' Payment mode: ' . $paymentMode : '';
                    if ($this->isCodPaymentMode($paymentMode)) {
                        $order->addCommentToStatusHistory(
                            'Payment via Cash on Delivery confirmed (Nimbbl webhook). Transaction ID: ' .
                            $nimbblTransactionId . $modeLabel
                        );
                        $this->logger->info('Nimbbl Webhook: payment_success (COD) — order ' . $order->getIncrementId());
                    } else {
                        $order->addCommentToStatusHistory(
                            'Payment succeeded via Nimbbl webhook. Transaction ID: ' .
                            $nimbblTransactionId . $modeLabel
                        );
                        $this->logger->info('Nimbbl Webhook: payment_success — order ' . $order->getIncrementId() .
                            ' mode=' . $paymentMode);
                    }

                    // G7 / P4: Currency conversion notes — mirrors WooCommerce build_nimbbl_currency_conversion_notes().
                    // Reads currency_conversion sub-objects (exchange_rate, original_total_amount) from both
                    // the transaction and order blocks; falls back to simple currency-code comparison (G7).
                    $conversionNote = $this->buildCurrencyConversionNotes($transaction, $webhookData);
                    if ($conversionNote !== '') {
                        $order->addCommentToStatusHistory($conversionNote);
                        $this->logger->info(
                            'Nimbbl Webhook: currency conversion note — order=' . $order->getIncrementId()
                        );
                    }

                    // G6: Charge reconciliation — when Nimbbl adds COD fees, convenience fees,
                    // or discounts to the transaction, the settlement amount may differ from the
                    // Magento order grand total. Add a reconciliation note so merchants know.
                    // Magento's order total is locked at creation time; adjustments must be
                    // applied manually (credit memo / invoice adjustment) after review.
                    $txnTotal     = (float) ($transaction['transaction_amount'] ?? 0);
                    $codCharge    = (float) ($transaction['cod_charge']         ?? ($transaction['cod_fee'] ?? 0));
                    $convFee      = (float) ($transaction['convenience_fee']    ?? 0);
                    $discountAmt  = (float) ($transaction['discount_amount']    ?? 0);
                    $nimbblAdded  = $codCharge + $convFee - $discountAmt;
                    $magentoTotal = (float) $order->getGrandTotal();

                    if (abs($nimbblAdded) > 0.005) {
                        $parts = [];
                        if ($codCharge  > 0)  { $parts[] = 'COD charge: ' . sprintf('%.2f', $codCharge); }
                        if ($convFee    > 0)  { $parts[] = 'Convenience fee: ' . sprintf('%.2f', $convFee); }
                        if ($discountAmt > 0) { $parts[] = 'Nimbbl discount: -' . sprintf('%.2f', $discountAmt); }
                        $order->addCommentToStatusHistory(
                            'Note (G6): Nimbbl applied additional charges/discounts (' .
                            implode(', ', $parts) . '). ' .
                            'Nimbbl settled: ' . sprintf('%.2f', $txnTotal) . '; ' .
                            'Magento order total: ' . sprintf('%.2f', $magentoTotal) . '. ' .
                            'Reconcile via credit memo or invoice adjustment if required.'
                        );
                        $this->logger->info(
                            'Nimbbl Webhook: G6 charge delta=' . sprintf('%.2f', $nimbblAdded) .
                            ' order=' . $order->getIncrementId()
                        );
                    }
                } else {
                    $order->addCommentToStatusHistory(
                        'Nimbbl Webhook: payment_success received but transaction status=' .
                        ($txnStatus ?: 'unknown') . ' (expected succeeded).'
                    );
                    $this->logger->info('Nimbbl Webhook: payment_success — unexpected txn status=' .
                        $txnStatus . ' order=' . $order->getIncrementId());
                }
                $order->save();

                // Mark the OrderLink row as webhook-fulfilled AFTER $order->save() succeeds.
                // Doing it before would leave OrderLink annotated as "Webhook" even if the
                // order state write failed, producing a misleading admin "Fulfilled Via" value.
                // markOrderLinkWebhookFulfilled() is non-fatal (wrapped in try-catch) so a
                // failure here does not roll back the order state change.
                if ($isSuccess) {
                    $this->markOrderLinkWebhookFulfilled($order->getQuoteId(), $nimbblTransactionId);
                }
                break;

            // -----------------------------------------------------------------
            case 'payment_failed':
                if (in_array($orderState, [SalesOrder::STATE_PROCESSING, SalesOrder::STATE_COMPLETE], true)) {
                    $order->addCommentToStatusHistory(
                        'Nimbbl Webhook: payment_failed received but order was already processed.'
                    );
                } else {
                    $order->setState(SalesOrder::STATE_CANCELED)->setStatus('canceled');
                    $order->addCommentToStatusHistory(
                        'Payment failed (from Nimbbl webhook).' . ($message ? ' ' . $message : '')
                    );
                    $this->logger->info('Nimbbl Webhook: payment_failed — order ' . $order->getIncrementId());
                }
                $order->save();
                break;

            // -----------------------------------------------------------------
            case 'refund_success':
                $orderTxnId    = $order->getPayment()->getLastTransId();
                $webhookTxnId  = trim($nimbblTransactionId);

                // Ignore if transaction IDs are both non-empty and don't match.
                if ($webhookTxnId !== '' && $orderTxnId !== '' && $webhookTxnId !== $orderTxnId) {
                    $this->logger->info('Nimbbl Webhook: refund_success — txn ID mismatch, ignoring. ' .
                        'order_txn=' . $orderTxnId . ' webhook_txn=' . $webhookTxnId);
                    break;
                }

                $refundId = (string) ($webhookData['refund_id'] ?? ($webhookData['refund']['refund_id'] ?? ''));
                $note     = 'Refund successful (from Nimbbl webhook).' . ($refundId ? ' Refund ID: ' . $refundId : '');
                $order->setState(SalesOrder::STATE_CLOSED)->setStatus('closed');
                $order->addCommentToStatusHistory($note);
                $order->save();
                $this->logger->info('Nimbbl Webhook: refund_success — order ' . $order->getIncrementId());
                break;

            // -----------------------------------------------------------------
            case 'refund_failed':
                $orderTxnId   = $order->getPayment()->getLastTransId();
                $webhookTxnId = trim($nimbblTransactionId);

                if ($webhookTxnId !== '' && $orderTxnId !== '' && $webhookTxnId !== $orderTxnId) {
                    $this->logger->info('Nimbbl Webhook: refund_failed — txn ID mismatch, ignoring.');
                    break;
                }

                $refundId = (string) ($webhookData['refund_id'] ?? ($webhookData['refund']['refund_id'] ?? ''));
                $note     = 'Refund failed (from Nimbbl webhook).' . ($refundId ? ' Refund ID: ' . $refundId : '');
                $order->addCommentToStatusHistory($note);
                $order->save();
                $this->logger->info('Nimbbl Webhook: refund_failed — order ' . $order->getIncrementId());
                break;

            // -----------------------------------------------------------------
            case 'payment_authorized':
                // Funds are HELD, not captured. Do NOT fulfil/ship. Mark as on-hold (pending capture).
                if (!in_array($orderState, [SalesOrder::STATE_PROCESSING, SalesOrder::STATE_COMPLETE, SalesOrder::STATE_CLOSED], true)) {
                    $order->setState(SalesOrder::STATE_PENDING_PAYMENT)->setStatus('pending_payment');
                    $order->addCommentToStatusHistory(
                        'Payment authorized — funds held, not yet captured (from Nimbbl webhook). ' .
                        'Capture or void before the authorization expires.'
                    );
                    $this->logger->info('Nimbbl Webhook: payment_authorized — order ' . $order->getIncrementId() . ' set to pending_payment.');
                } else {
                    $order->addCommentToStatusHistory(
                        'Nimbbl Webhook: payment_authorized received but order already ' . $orderState . '.'
                    );
                }
                $order->save();
                break;

            // -----------------------------------------------------------------
            default:
                // Capture/void/pending events are intentionally ignored — no status change.
                $this->logger->info('Nimbbl Webhook: unhandled event_type=' . $eventType .
                    ' order=' . $order->getIncrementId());
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Order lookup
    // -------------------------------------------------------------------------

    /**
     * Resolve a Magento order from webhook payload.
     *
     * Strategy 1 — parse quote_id from our invoice_id format:
     *   inv_nimbbl_magento_{quoteId}_{uniqid}
     *
     * Strategy 2 — look up OrderLink by nimbbl_order_id → quote_id → sales order.
     */
    protected function getOrderFromWebhookData(array $webhookData): ?SalesOrder
    {
        $invoiceId    = trim((string) ($webhookData['order']['invoice_id'] ?? ''));
        $nimbblOrderId = trim((string) ($webhookData['nimbbl_order_id'] ?? ''));

        // Strategy 1: invoice_id format = inv_nimbbl_magento_{quoteId}_{uniqid}
        if ($invoiceId !== '' && preg_match('/^inv_nimbbl_magento_(\d+)_/', $invoiceId, $m)) {
            $order = $this->getOrderByQuoteId((int) $m[1]);
            if ($order) {
                $this->debugLog('Nimbbl Webhook: resolved order via invoice_id quote_id=' . $m[1]);
                return $order;
            }
        }

        // Strategy 2: OrderLink table keyed on nimbbl_order_id
        if ($nimbblOrderId !== '') {
            $orderLinkCollection = $this->_objectManager
                ->get('Nimbbl\Magento\Model\OrderLink')
                ->getCollection()
                ->addFilter('nimbbl_order_id', $nimbblOrderId)
                ->getFirstItem();

            $orderLink = $orderLinkCollection->getData();
            if (!empty($orderLink['quote_id'])) {
                $order = $this->getOrderByQuoteId((int) $orderLink['quote_id']);
                if ($order) {
                    $this->debugLog('Nimbbl Webhook: resolved order via OrderLink nimbbl_order_id=' . $nimbblOrderId);
                    return $order;
                }
            }
        }

        return null;
    }

    protected function getOrderByQuoteId(int $quoteId): ?SalesOrder
    {
        $collection = $this->_objectManager
            ->get('Magento\Sales\Model\Order')
            ->getCollection()
            ->addFieldToSelect('entity_id')
            ->addFilter('quote_id', $quoteId)
            ->getFirstItem();

        $data = $collection->getData();
        if (!empty($data['entity_id'])) {
            return $this->orderRepository->get((int) $data['entity_id']);
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Safety-net order creation (customer closed browser before success page)
    // -------------------------------------------------------------------------

    /**
     * Create a Magento order from the still-active quote when a verified webhook
     * arrives before the customer reaches the thank-you page.
     * Only fires for actionable events: payment_success (succeeded) and payment_authorized.
     */
    protected function createOrderFromWebhook(array $webhookData): ?SalesOrder
    {
        $eventType = strtolower(trim((string) ($webhookData['event_type'] ?? '')));
        $transaction = is_array($webhookData['transaction'] ?? null) ? $webhookData['transaction'] : [];
        $txnStatus   = strtolower(trim((string) ($transaction['status'] ?? '')));

        $isSuccess   = ($eventType === 'payment_success' && in_array($txnStatus, ['success', 'succeeded'], true));
        $isPreAuth   = ($eventType === 'payment_authorized');

        if (!$isSuccess && !$isPreAuth) {
            return null;
        }

        // Resolve quote_id from invoice_id
        $invoiceId = trim((string) ($webhookData['order']['invoice_id'] ?? ''));
        $quoteId   = null;

        if ($invoiceId !== '' && preg_match('/^inv_nimbbl_magento_(\d+)_/', $invoiceId, $m)) {
            $quoteId = (int) $m[1];
        }

        if (!$quoteId) {
            $nimbblOrderId = trim((string) ($webhookData['nimbbl_order_id'] ?? ''));
            if ($nimbblOrderId !== '') {
                $orderLink = $this->_objectManager
                    ->get('Nimbbl\Magento\Model\OrderLink')
                    ->getCollection()
                    ->addFilter('nimbbl_order_id', $nimbblOrderId)
                    ->getFirstItem()
                    ->getData();
                $quoteId = !empty($orderLink['quote_id']) ? (int) $orderLink['quote_id'] : null;
            }
        }

        if (!$quoteId) {
            $this->logger->error('Nimbbl Webhook: createOrderFromWebhook — could not resolve quote_id.');
            return null;
        }

        // Check front-end processing cache (race with front-end checkout)
        if (!empty($this->cache->load('quote_Front_processing_' . $quoteId))) {
            $this->debugLog('Nimbbl Webhook: front-end processing active for quoteID=' . $quoteId . ', deferring.');
            return null;
        }

        try {
            $quote = $this->quoteRepository->get($quoteId);
        } catch (\Exception $e) {
            $this->logger->error('Nimbbl Webhook: quote not found quoteId=' . $quoteId . ': ' . $e->getMessage());
            return null;
        }

        if (!$quote->getIsActive()) {
            $this->debugLog('Nimbbl Webhook: quote inactive quoteId=' . $quoteId);
            return null;
        }

        // Set payment method and customer details on quote
        $quote = $this->prepareQuoteForSubmit($quote, $webhookData);

        // Race guard: set cache before submitting
        $this->cache->save('started', 'quote_processing_' . $quoteId, ['nimbbl'], 30);

        try {
            $order = $this->quoteManagement->submit($quote);
        } catch (\Exception $e) {
            $this->logger->error('Nimbbl Webhook: quoteManagement->submit failed quoteId=' . $quoteId . ': ' . $e->getMessage());
            return null;
        }

        $quote->setIsActive(false)->save();
        $this->logger->info('Nimbbl Webhook: created order ' . $order->getIncrementId() . ' from quote ' . $quoteId);
        return $order;
    }

    protected function prepareQuoteForSubmit($quote, array $webhookData)
    {
        $quote->getPayment()->setMethod(PaymentMethod::METHOD_CODE);

        $firstName = $quote->getBillingAddress()->getFirstname() ?? '';
        $lastName  = $quote->getBillingAddress()->getLastname() ?? '';
        $email     = $quote->getBillingAddress()->getEmail()
            ?? ($webhookData['user']['email'] ?? '');

        $store = $quote->getStore() ?: $this->storeManagement->getStore();
        $quote->setStore($store);

        $customer = $this->_objectManager
            ->create('Magento\Customer\Model\Customer')
            ->setWebsiteId($store->getWebsiteId())
            ->loadByEmail($email);

        if (empty($customer->getEntityId()) || empty($quote->getBillingAddress()->getCustomerId())) {
            $quote->setCustomerFirstname($firstName)
                  ->setCustomerLastname($lastName)
                  ->setCustomerEmail($email)
                  ->setCustomerIsGuest(true);
        }

        $quote->collectTotals()->save();
        return $quote;
    }

    // -------------------------------------------------------------------------
    // Signature verification fallback (mirrors WooCommerce verify_nimbbl_payment_signature)
    // -------------------------------------------------------------------------

    /**
     * Fallback HMAC-SHA256 verification used when the full Nimbbl SDK is not installed.
     * Supports signature_version v3 and legacy v2 formats.
     *
     * @param array  $data          Decoded webhook payload
     * @param string $webhookSecret The webhook_secret from admin config (NOT the API key_secret)
     */
    protected function verifyNimbblPaymentSignature(array $data, string $webhookSecret): bool
    {
        $nimbblTransactionId = (string) ($data['nimbbl_transaction_id'] ?? '');
        $nimbblSignature     = (string) ($data['transaction']['signature'] ?? '');
        $signatureVersion    = (string) ($data['transaction']['signature_version'] ?? '');
        $keySecret           = $webhookSecret;

        if (empty($signatureVersion) || empty($nimbblSignature)) {
            $this->logger->info('Nimbbl Webhook: signature_version or signature missing — cannot verify.');
            return false;
        }

        if ($signatureVersion === 'v3') {
            $amount = $this->formatAmount($data['transaction']['transaction_amount'] ?? 0);
            $signatureString = implode('|', [
                $data['order']['invoice_id']                 ?? '',
                $nimbblTransactionId,
                $amount,
                $data['transaction']['transaction_currency'] ?? '',
                $data['transaction']['status']               ?? '',
                $data['transaction']['transaction_type']     ?? '',
            ]);
        } else {
            // v2 legacy
            $amount          = sprintf('%.2f', (float) ($data['transaction']['transaction_amount'] ?? 0));
            $currency        = (string) ($data['transaction']['transaction_currency'] ?? '');
            $invoiceId       = (string) ($data['order']['invoice_id'] ?? '');
            $signatureString = $invoiceId . '|' . $nimbblTransactionId . '|' . $amount . '|' . $currency;
        }

        // PHP 8.2+ deprecates utf8_encode(); use mb_convert_encoding as drop-in replacement.
        $toUtf8 = static function (string $value): string {
            return function_exists('mb_convert_encoding')
                ? mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1')
                : $value;
        };

        $generated = hash_hmac('sha256', $toUtf8($signatureString), $toUtf8($keySecret));

        if ($generated === $nimbblSignature) {
            $this->debugLog('Nimbbl Webhook: HMAC signature verified OK (version=' . $signatureVersion . ').');
            return true;
        }

        $this->logger->error('Nimbbl Webhook: HMAC signature mismatch (version=' . $signatureVersion . ').');
        return false;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Normalise amount to exactly 2 decimal places — mirrors WooCommerce format_amount().
     */
    protected function formatAmount($amount): string
    {
        $inp   = str_replace(',', '', (string) $amount);
        $parts = explode('.', $inp);

        if (count($parts) === 1) {
            return $parts[0] . '.00';
        }

        return $parts[0] . '.' . str_pad(substr($parts[1], 0, 2), 2, '0');
    }

    protected function getRawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    protected function sendResponse(int $httpCode)
    {
        $response = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $response->setHttpResponseCode($httpCode);
        $response->setContents('');
        return $response;
    }

    /**
     * Set by_webhook = true and record the Nimbbl transaction ID on the OrderLink row
     * for the given quote. Called after a verified payment_success webhook is processed.
     *
     * This is what powers the admin "Fulfilled Via: Webhook" display in NimbblInfo.
     * authorize() (frontend path) always passes false to updatePaymentNote(), so
     * by_webhook can only be set here.
     */
    private function markOrderLinkWebhookFulfilled(string $quoteId, string $nimbblTransactionId): void
    {
        if (empty($quoteId)) {
            return;
        }

        try {
            $orderLink = $this->_objectManager
                ->get('Nimbbl\Magento\Model\OrderLink')
                ->getCollection()
                ->addFilter('quote_id', $quoteId)
                ->getFirstItem();

            if ($orderLink && $orderLink->getId()) {
                $orderLink->setByWebhook(true);
                if (!empty($nimbblTransactionId)) {
                    $orderLink->setNimbblPaymentId($nimbblTransactionId);
                }
                $orderLink->save();
                $this->debugLog('Nimbbl Webhook: OrderLink marked by_webhook for quote=' . $quoteId);
            }
        } catch (\Exception $e) {
            // Non-fatal: the payment was processed successfully; only the annotation failed.
            $this->logger->warning(
                'Nimbbl Webhook: could not update OrderLink by_webhook — ' . $e->getMessage()
            );
        }
    }

    /**
     * Build currency-conversion notes for an order's status history.
     *
     * P4: Mirrors WooCommerce build_nimbbl_currency_conversion_notes() — reads the
     * currency_conversion sub-objects from both the transaction and order blocks, including
     * exchange_rate and original_total_amount. Falls back to the simpler G7 transaction_currency
     * vs order currency code comparison when no currency_conversion block is present.
     *
     * @param  array $transaction  The 'transaction' block from webhookData.
     * @param  array $webhookData  Full decoded webhook payload.
     * @return string  Human-readable note(s), or '' when no conversion is detected.
     */
    private function buildCurrencyConversionNotes(array $transaction, array $webhookData): string
    {
        $notes = [];

        $txnConv   = is_array($transaction['currency_conversion']          ?? null) ? $transaction['currency_conversion']          : [];
        $orderConv = is_array($webhookData['order']['currency_conversion'] ?? null) ? $webhookData['order']['currency_conversion'] : [];

        // Transaction-level currency conversion block
        if (!empty($txnConv)) {
            $origCurr = strtoupper(trim((string) ($txnConv['original_currency']    ?? '')));
            $convCurr = strtoupper(trim((string) ($txnConv['converted_currency']   ?? '')));
            $rate     = (float)                  ($txnConv['exchange_rate']         ?? 0);
            $origAmt  = (float)                  ($txnConv['original_total_amount'] ?? 0);

            if ($origCurr !== '' && $convCurr !== '' && $origCurr !== $convCurr) {
                $notes[] = sprintf(
                    'Transaction currency conversion: %s %.2f → %s (exchange rate: %.6f).',
                    $origCurr, $origAmt, $convCurr, $rate
                );
            }
        }

        // Order-level currency conversion block
        if (!empty($orderConv)) {
            $origCurr = strtoupper(trim((string) ($orderConv['original_currency']    ?? '')));
            $convCurr = strtoupper(trim((string) ($orderConv['converted_currency']   ?? '')));
            $rate     = (float)                  ($orderConv['exchange_rate']         ?? 0);
            $origAmt  = (float)                  ($orderConv['original_total_amount'] ?? 0);

            if ($origCurr !== '' && $convCurr !== '' && $origCurr !== $convCurr) {
                $notes[] = sprintf(
                    'Order currency conversion: %s %.2f → %s (exchange rate: %.6f).',
                    $origCurr, $origAmt, $convCurr, $rate
                );
            }
        }

        // Fallback (G7): simple transaction_currency vs order currency when no conversion block present.
        if (empty($notes)) {
            $txnCurrency = strtoupper(trim((string) ($transaction['transaction_currency'] ?? '')));
            $ordCurrency = strtoupper(trim((string) ($webhookData['order']['currency']    ?? '')));

            if ($txnCurrency !== '' && $ordCurrency !== '' && $txnCurrency !== $ordCurrency) {
                $txnAmount = sprintf('%.2f', (float) ($transaction['transaction_amount'] ?? 0));
                $notes[]   = 'Note: Nimbbl settled ' . $txnCurrency . ' ' . $txnAmount .
                             ' but order currency is ' . $ordCurrency .
                             '. Review the exchange rate and adjust order totals if required.';
            }
        }

        return implode(' ', $notes);
    }

    /**
     * Returns true when the payment mode string represents Cash on Delivery.
     *
     * COD orders must stay in STATE_PROCESSING (cash has not yet been collected);
     * advancing them to STATE_COMPLETE would falsely imply fulfilment.
     * Mirrors WooCommerce's is_cod_payment_mode() helper.
     */
    private function isCodPaymentMode(string $mode): bool
    {
        return strtolower(trim($mode)) === 'cash on delivery';
    }

    /**
     * Write a debug message only when Debug Logging is enabled in admin config.
     *
     * Use this for implementation-detail messages (which verification path was taken,
     * which order-lookup strategy matched, etc.) that produce noise in production
     * but are invaluable during integration testing.
     */
    private function debugLog(string $message): void
    {
        if ($this->config->isDebugEnabled()) {
            $this->logger->debug($message);
        }
    }
}
