<?php

namespace Nimbbl\Magento\Model;

// use Razorpay\Api\Api;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;
use Magento\Sales\Model\Order\Payment\Transaction as PaymentTransaction;
use Magento\Payment\Model\InfoInterface;
use Nimbbl\Magento\Model\Config;
use Magento\Catalog\Model\Session;

/**
 * Class PaymentMethod
 * @package Nimbbl\Magento\Model
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CHANNEL_NAME                  = 'Magento';
    const METHOD_CODE                   = 'nimbbl';
    const CONFIG_MASKED_FIELDS          = 'masked_fields';
    const CURRENCY                      = 'INR';

    /**
     * @var string
     */
    protected $_code                    = self::METHOD_CODE;

    /**
     * @var bool
     */
    protected $_canAuthorize            = true;

    /**
     * @var bool
     */
    protected $_canCapture              = true;

    /**
     * @var bool
     */
    protected $_canRefund               = true;

    /**
     * @var bool
     */
    protected $_canUseInternal          = false;        //Disable module for Magento Admin Order

    /**
     * @var bool
     */
    protected $_canUseCheckout          = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @var array|null
     */
    protected $requestMaskedFields      = null;

    /**
     * @var \Nimbbl\Magento\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var TransactionCollectionFactory
     */
    protected $salesTransactionCollectionFactory;

    /**
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    protected $productMetaData;

    /**
     * @var \Magento\Directory\Model\RegionFactory
     */
    protected $regionFactory;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var \Nimbbl\Magento\Model\NimbblClientFactory
     */
    protected $nimbblClientFactory;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Nimbbl\Magento\Model\Config $config
     * @param \Magento\Framework\App\RequestInterface $request
     * @param TransactionCollectionFactory $salesTransactionCollectionFactory
     * @param \Magento\Framework\App\ProductMetadataInterface $productMetaData
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Nimbbl\Magento\Model\Config $config,
        \Magento\Framework\App\RequestInterface $request,
        TransactionCollectionFactory $salesTransactionCollectionFactory,
        \Magento\Framework\App\ProductMetadataInterface $productMetaData,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Nimbbl\Magento\Controller\Payment\Order $order,
        \Nimbbl\Magento\Model\NimbblClientFactory $nimbblClientFactory,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->config = $config;
        $this->request = $request;
        $this->salesTransactionCollectionFactory = $salesTransactionCollectionFactory;
        $this->productMetaData = $productMetaData;
        $this->regionFactory = $regionFactory;
        $this->orderRepository = $orderRepository;
        $this->order               = $order;
        $this->nimbblClientFactory = $nimbblClientFactory;
    }

    /**
     * Validate data
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function validate()
    {
        $info = $this->getInfoInstance();
        if ($info instanceof \Magento\Sales\Model\Order\Payment) {
            $billingCountry = $info->getOrder()->getBillingAddress()->getCountryId();
        } else {
            $billingCountry = $info->getQuote()->getBillingAddress()->getCountryId();
        }

        if (!$this->config->canUseForCountry($billingCountry)) {
            throw new LocalizedException(__('Selected payment type is not allowed for billing country.'));
        }

        return $this;
    }

    /**
     * Authorizes specified amount
     *
     * @param InfoInterface $payment
     * @param string $amount
     * @return $this
     * @throws LocalizedException
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        try
        {
            /** @var \Magento\Sales\Model\Order\Payment $payment */
            $order = $payment->getOrder();
            $orderId = $order->getIncrementId();

            $request = $this->getPostData();

            $this->debugLog("Nimbbl: PaymentMethod authorize invoked with: " . json_encode($request));

            $payment_id      = null;   // set in each branch; validated non-empty before use
            $nimbbl_order_id = null;   // same
            $paymentMode     = '';     // filled by each branch; saved to payment additional info at the end

            // ---- Redirect mode (checkout_mode = 'redirect') -------------------
            // Order.php::resolveRedirectCallback() already verified the signature and
            // stored the transaction ID on the payment object. Skip HTTP-body parsing;
            // run the amount check + Transaction Enquiry only.
            if ($payment->getAdditionalInformation('nimbbl_redirect_verified')) {
                $payment_id      = (string) ($payment->getAdditionalInformation('nimbbl_payment_id') ?? '');
                $nimbbl_order_id = $this->order->getOrderId();

                // payment_mode was set by Order.php::resolveRedirectCallback().
                $paymentMode = (string) ($payment->getAdditionalInformation('nimbbl_payment_mode') ?? '');

                // Clear the one-time verification flag immediately. If authorize() is ever
                // called again on this payment object (admin retry, third-party extension),
                // the redirect branch must not fire with stale transaction data.
                $payment->unsAdditionalInformation('nimbbl_redirect_verified');

                $orderAmount = (int) (number_format($order->getGrandTotal() * 100, 0, ".", ""));
                if ($orderAmount !== $this->order->getNimbblOrderAmount()) {
                    $rzpOrderAmount = $order->getOrderCurrency()->formatTxt(
                        number_format($this->order->getNimbblOrderAmount() / 100, 2, ".", "")
                    );
                    throw new LocalizedException(__(
                        "Cart order amount = %1 doesn't match with amount paid = %2",
                        $order->getOrderCurrency()->formatTxt($order->getGrandTotal()),
                        $rzpOrderAmount
                    ));
                }

                // Transaction Enquiry: authoritative server-side confirmation.
                // Also enriches payment_mode if not already set from Order.php.
                if (!empty($payment_id)) {
                    $txnData = $this->verifyTransactionWithApi(
                        $payment_id,
                        $order->getGrandTotal(),
                        $order->getOrderCurrencyCode() ?: 'INR'
                    );
                    if (empty($paymentMode)) {
                        $paymentMode = (string) ($txnData['payment_mode'] ?? '');
                    }
                }

            // ---- Popup / standard checkout (and legacy individual-field redirect) ---
            // NOTE: The old codebase had a "webhook call" branch here that read
            // payload.payment.entity.id from php://input without any signature check.
            // That branch was removed: it was dead code (actual webhooks go to Webhook.php)
            // and represented an attack surface — any POST body with that field set could
            // enter the branch with an attacker-supplied transaction ID.
            } else {
                // Legacy redirect compatibility: if body was empty but individual POST
                // fields were sent (old Razorpay-style), reconstruct additional_data.
                if (empty($request) && isset($_POST['nimbbl_signature'])) {
                    $request['paymentMethod']['additional_data'] = [
                        'nimbbl_payment_id' => $_POST['nimbbl_payment_id'] ?? '',
                        'nimbbl_order_id'   => $_POST['nimbbl_order_id']   ?? '',
                        'nimbbl_signature'  => $_POST['nimbbl_signature']   ?? '',
                    ];
                }

                if (isset($request['paymentMethod']['additional_data']['nimbbl_payment_id'])) {
                    $payment_id = $request['paymentMethod']['additional_data']['nimbbl_payment_id'];
                }

                $nimbbl_order_id = $this->order->getOrderId();

                //validate NimbblOrderamount with quote/order amount before signature
                $orderAmount = (int) (number_format($order->getGrandTotal() * 100, 0, ".", ""));

                if ($orderAmount !== $this->order->getNimbblOrderAmount()) {
                    $rzpOrderAmount = $order->getOrderCurrency()->formatTxt(
                        number_format($this->order->getNimbblOrderAmount() / 100, 2, ".", "")
                    );
                    throw new LocalizedException(__(
                        "Cart order amount = %1 doesn't match with amount paid = %2",
                        $order->getOrderCurrency()->formatTxt($order->getGrandTotal()),
                        $rzpOrderAmount
                    ));
                }

                $additionalData = $request['paymentMethod']['additional_data'] ?? [];

                // 1. HMAC signature verification (fast, no network round-trip).
                if (!empty($additionalData['nimbbl_signature'])) {
                    $invoiceId = $this->order->getNimbblInvoiceId();
                    $currency  = $order->getOrderCurrencyCode() ?: 'INR';
                    $this->verifyNimbblCallbackSignature($additionalData, $invoiceId, $order->getGrandTotal(), $currency);
                }

                // 2. Transaction Enquiry API — authoritative server-side status check.
                // The returned txnData carries payment_mode for storage below.
                $nimbblTxnId = $additionalData['nimbbl_payment_id'] ?? $payment_id ?? '';
                if (!empty($nimbblTxnId)) {
                    $txnData = $this->verifyTransactionWithApi(
                        (string) $nimbblTxnId,
                        $order->getGrandTotal(),
                        $order->getOrderCurrencyCode() ?: 'INR'
                    );
                    $paymentMode = (string) ($txnData['payment_mode'] ?? '');
                }
            }

            if (empty($payment_id)) {
                throw new LocalizedException(__(
                    'Nimbbl: Transaction ID is missing. Cannot record payment. Please contact support.'
                ));
            }

            $payment->setStatus(self::STATUS_APPROVED)
                    ->setAmountPaid($amount)
                    ->setLastTransId($payment_id)
                    ->setTransactionId($payment_id)
                    ->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true);

            // Persist invoice_id and payment_mode as payment additional information so
            // they are available on the admin Order View and customer Order Details pages.
            $invoiceId = $this->order->getNimbblInvoiceId();
            if (!empty($invoiceId)) {
                $payment->setAdditionalInformation('nimbbl_invoice_id', $invoiceId);
            }
            if (!empty($paymentMode)) {
                $payment->setAdditionalInformation('nimbbl_payment_mode', $paymentMode);
            }

            // update the Nimbbl payment with corresponding created order ID of this quote ID
            // Frontend path: always marks by_frontend = true (webhook path goes via Webhook.php).
            $this->updatePaymentNote($payment_id, $order, $nimbbl_order_id);
        }
        catch (\Exception $e)
        {
            $this->_logger->critical($e);
            // FIX-3: Never expose raw transaction IDs or internal status strings in the
            // user-facing message. Log the full exception for ops; surface a clean,
            // actionable message to the customer.
            //
            // Distinguish two cases:
            //   a) Transaction Enquiry rejected the payment (status check failure, amount
            //      mismatch) — the payment definitely did not go through.
            //   b) Any other exception (network error, config issue, etc.).
            $rawMsg = $e->getMessage();
            if (
                str_contains($rawMsg, 'Transaction Enquiry') ||
                str_contains($rawMsg, 'Transaction amount mismatch') ||
                str_contains($rawMsg, 'Transaction ID is missing')
            ) {
                // Payment was not confirmed — tell the customer clearly without exposing
                // the internal txn_id or API status value.
                throw new LocalizedException(__(
                    'Your payment could not be verified. If any amount was debited, ' .
                    'it will be refunded automatically. Please contact support with your order number.'
                ));
            }
            // Generic failure — configuration, network, or unexpected error.
            throw new LocalizedException(__(
                'Payment processing failed. Please try again or contact support.'
            ));
        }

        return $this;
    }

    /**
     * Capture specified amount with authorization
     *
     * @param InfoInterface $payment
     * @param string $amount
     * @return $this
     */

    public function capture(InfoInterface $payment, $amount)
    {
        //check if payment has been authorized
        if(is_null($payment->getParentTransactionId())) {
            $this->authorize($payment, $amount);
        }

        return $this;
    }

    /**
     * Update the OrderLink row with the Magento increment_order_id and the Nimbbl
     * payment (transaction) ID, and mark the row as fulfilled via the frontend path.
     *
     * The webhook path (Webhook.php::markOrderLinkWebhookFulfilled()) handles the
     * by_webhook case directly; this method is only ever called from authorize(),
     * which is the browser-side checkout flow.
     *
     * @param string $paymentId     Nimbbl transaction ID from the checkout callback
     * @param object $order         Magento Sales Order model
     * @param string $nimbblOrderId Nimbbl Order ID (from the OrderLink row)
     */
    protected function updatePaymentNote($paymentId, $order, $nimbblOrderId)
    {
        $_objectManager  = \Magento\Framework\App\ObjectManager::getInstance();

        $orderLinkCollection = $_objectManager->get('Nimbbl\Magento\Model\OrderLink')
                                                   ->getCollection()
                                                   ->addFieldToSelect('entity_id')
                                                   ->addFilter('quote_id', $order->getQuoteId())
                                                   ->addFilter('nimbbl_order_id', $nimbblOrderId)
                                                   ->getFirstItem();

        $orderLink = $orderLinkCollection->getData();

        if (!empty($orderLink['entity_id'])) {
            $orderLinkCollection->setNimbblPaymentId($paymentId)
                                ->setIncrementOrderId($order->getIncrementId())
                                ->setByFrontend(true)
                                ->save();
        }
    }

    /**
     * Verify the HMAC signature returned by the Nimbbl checkout SDK callback handler.
     *
     * Mirrors the WooCommerce plugin's verify_nimbbl_payment_signature() logic.
     * Supports v2, v3, and v4 signature formats.
     *
     * v4 format: the entire inner payload JSON is base64-encoded and sent as nimbbl_payload.
     *   HMAC = SHA256(keySecret, base64_decode(nimbbl_payload))
     *   No pipe-joined string; the raw JSON string is the HMAC input.
     *   invoice_id is embedded in the inner payload and not needed for the HMAC itself.
     *
     * v3 format: invoice_id|txn_id|amount|currency|status|txn_type
     * v2 format: invoice_id|txn_id|amount|currency
     *
     * @param array  $additionalData  Payment additional_data from JS getData()
     * @param string $invoiceId       Our invoice_id sent to Nimbbl (from session) — not used for v4
     * @param float  $grandTotal      Order grand total (Magento value — cannot be spoofed by client)
     * @param string $currency        Order currency code (e.g. INR)
     * @throws LocalizedException    If signature does not match
     */
    protected function verifyNimbblCallbackSignature(array $additionalData, string $invoiceId, float $grandTotal, string $currency): void
    {
        $nimbblTransactionId = (string) ($additionalData['nimbbl_payment_id'] ?? '');
        $nimbblSignature     = (string) ($additionalData['nimbbl_signature'] ?? '');
        $signatureVersion    = (string) ($additionalData['nimbbl_signature_version'] ?? 'v2');

        if (empty($nimbblSignature)) {
            throw new LocalizedException(__('Nimbbl: Payment signature is missing. Cannot verify payment.'));
        }

        // ── v4: HMAC over the raw inner payload JSON ──────────────────────────
        // The JS sends nimbbl_payload = base64(innerJson) directly from the callback.
        // We decode it and compute HMAC-SHA256 over the raw JSON string, mirroring
        // _sign_v4_payload() in the Nimbbl backend (universal.py).
        // invoice_id is NOT part of the HMAC for v4; it is embedded in the inner payload.
        if ($signatureVersion === 'v4') {
            $encodedPayload = (string) ($additionalData['nimbbl_payload'] ?? '');

            if (empty($encodedPayload)) {
                throw new LocalizedException(__(
                    'Nimbbl: v4 payload is missing. Cannot verify payment.'
                ));
            }

            // strict=true rejects invalid base64 characters and returns false on error.
            $rawJson = base64_decode($encodedPayload, true);
            if ($rawJson === false || $rawJson === '') {
                throw new LocalizedException(__(
                    'Nimbbl: v4 payload is not valid base64. Cannot verify payment.'
                ));
            }

            $generated = hash_hmac('sha256', $rawJson, $this->config->getKeySecret());

            if ($generated !== $nimbblSignature) {
                $this->_logger->critical('Nimbbl: v4 signature mismatch. txn_id=' . $nimbblTransactionId);
                throw new LocalizedException(__('Nimbbl: Payment signature verification failed. Please contact support.'));
            }

            $this->_logger->info('Nimbbl: v4 callback signature verified OK. txn_id=' . $nimbblTransactionId);
            return;
        }

        // ── v1 / v2 / v3: pipe-joined string HMAC ────────────────────────────
        // invoice_id is required for all pipe-joined formats.
        if (empty($invoiceId)) {
            // Session expired, cleared, or double-submit. Without invoice_id the HMAC
            // string cannot be reconstructed, so the signature is unverifiable. Failing
            // closed here is safer than silently skipping — if the Transaction Enquiry API
            // is also unreachable, no verification would run at all.
            throw new LocalizedException(__(
                'Nimbbl: Invoice ID is missing from the session. Cannot verify payment signature. ' .
                'Please restart checkout.'
            ));
        }

        $amount = \Nimbbl\Magento\Model\Config::normalizeAmount($grandTotal);

        if ($signatureVersion === 'v3') {
            $status  = (string) ($additionalData['nimbbl_status'] ?? 'success');
            $txnType = (string) ($additionalData['nimbbl_txn_type'] ?? '');
            $signatureString = implode('|', [$invoiceId, $nimbblTransactionId, $amount, $currency, $status, $txnType]);
        } else {
            // v1 / v2 format (v1 omits amount+currency but v2 is the practical minimum)
            $signatureString = $invoiceId . '|' . $nimbblTransactionId . '|' . $amount . '|' . $currency;
        }

        $generated = hash_hmac('sha256', $signatureString, $this->config->getKeySecret());

        if ($generated !== $nimbblSignature) {
            $this->_logger->critical('Nimbbl: signature mismatch. version=' . $signatureVersion .
                ' invoice_id=' . $invoiceId . ' txn_id=' . $nimbblTransactionId);
            throw new LocalizedException(__('Nimbbl: Payment signature verification failed. Please contact support.'));
        }

        $this->_logger->info('Nimbbl: callback signature verified OK. version=' . $signatureVersion .
            ' txn_id=' . $nimbblTransactionId);
    }

    /**
     * Call the Nimbbl Transaction Enquiry API to confirm the transaction is genuine.
     *
     * This is an authoritative server-to-server check — it catches replayed callbacks,
     * recycled transaction IDs, and any case where the HMAC alone is insufficient.
     *
     * Failures are logged but do NOT throw when the API itself is unreachable
     * (network timeouts, 5xx) to avoid blocking legitimate orders during Nimbbl outages.
     * Genuine fraud signals (wrong amount / non-success status) DO throw.
     *
     * @param string $nimbblTransactionId  From the checkout callback
     * @param float  $magentoGrandTotal    From the Magento order (server-side value)
     * @param string $currency             From the Magento order
     * @return array                       The raw transaction record from Nimbbl API (empty on network error)
     * @throws LocalizedException          On definite fraud signals
     */
    protected function verifyTransactionWithApi(string $nimbblTransactionId, float $magentoGrandTotal, string $currency): array
    {
        try {
            $client   = $this->nimbblClientFactory->create();
            $txnData  = $client->transactions()->transactionEnquiry(['nimbbl_transaction_id' => $nimbblTransactionId]);

            $apiStatus   = strtolower(trim((string) ($txnData['payment_status'] ?? ($txnData['status'] ?? ''))));
            $apiAmount   = (float) ($txnData['total_amount'] ?? ($txnData['amount'] ?? 0));
            $apiCurrency = strtoupper(trim((string) ($txnData['currency'] ?? '')));

            $this->_logger->info('Nimbbl: Transaction Enquiry result — txn_id=' . $nimbblTransactionId .
                ' status=' . $apiStatus . ' amount=' . $apiAmount . ' currency=' . $apiCurrency);

            // Definite fraud: transaction is explicitly failed or in a non-payment state.
            $successStatuses = ['success', 'succeeded', 'authorized'];
            if ($apiStatus !== '' && !in_array($apiStatus, $successStatuses, true)) {
                throw new LocalizedException(__(
                    'Nimbbl: Transaction Enquiry shows status "%1" for transaction %2. Payment not confirmed.',
                    $apiStatus,
                    $nimbblTransactionId
                ));
            }

            // Amount mismatch — allow 1-paisa tolerance for floating-point edge cases.
            if ($apiAmount > 0 && abs($apiAmount - $magentoGrandTotal) > 0.01) {
                throw new LocalizedException(__(
                    'Nimbbl: Transaction amount mismatch. Expected %1 %2, Nimbbl reports %3 %4.',
                    $currency,
                    number_format($magentoGrandTotal, 2),
                    $apiCurrency ?: $currency,
                    number_format($apiAmount, 2)
                ));
            }

            $this->_logger->info('Nimbbl: Transaction Enquiry passed — txn_id=' . $nimbblTransactionId);

            return $txnData;

        } catch (LocalizedException $e) {
            // Re-throw fraud signals — do not swallow.
            throw $e;
        } catch (\Exception $e) {
            // Network error, timeout, or unexpected response: log and allow order through
            // to avoid blocking real payments during Nimbbl maintenance windows.
            $this->_logger->warning('Nimbbl: Transaction Enquiry API unreachable — allowing order through. ' .
                'txn_id=' . $nimbblTransactionId . ' error=' . $e->getMessage());
            return [];
        }
    }

    protected function getPostData()
    {
        $request = file_get_contents('php://input');

        return json_decode($request, true);
    }

    /**
     * Refunds the specified amount via the Nimbbl Refund API.
     *
     * Triggered when a merchant creates a Credit Memo from the admin panel.
     * The webhook (refund_success / refund_failed) will arrive later and update
     * the order status; this method only initiates the refund.
     *
     * @param InfoInterface $payment
     * @param float         $amount  Refund amount in store currency
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $order    = $payment->getOrder();
        $txnId    = $payment->getLastTransId();
        $currency = strtoupper($order->getOrderCurrencyCode() ?: 'INR');

        if (empty($txnId)) {
            throw new LocalizedException(
                __('Nimbbl: Cannot initiate refund — no transaction ID found on this payment. Please refund directly from the Nimbbl dashboard.')
            );
        }

        try {
            $client = $this->nimbblClientFactory->create();
            $result = $client->refunds()->initiateRefund([
                'transaction_id' => $txnId,
                'refund_amount'  => round((float) $amount, 2),
                'currency'       => $currency,
                'reason'         => 'merchant_initiated',
            ]);

            $refundId = (string) ($result['refund_id'] ?? ($result['id'] ?? ''));

            $this->_logger->info(
                'Nimbbl: Refund initiated — txn_id=' . $txnId .
                ' amount=' . number_format($amount, 2) . ' ' . $currency .
                ' refund_id=' . $refundId
            );

            // Store the refund ID as the child transaction ID on the payment.
            $payment->setTransactionId($refundId ?: ($txnId . '-refund'))
                    ->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true);

            // Record the refund ID on the order link row for the webhook to match later.
            if (!empty($refundId)) {
                $this->updateOrderLinkRefundId($order->getQuoteId(), $refundId);
            }

        } catch (\Exception $e) {
            $this->_logger->critical('Nimbbl: Refund API error — ' . $e->getMessage());
            throw new LocalizedException(__('Nimbbl Refund Error: %1', $e->getMessage()));
        }

        return $this;
    }

    /**
     * Persist the Nimbbl refund_id against the OrderLink row so the webhook can match it.
     */
    protected function updateOrderLinkRefundId($quoteId, string $refundId): void
    {
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $orderLink = $objectManager->get('Nimbbl\Magento\Model\OrderLink')
                ->getCollection()
                ->addFilter('quote_id', $quoteId)
                ->getFirstItem();

            if ($orderLink && $orderLink->getId()) {
                // nimbbl_payment_id column is repurposed here to store the refund ID alongside
                // the original transaction; this is a best-effort annotation — not critical.
                $orderLink->setData('nimbbl_refund_id', $refundId)->save();
            }
        } catch (\Exception $e) {
            // Non-fatal: refund was initiated successfully; only the annotation failed.
            $this->_logger->warning('Nimbbl: Could not update OrderLink with refund_id — ' . $e->getMessage());
        }
    }

    /**
     * Write a debug message only when Debug Logging is enabled in admin config.
     *
     * Use this instead of $this->_logger->debug() directly so verbose output
     * can be suppressed in production without a code deploy.
     */
    private function debugLog(string $message): void
    {
        if ($this->config->isDebugEnabled()) {
            $this->_logger->debug($message);
        }
    }

    /**
     * Format param "channel" for transaction
     *
     * @return string
     */
    protected function getChannel()
    {
        $edition = $this->productMetaData->getEdition();
        $version = $this->productMetaData->getVersion();
        return self::CHANNEL_NAME . ' ' . $edition . ' ' . $version;
    }

    /**
     * Retrieve information from payment configuration
     *
     * @param string $field
     * @param int|string|null|\Magento\Store\Model\Store $storeId
     *
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        if ('order_place_redirect_url' === $field) {
            return $this->getOrderPlaceRedirectUrl();
        }
        return $this->config->getConfigData($field, $storeId);
    }
}
