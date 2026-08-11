<?php

namespace Nimbbl\Magento\Controller\Payment;

use Nimbbl\Magento\Model\PaymentMethod;
use Nimbbl\Magento\Model\NimbblClientFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

/**
 * Implements CsrfAwareActionInterface so that Magento 2.4.x's CsrfValidator
 * plugin does not block the redirect-mode POST-back that arrives from Nimbbl's
 * server (which has no Magento form key).
 *
 * validateForCsrf() returns true ONLY for the two Nimbbl redirect-callback
 * patterns; all other POST paths (order creation, order_check) fall through
 * to normal Magento form-key validation.
 */
class Order extends \Nimbbl\Magento\Controller\BaseController
    implements CsrfAwareActionInterface
{
    /**
     * {@inheritdoc}
     * Returning null means Magento will use its default exception.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * {@inheritdoc}
     *
     * Skip CSRF only for the two redirect-callback detection patterns used in
     * execute(). All other requests — including the browser-originated AJAX
     * calls for order creation and order_check — return null so that Magento's
     * default form-key validation still applies.
     *
     * @return bool|null  true = bypass CSRF; null = use default validation
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Pattern 1: Nimbbl posts a 'response' field containing a base64 payload.
        if (!empty($_POST['response']) && is_string($_POST['response'])) {
            return true;
        }

        // Pattern 2: raw JSON redirect-callback body sent directly by Nimbbl's server.
        // Guards: (a) no browser-originated POST fields are present; (b) the body is a
        // JSON object ({...}) containing "nimbbl_transaction_id", which is always present
        // in a genuine Nimbbl redirect-callback payload. This keeps the bypass narrow so
        // that scanners or SSRF probes that happen to omit those form fields cannot match.
        if (empty($_POST['email'])
            && empty($_POST['order_check'])
            && empty($_POST['nimbbl_payment_id'])
            && empty($_POST['nimbbl_transaction_id'])
            && empty($_POST['error'])
        ) {
            $rawBody = (string) file_get_contents('php://input');
            if ($rawBody !== ''
                && $rawBody[0] === '{'
                && strpos($rawBody, 'nimbbl_transaction_id') !== false
            ) {
                return true;
            }
        }

        // Fall through to Magento's default form-key check for all other paths.
        return null;
    }

    protected $quote;

    protected $checkoutSession;

    protected $cartManagement;

    protected $cache;

    protected $orderRepository;

    protected $logger;

    /**
     * @var NimbblClientFactory
     */
    protected $nimbblClientFactory;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Nimbbl\Magento\Model\Config $config
     * @param \Magento\Quote\Api\CartManagementInterface $cartManagement
     * @param \Nimbbl\Magento\Model\CheckoutFactory $checkoutFactory
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Psr\Log\LoggerInterface $logger
     * @param NimbblClientFactory $nimbblClientFactory
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Nimbbl\Magento\Model\Config $config,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Nimbbl\Magento\Model\CheckoutFactory $checkoutFactory,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Psr\Log\LoggerInterface $logger,
        NimbblClientFactory $nimbblClientFactory
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->config                = $config;
        $this->cartManagement        = $cartManagement;
        $this->customerSession       = $customerSession;
        $this->checkoutFactory       = $checkoutFactory;
        $this->cache                 = $cache;
        $this->orderRepository       = $orderRepository;
        $this->logger                = $logger;
        $this->nimbblClientFactory   = $nimbblClientFactory;
    }

    public function execute()
    {
        $this->debugLog("Nimbbl: Order creation controller endpoint invoked.");
        $receipt_id = $this->getQuote()->getId();

        if (empty($_POST['error']) === false) {
            // G5: show Nimbbl's actual error text, not just a generic "Payment Failed".
            // Strip HTML tags and truncate to prevent injection; fall back when empty.
            $rawError = is_string($_POST['error']) ? $_POST['error'] : '';
            $safeMsg  = htmlspecialchars(strip_tags($rawError), ENT_QUOTES, 'UTF-8');
            $safeMsg  = $safeMsg !== '' ? $safeMsg : (string) __('Payment could not be completed. Please try again.');
            $this->messageManager->addError($safeMsg);
            return $this->_redirect('checkout/cart');
        }

        if (isset($_POST['order_check']))
        {
            $this->debugLog("Nimbbl: Initiating order_check.");
            if (empty($this->cache->load("quote_processing_".$receipt_id)) === false)
            {
                $responseContent = [
                'success'   => true,
                'order_id'  => false,
                'parameters' => []
                ];

                # fetch the related sales order and verify the payment ID with rzp payment id
                # To avoid duplicate order entry for same quote
                $collection = $this->_objectManager->get('Magento\Sales\Model\Order')
                                                   ->getCollection()
                                                   ->addFieldToSelect('entity_id')
                                                   ->addFilter('quote_id', $receipt_id)
                                                   ->getFirstItem();

                $salesOrder = $collection->getData();

                if (empty($salesOrder['entity_id']) === false)
                {
                    $this->logger->info("Nimbbl inside order already processed with webhook quoteID:" . $receipt_id
                                    ." and OrderID:".$salesOrder['entity_id']);

                    $this->checkoutSession
                            ->setLastQuoteId($this->getQuote()->getId())
                            ->setLastSuccessQuoteId($this->getQuote()->getId())
                            ->clearHelperData();

                    $order = $this->orderRepository->get($salesOrder['entity_id']);

                    if ($order) {
                        $this->checkoutSession->setLastOrderId($order->getId())
                                           ->setLastRealOrderId($order->getIncrementId())
                                           ->setLastOrderStatus($order->getStatus());
                    }

                    $responseContent['order_id'] = true;
                }
            }
            else
            {
                if(empty($receipt_id) === false)
                {
                    //set the chache to stop webhook processing
                    $this->cache->save("started", "quote_Front_processing_$receipt_id", ["nimbbl"], 30);

                    $this->logger->info("Nimbbl front-end order processing started quoteID:" . $receipt_id);

                    $responseContent = [
                    'success'   => false,
                    'parameters' => []
                    ];
                }
                else
                {
                    $this->logger->info("Nimbbl order already processed with quoteID:" . $this->checkoutSession
                            ->getLastQuoteId());

                    $responseContent = [
                        'success'    => true,
                        'order_id'   => true,
                        'parameters' => []
                    ];

                }
            }

            $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $response->setData($responseContent);
            $response->setHttpResponseCode(200);

            return $response;
        }

        // --- Redirect mode callback ------------------------------------------------
        // checkout_mode = 'redirect': after payment, Nimbbl POSTs back to this URL.
        // The body is either a form field 'response' carrying a base64 JSON envelope,
        // or a raw JSON body. Individual popup fields (nimbbl_payment_id / email /
        // order_check) are NOT present, so we detect the redirect path by exclusion.
        //
        // Verify with SignatureVerifier::verifyCallback() (v4 signed/encrypted + legacy)
        // and fall back to HMAC v3/v2 when the SDK class is absent.
        // 64 KB is many times larger than any real Nimbbl redirect payload and prevents
        // base64_decode() + json_decode() from running on attacker-supplied multi-MB inputs.
        $maxRedirectBytes = 65536;

        $redirectRaw = '';
        if (!empty($_POST['response']) && is_string($_POST['response'])
            && strlen($_POST['response']) <= $maxRedirectBytes
        ) {
            // Standard redirect: Nimbbl posts a 'response' field with base64 payload.
            $redirectRaw = $_POST['response'];
        } elseif (
            empty($_POST['email'])
            && empty($_POST['order_check'])
            && empty($_POST['nimbbl_payment_id'])
            && empty($_POST['nimbbl_transaction_id'])
            && empty($_POST['error'])
        ) {
            // Some integrations post a raw JSON body directly (no form field).
            $rawBody = (string) file_get_contents('php://input');
            if ($rawBody !== '' && strlen($rawBody) <= $maxRedirectBytes) {
                $redirectRaw = $rawBody;
            }
        }

        if ($redirectRaw !== '') {
            $this->logger->info('Nimbbl: redirect callback received (length=' . strlen($redirectRaw) . ')');
            $callbackResult = $this->resolveRedirectCallback($redirectRaw);

            if ($callbackResult === null) {
                $this->messageManager->addError(__(
                    'Nimbbl: Payment verification failed. Please contact support.'
                ));
                return $this->_redirect('checkout/cart');
            }

            $outcome = $callbackResult['outcome'] ?? 'success';

            // G5: Redirect failed/pending payments back to cart with a meaningful message.
            // 'success' and 'authorized' (pre-auth) both proceed to order placement below.
            if ($outcome === 'failed') {
                $errMsg = !empty($callbackResult['message'])
                    ? htmlspecialchars(strip_tags((string) $callbackResult['message']), ENT_QUOTES, 'UTF-8')
                    : (string) __('Payment was declined or could not be completed. Please try again.');
                $this->messageManager->addError($errMsg);
                return $this->_redirect('checkout/cart');
            }
            if ($outcome === 'pending') {
                $this->messageManager->addError(__(
                    'Your payment is still processing. Please check your order status or contact support.'
                ));
                return $this->_redirect('checkout/cart');
            }

            // Store the pre-verified transaction ID on the payment so authorize()
            // can run Transaction Enquiry without needing to re-parse the body.
            $paymentInfo = $this->getQuote()->getPayment();
            $paymentInfo
                ->setAdditionalInformation('nimbbl_payment_id',       $callbackResult['transaction_id'])
                ->setAdditionalInformation('nimbbl_redirect_verified', true)
                ->setAdditionalInformation('nimbbl_payment_mode',     $callbackResult['payment_mode'])
                ->setMethod(PaymentMethod::METHOD_CODE);

            // G2: Flag pre-authorised (funds held, not captured) payments so we can
            // override the order state to STATE_PENDING_PAYMENT after placeOrder().
            if ($outcome === 'authorized') {
                $paymentInfo->setAdditionalInformation('nimbbl_payment_authorized', true);
            }

            try {
                if (!$this->customerSession->isLoggedIn()) {
                    $this->getQuote()->setCheckoutMethod($this->cartManagement::METHOD_GUEST);
                    $this->getQuote()->setCustomerEmail($this->customerSession->getCustomerEmailAddress());
                }
                $this->cartManagement->placeOrder($this->getQuote()->getId());

                // G2: placeOrder() leaves the order in STATE_PROCESSING (via authorize()).
                // For pre-auth, override to STATE_PENDING_PAYMENT so merchants know the
                // order needs manual capture or void before the authorisation expires.
                if ($outcome === 'authorized') {
                    $orderId = $this->checkoutSession->getLastOrderId();
                    if ($orderId) {
                        try {
                            $order = $this->orderRepository->get((int) $orderId);
                            $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                                  ->setStatus('pending_payment');
                            $order->addCommentToStatusHistory(
                                'Nimbbl: payment_authorized — funds held, awaiting capture. ' .
                                'Capture or void via the Nimbbl dashboard before the authorization expires.'
                            );
                            $this->orderRepository->save($order);
                            $this->logger->info(
                                'Nimbbl: set order ' . $order->getIncrementId()
                                . ' to pending_payment (pre-auth redirect callback)'
                            );
                        } catch (\Exception $e) {
                            $this->logger->warning(
                                'Nimbbl: could not override pre-auth order state: ' . $e->getMessage()
                            );
                        }
                    }
                }

                return $this->_redirect('checkout/onepage/success');
            } catch (\Exception $e) {
                $this->logger->critical('Nimbbl: redirect placeOrder failed: ' . $e->getMessage());
                $this->messageManager->addError(__($e->getMessage()));
                return $this->_redirect('checkout/cart');
            }
        }
        // ---------------------------------------------------------------------------

        if(isset($_POST['nimbbl_payment_id']) || isset($_POST['nimbbl_transaction_id']))
        {
            $this->debugLog("Nimbbl: Invoked execute with nimbbl_transaction_id already set.");

            $this->getQuote()->getPayment()->setMethod(PaymentMethod::METHOD_CODE);

            try
            {
                if(!$this->customerSession->isLoggedIn()) {
                    $this->getQuote()->setCheckoutMethod($this->cartManagement::METHOD_GUEST);
                    $this->getQuote()->setCustomerEmail($this->customerSession->getCustomerEmailAddress());
                }
                $this->cartManagement->placeOrder($this->getQuote()->getId());
                return $this->_redirect('checkout/onepage/success');
            }
            catch(\Exception $e)
            {
                $this->messageManager->addError(__($e->getMessage()));
                return $this->_redirect('checkout/cart');
            }
        }
        else
        {
            $this->debugLog("Nimbbl: Invoked execute to create a new order.");

            if(empty($_POST['email']) === true)
            {
                $this->logger->info("Email field is required");

                $responseContent = [
                    'message'   => "Email field is required",
                    'parameters' => []
                ];

                $code = 200;
            }
            else
            {
                $amount = (int) (number_format($this->getQuote()->getGrandTotal() * 100, 0, ".", ""));

                $payment_action = $this->config->getPaymentAction();

                $maze_version = $this->_objectManager->get('Magento\Framework\App\ProductMetadataInterface')->getVersion();
                // getOne() returns null when the module is not registered (e.g. during
                // integration tests or a partial install). Guard against TypeError on ['setup_version'].
                $module_version = ($this->_objectManager
                    ->get('Magento\Framework\Module\ModuleList')
                    ->getOne('Nimbbl_Magento') ?? [])['setup_version'] ?? '';

                $this->customerSession->setCustomerEmailAddress($_POST['email']);

                if ($payment_action === 'authorize')
                {
                    $payment_capture = 0;
                }
                else
                {
                    $payment_capture = 1;
                }

                $code = 400;

                try
                {

                    $isExpressCheckout = !empty($_POST['express_checkout']);

                    $payload = [
                        'amount'           => $amount,
                        'receipt'          => $receipt_id,
                        'currency'         => $this->getQuote()->getQuoteCurrencyCode(),
                        'payment_capture'  => $payment_capture,
                        'app_offer'        => ($this->getDiscount() > 0) ? 1 : 0,
                        'billing_details'  => json_decode($_POST['billing_address'] ?? '{}', true) ?: [],
                        'email'            => $_POST['email'],
                        'express_checkout' => $isExpressCheckout,
                    ];
                    $this->debugLog("Nimbbl: Creating order in RP with: " . json_encode($payload));

                    // $order = $this->rzp->order->create($payload);

                    $order = $this->createOrder($payload);

                    $responseContent = [
                        'message'   => 'Unable to create your order. Please contact support.',
                        'parameters' => []
                    ];

                    if (null !== $order && !empty($order['id']))
                    {
                        $this->debugLog("Nimbbl: Order creation in RP done.");

                        // checkout_mode admin setting drives is_hosted:
                        //   popup    → overlay (is_hosted = false)
                        //   redirect → Nimbbl hosted page (is_hosted = true)
                        $isHosted = ($this->config->getCheckoutMode() === 'redirect');

                        $responseContent = [
                            'success'           => true,
                            'nimbbl_order'      => $order['id'],
                            'order_id'          => $receipt_id,
                            'amount'            => $order['amount'],
                            'quote_currency'    => $this->getQuote()->getQuoteCurrencyCode(),
                            'quote_amount'      => number_format($this->getQuote()->getGrandTotal(), 2, ".", ""),
                            'maze_version'      => $maze_version,
                            'module_version'    => $module_version,
                            'is_hosted'         => $isHosted,
                            'image'             => '',
                            'embedded_url'      => '',
                        ];

                        $code = 200;

                        $this->checkoutSession->setNimbblOrderID($order['id']);
                        $this->checkoutSession->setNimbblOrderAmount($amount);

                        //save to nimbbl orderLink
                        $orderLinkCollection = $this->_objectManager->get('Nimbbl\Magento\Model\OrderLink')
                                                               ->getCollection()
                                                               ->addFilter('quote_id', $receipt_id)
                                                               ->getFirstItem();

                        $orderLinkData = $orderLinkCollection->getData();

                        if (empty($orderLinkData['entity_id']) === false)
                        {
                            $orderLinkCollection->setNimbblOrderId($order['id'])
                                      ->save();
                        }
                        else
                        {
                            $orderLnik = $this->_objectManager->create('Nimbbl\Magento\Model\OrderLink');
                            $orderLnik->setQuoteId($receipt_id)
                                      ->setNimbblOrderId($order['id'])
                                      ->save();
                        }

                    }
                }
                catch(\Exception $e)
                {
                    $responseContent = [
                        'message'   => $e->getMessage(),
                        'parameters' => []
                    ];
                }
            }

            //set the chache for race with webhook
            $this->cache->save("started", "quote_Front_processing_$receipt_id", ["nimbbl"], 300);
            $this->debugLog("Nimbbl: Returning from order creation with: " . json_encode($responseContent));

            $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
            $response->setData($responseContent);
            $response->setHttpResponseCode($code);

            return $response;
        }
    }

    public function getOrderID()
    {
        return $this->checkoutSession->getNimbblOrderID();
    }

    public function getNimbblOrderAmount()
    {
        return $this->checkoutSession->getNimbblOrderAmount();
    }

    public function getNimbblInvoiceId()
    {
        return (string) $this->checkoutSession->getNimbblInvoiceId();
    }

    protected function createOrder($payload)
    {
        $this->debugLog("Nimbbl: Invoking Nimbbl CreateOrder API (v3) with payload: " . json_encode($payload));

        $invoiceId = 'inv_nimbbl_magento_' . $payload['receipt'] . '_' . uniqid();
        // Persist so PaymentMethod::authorize() can use it for HMAC signature verification.
        $this->checkoutSession->setNimbblInvoiceId($invoiceId);

        // For express checkout, billing_details may be missing — Nimbbl collects address
        // on its own hosted page, so we send the user's email and leave address fields empty.
        $b       = is_array($payload['billing_details']) ? $payload['billing_details'] : [];
        $streets = is_array($b['street'] ?? null) ? $b['street'] : [$b['street'] ?? ''];

        // P2: Build order_line_items — mirrors WooCommerce create_nimbbl_payment_order_data().
        $lineItems = [];
        try {
            foreach ($this->getQuote()->getAllVisibleItems() as $item) {
                $product  = $item->getProduct();
                $imageUrl = '';
                if ($product) {
                    try {
                        $imageUrl = (string) $this->_objectManager
                            ->get(\Magento\Catalog\Helper\Image::class)
                            ->init($product, 'product_thumbnail_image')
                            ->getUrl();
                    } catch (\Exception $e) {
                        // Image URL is informational — proceed without it.
                    }
                }

                $itemQty      = (float) $item->getQty();
                $itemPrice    = round((float) $item->getPrice(), 2);
                $itemTaxTotal = round((float) $item->getTaxAmount(), 2);
                $itemTaxUnit  = $itemQty > 0 ? round($itemTaxTotal / $itemQty, 2) : 0.00;

                $lineItems[] = [
                    'product_id'   => (string) $item->getProductId(),
                    'product_name' => (string) $item->getName(),
                    'product_url'  => $product ? (string) $product->getProductUrl() : '',
                    'image_url'    => $imageUrl,
                    'sku'          => (string) $item->getSku(),
                    'quantity'     => (int) $itemQty,
                    'unit_price'   => $itemPrice,
                    'subtotal'     => round($itemPrice * $itemQty, 2),
                    'tax'          => $itemTaxUnit,
                    'total'        => round($itemPrice * $itemQty + $itemTaxTotal, 2),
                    'currency'     => 'INR',
                ];
            }
        } catch (\Exception $e) {
            $this->logger->warning('Nimbbl: could not build order_line_items: ' . $e->getMessage());
            $lineItems = [];
        }

        // P2: Callback URL for redirect mode only.
        // callback_url is a server-to-server redirect target: Nimbbl's production API rejects
        // non-routable URLs (localhost, 127.0.0.1) with PAYMENT_INFORMATION_MISSING.
        // In popup mode the response is delivered via callback_handler in JS, so the field
        // is unnecessary and must be omitted to avoid breaking local / staged environments.
        $callbackUrl = null;
        if ($this->config->getCheckoutMode() === 'redirect') {
            $rawCallbackUrl = (string) $this->_objectManager
                ->get(\Magento\Framework\UrlInterface::class)
                ->getUrl('nimbbl/payment/order');
            // Only include if it is a routable (non-localhost) URL.
            $parsedHost = parse_url($rawCallbackUrl, PHP_URL_HOST) ?? '';
            if ($parsedHost !== '' && $parsedHost !== 'localhost' && $parsedHost !== '127.0.0.1') {
                $callbackUrl = $rawCallbackUrl;
            }
        }

        $nimbblPayload = [
            "amount_before_tax" => $payload['amount'] / 100,
            "currency"          => "INR",
            "invoice_id"        => $invoiceId,
            "tax"               => 0,
            "total_amount"      => $payload['amount'] / 100,
            "user"              => [
                "mobile_number" => $b['telephone'] ?? '',
                "email"         => $payload['email'],
                "first_name"    => $b['firstname'] ?? '',
                "last_name"     => $b['lastname'] ?? ''
            ],
            "shipping_address"  => [
                "street"       => implode(',', array_filter($streets)),
                // Nimbbl requires a non-empty area; use street line 2 if present, else city
                "area"         => (!empty($streets[1]) ? $streets[1] : ($b['city'] ?? '')),
                "city"         => $b['city'] ?? '',
                "state"        => $b['region'] ?? '',
                "pincode"      => $b['postcode'] ?? '',
                "address_type" => "residential"
            ],
            // P2: Line items — mirrors WooCommerce create_nimbbl_payment_order_data().
            "order_line_items" => $lineItems,
        ];

        // Only attach callback_url if it was resolved above (redirect mode + routable host).
        if ($callbackUrl !== null) {
            $nimbblPayload['callback_url'] = $callbackUrl;
        }

        // SDK auto-generates merchant token and retries on 401 — no manual token handling needed
        $client         = $this->nimbblClientFactory->create();
        $order_response = $client->orders()->createOrder($nimbblPayload);

        $this->debugLog("Nimbbl: order response: " . json_encode($order_response));

        // Normalise response keys for downstream compatibility
        $order_response['id']     = $order_response['order_id'] ?? '';
        $order_response['amount'] = $order_response['total_amount'] ?? ($payload['amount'] / 100);

        return $order_response;
    }

    // protected function getMerchantPreferences()
    // {
    //     try
    //     {
    //         $api = new Api($this->config->getKeyId(),"");

    //         $response = $api->request->request("GET", "preferences");
    //     }
    //     catch (\Razorpay\Api\Errors\Error $e)
    //     {
    //         echo 'Magento Error : ' . $e->getMessage();
    //     }

    //     $preferences = [];

    //     $preferences['embedded_url'] = Api::getFullUrl("checkout/embedded");
    //     $preferences['is_hosted'] = false;
    //     $preferences['image'] = $response['options']['image'];

    //     if(isset($response['options']['redirect']) && $response['options']['redirect'] === true)
    //     {
    //         $preferences['is_hosted'] = true;
    //     }

    //     return $preferences;
    // }

    public function getDiscount()
    {
        return ($this->getQuote()->getBaseSubtotal() - $this->getQuote()->getBaseSubtotalWithDiscount());
    }

    /**
     * Parse and verify a Nimbbl redirect-mode callback raw response.
     *
     * Mirrors WooCommerce's resolve_nimbbl_callback():
     *   1. SDK SignatureVerifier::verifyCallback() handles v4 signed/encrypted + legacy.
     *   2. Base64-decode + HMAC v3/v2 fallback when the SDK class is absent.
     *
     * @param  string $raw  Base64-encoded response string or raw JSON.
     * @return array{transaction_id:string, order_id:string, payment_mode:string,
     *               outcome:string, message:string}|null
     *         outcome: 'success' | 'authorized' (pre-auth, funds held) | 'failed' | 'pending'
     *         Returns null if signature verification fails (caller must redirect to cart).
     */
    private function resolveRedirectCallback(string $raw): ?array
    {
        $keySecret = $this->config->getKeySecret();

        // 1. Primary path: SDK SignatureVerifier::verifyCallback()
        //    Handles v4 signed envelope, encrypted v4, and all legacy formats.
        if (class_exists('\\Nimbbl\\Api\\Common\\SignatureVerifier')) {
            try {
                $verifier = new \Nimbbl\Api\Common\SignatureVerifier();
                $result   = $verifier->verifyCallback($raw, $keySecret);

                if (!empty($result['success']) && is_array($result['payload'])) {
                    $p           = $result['payload'];
                    $txnId       = $p['nimbbl_transaction_id']
                        ?? ($p['transaction']['transaction_id'] ?? null);
                    $orderId     = $p['nimbbl_order_id']
                        ?? ($p['order_id'] ?? ($p['order']['order_id'] ?? null));
                    $paymentMode = $p['transaction']['payment_mode']
                        ?? ($p['payment_mode'] ?? '');

                    $this->debugLog('Nimbbl: redirect verifyCallback OK — version='
                        . ($result['version'] ?? '?')
                        . ' txn_id=' . ($txnId ?? '')
                        . ' order_id=' . ($orderId ?? ''));

                    if (empty($txnId)) {
                        $this->logger->error('Nimbbl: redirect verifyCallback OK but no transaction_id in payload');
                        return null;
                    }

                    // G2/G5: Determine payment outcome from the verified SDK payload.
                    $rawStatus   = $p['checkout_status']
                        ?? ($p['transaction']['status'] ?? ($p['status'] ?? ''));
                    $reason      = (string) ($p['reason'] ?? '');
                    $failMessage = (string) ($p['message']
                        ?? ($p['transaction']['failure_reason']
                        ?? ($p['transaction']['message'] ?? '')));

                    $outcome = $this->classifyNimbblStatus((string) $rawStatus, $reason);

                    // P1: Override with authoritative status from Transaction Enquiry API.
                    // Only fires when the local classification was inconclusive ('pending').
                    $this->applyEnquiryOverride($outcome, (string) $txnId);

                    return [
                        'transaction_id' => (string) $txnId,
                        'order_id'       => (string) ($orderId ?? ''),
                        'payment_mode'   => (string) $paymentMode,
                        'outcome'        => $outcome,
                        'message'        => $failMessage,
                    ];
                }

                $this->logger->warning('Nimbbl: redirect verifyCallback failed — '
                    . ($result['message'] ?? 'unknown error'));
                return null;

            } catch (\Exception $e) {
                $this->logger->warning('Nimbbl: redirect verifyCallback exception: '
                    . $e->getMessage() . ' — falling back to HMAC');
            }
        }

        // 2. Fallback: base64-decode then HMAC v3/v2 (SDK class not available)
        $decoded = base64_decode($raw, true);
        $data    = null;
        if ($decoded !== false && $decoded !== '') {
            $data = json_decode($decoded, true);
        }
        if (!is_array($data)) {
            $data = json_decode($raw, true);
        }
        if (!is_array($data)) {
            $this->logger->error('Nimbbl: redirect callback — cannot parse payload as JSON');
            return null;
        }

        $txnId       = $data['nimbbl_transaction_id'] ?? ($data['transaction']['transaction_id'] ?? null);
        $orderId     = $data['nimbbl_order_id'] ?? ($data['order_id'] ?? ($data['order']['order_id'] ?? null));
        $sig         = $data['nimbbl_signature'] ?? ($data['transaction']['signature'] ?? null);
        $sigVer      = $data['nimbbl_signature_version'] ?? ($data['transaction']['signature_version'] ?? 'v2');
        $invoiceId   = $data['order']['invoice_id'] ?? '';
        $status      = $data['transaction']['status'] ?? '';
        $txnType     = $data['transaction']['transaction_type'] ?? '';
        $currency    = $data['transaction']['transaction_currency'] ?? 'INR';
        $amount      = (float) ($data['transaction']['transaction_amount'] ?? 0);
        $paymentMode = $data['transaction']['payment_mode'] ?? ($data['payment_mode'] ?? '');

        if (empty($txnId) || empty($sig)) {
            $this->logger->error('Nimbbl: redirect HMAC fallback — missing txn_id or signature');
            return null;
        }

        if ($sigVer === 'v3') {
            $sigStr = $invoiceId . '|' . $txnId . '|'
                . \Nimbbl\Magento\Model\Config::normalizeAmount($amount) . '|'
                . $currency . '|' . $status . '|' . $txnType;
        } else {
            $sigStr = $invoiceId . '|' . $txnId . '|'
                . \Nimbbl\Magento\Model\Config::normalizeAmount($amount) . '|' . $currency;
        }

        // HMAC strings are pure ASCII (amount, currency, txn IDs, invoice IDs) — no encoding step needed.
        $expected = hash_hmac('sha256', $sigStr, $keySecret);
        if (!hash_equals($expected, (string) $sig)) {
            $this->logger->error('Nimbbl: redirect HMAC mismatch — version=' . $sigVer);
            return null;
        }

        $this->debugLog('Nimbbl: redirect HMAC OK — version=' . $sigVer . ' txn_id=' . $txnId);

        // G2/G5: Determine payment outcome from the HMAC-verified status field.
        $outcome = $this->classifyNimbblStatus((string) $status);

        // P1: Override with authoritative status from Transaction Enquiry API (HMAC path).
        // Only fires when the local classification was inconclusive ('pending').
        $this->applyEnquiryOverride($outcome, (string) $txnId);

        return [
            'transaction_id' => (string) $txnId,
            'order_id'       => (string) ($orderId ?? ''),
            'payment_mode'   => (string) $paymentMode,
            'outcome'        => $outcome,
            'message'        => '',
        ];
    }

    /**
     * Fetch transaction details from Nimbbl API for authoritative status classification.
     *
     * P1: Mirrors WooCommerce get_nimbbl_transaction_enquiry() — called inside
     * resolveRedirectCallback() to get the authoritative transaction status from the
     * Nimbbl API rather than relying solely on the client-side callback payload's
     * checkout_status, which can be stale or absent for edge-case flows.
     *
     * @param  string $txnId  Nimbbl transaction ID.
     * @return array  Transaction data returned by the API, or empty array on failure.
     */
    private function fetchTransactionEnquiry(string $txnId): array
    {
        if ($txnId === '') {
            return [];
        }
        try {
            $client = $this->nimbblClientFactory->create();
            $result = $client->transactions()->fetch($txnId);
            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            $this->logger->warning(
                'Nimbbl: Transaction Enquiry failed for txn=' . $txnId . ': ' . $e->getMessage()
            );
            return [];
        }
    }

    /**
     * Map a raw Nimbbl status string to a canonical outcome.
     *
     * @param  string $status  Raw status from SDK payload or Transaction Enquiry.
     * @param  string $reason  Optional reason field from the SDK payload.
     * @return string  'success' | 'authorized' | 'failed' | 'pending'
     */
    private function classifyNimbblStatus(string $status, string $reason = ''): string
    {
        $s = strtolower(trim($status));
        $r = strtolower(trim($reason));

        if (in_array($s, ['succeeded', 'success'], true)) {
            return 'success';
        }
        if ($s === 'authorized' || $r === 'payment_authorized') {
            return 'authorized';
        }
        if (in_array($s, ['failed', 'cancelled', 'canceled', 'expired', 'declined', 'voided'], true)) {
            return 'failed';
        }
        // Empty or unknown status → 'pending'; applyEnquiryOverride() resolves it via the API.
        return 'pending';
    }

    /**
     * Override $outcome using the authoritative Nimbbl Transaction Enquiry API.
     *
     * P1: Only fires when local classification returned 'pending' (inconclusive status).
     * Mirrors WooCommerce resolve_nimbbl_callback() → get_nimbbl_transaction_enquiry().
     * C1: Reads payment_status first, then falls through to status / transaction.status.
     *
     * @param string $outcome  Current outcome, passed by reference; updated when the API is conclusive.
     * @param string $txnId    Nimbbl transaction ID.
     */
    private function applyEnquiryOverride(string &$outcome, string $txnId): void
    {
        // Skip the API call when we already have a conclusive outcome (efficiency: avoids
        // an unnecessary network round-trip on every success/authorized/failed redirect).
        if ($outcome !== 'pending') {
            return;
        }
        $enquiry = $this->fetchTransactionEnquiry($txnId);
        if (empty($enquiry)) {
            return;
        }
        $apiStatus = strtolower(trim((string) ($enquiry['payment_status']
            ?? ($enquiry['status']
            ?? ($enquiry['transaction']['status'] ?? '')))));
        if ($apiStatus === '') {
            return;
        }
        $resolved = $this->classifyNimbblStatus($apiStatus);
        if ($resolved !== 'pending') {
            $outcome = $resolved;
        }
        $this->debugLog('Nimbbl: P1 Transaction Enquiry override — api_status=' . $apiStatus . ' outcome=' . $outcome);
    }
}
