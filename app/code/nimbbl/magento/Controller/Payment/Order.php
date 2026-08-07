<?php

namespace Nimbbl\Magento\Controller\Payment;

use Nimbbl\Magento\Model\PaymentMethod;
use Nimbbl\Magento\Model\NimbblClientFactory;
use Magento\Framework\Controller\ResultFactory;

class Order extends \Nimbbl\Magento\Controller\BaseController
{
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
        $this->logger->debug("Nimbbl: Order creation controller endpoint invoked.");
        $receipt_id = $this->getQuote()->getId();

        if(empty($_POST['error']) === false)
        {
            $this->messageManager->addError(__('Payment Failed'));
            return $this->_redirect('checkout/cart');
        }

        if (isset($_POST['order_check']))
        {
            $this->logger->debug("Nimbbl: Initiating order_check.");
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

        if(isset($_POST['nimbbl_payment_id']) || isset($_POST['nimbbl_transaction_id']))
        {
            $this->logger->debug("Nimbbl: Invoked execute with nimbbl_transaction_id already set.");

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
            $this->logger->debug("Nimbbl: Invoked execute to create a new order.");

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
                $module_version =  $this->_objectManager->get('Magento\Framework\Module\ModuleList')->getOne('Nimbbl_Magento')['setup_version'];

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

                    $payload = [
                        'amount' => $amount,
                        'receipt' => $receipt_id,
                        'currency' => $this->getQuote()->getQuoteCurrencyCode(),
                        'payment_capture' => $payment_capture,
                        'app_offer' => ($this->getDiscount() > 0) ? 1 : 0,
                        'billing_details' => json_decode($_POST['billing_address'], true),
                        'email' => $_POST['email'],
                    ];
                    $this->logger->debug("Nimbbl: Creating order in RP with: " . json_encode($payload));

                    // $order = $this->rzp->order->create($payload);

                    $order = $this->createOrder($payload);

                    $responseContent = [
                        'message'   => 'Unable to create your order. Please contact support.',
                        'parameters' => []
                    ];

                    if (null !== $order && !empty($order['id']))
                    {
                        $this->logger->debug("Nimbbl: Order creation in RP done.");
                        $is_hosted = false;

                        // $merchantPreferences    = $this->getMerchantPreferences();

                        $responseContent = [
                            'success'           => true,
                            'nimbbl_order'         => $order['id'],
                            'order_id'          => $receipt_id,
                            'amount'            => $order['amount'],
                            'quote_currency'    => $this->getQuote()->getQuoteCurrencyCode(),
                            'quote_amount'      => number_format($this->getQuote()->getGrandTotal(), 2, ".", ""),
                            'maze_version'      => $maze_version,
                            'module_version'    => $module_version,
                            // 'is_hosted'         => $merchantPreferences['is_hosted'],
                            // 'image'             => $merchantPreferences['image'],
                            // 'embedded_url'      => $merchantPreferences['embedded_url'],
                            'is_hosted'         => false,
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
                catch(\Razorpay\Api\Errors\Error $e)
                {
                    $responseContent = [
                        'message'   => $e->getMessage(),
                        'parameters' => []
                    ];
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
            $this->logger->debug("Nimbbl: Returning from order creation with: " . json_encode($responseContent));

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

    protected function createOrder($payload)
    {
        $this->logger->debug("Nimbbl: Invoking Nimbbl CreateOrder API (v3) with payload: " . json_encode($payload));

        $nimbblPayload = [
            "amount_before_tax" => $payload['amount'] / 100,
            "currency"          => "INR",
            "invoice_id"        => 'inv_nimbbl_magento_' . $payload['receipt'] . '_' . uniqid(),
            "tax"               => 0,
            "total_amount"      => $payload['amount'] / 100,
            "user"              => [
                "mobile_number" => $payload['billing_details']['telephone'],
                "email"         => $payload['email'],
                "first_name"    => $payload['billing_details']['firstname'],
                "last_name"     => $payload['billing_details']['lastname']
            ],
            "shipping_address"  => [
                "street"       => implode(',', $payload['billing_details']['street']),
                // Nimbbl requires a non-empty area; use street line 2 if present, else city
                "area"         => !empty($payload['billing_details']['street'][1])
                                    ? $payload['billing_details']['street'][1]
                                    : $payload['billing_details']['city'],
                "city"         => $payload['billing_details']['city'],
                "state"        => $payload['billing_details']['region'],
                "pincode"      => $payload['billing_details']['postcode'],
                "address_type" => "residential"
            ],
        ];

        // SDK auto-generates merchant token and retries on 401 — no manual token handling needed
        $client         = $this->nimbblClientFactory->create();
        $order_response = $client->orders()->createOrder($nimbblPayload);

        $this->logger->debug("Nimbbl: order response: " . json_encode($order_response));

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
}
