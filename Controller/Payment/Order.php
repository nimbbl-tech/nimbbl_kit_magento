<?php

namespace Nimbbl\Magento\Controller\Payment;

use Nimbbl\Api\NimbblApi;
use Nimbbl\Magento\Model\PaymentMethod;
use Magento\Framework\Controller\ResultFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;

class Order extends \Nimbbl\Magento\Controller\BaseController
{
    protected $quote;

    protected $checkoutSession;

    protected $cartManagement;

    protected $cache;

    protected $orderRepository;

    protected $logger;

    protected $productFactory;

    protected $imageHelper;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Nimbbl\Model\Config\Payment $config
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Catalog\Model\ProductFactory $productFactory
     * @param \Magento\Catalog\Helper\Image $imageHelper
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
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Catalog\Helper\Image $imageHelper
    ) {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $this->config          = $config;
        $this->cartManagement  = $cartManagement;
        $this->customerSession = $customerSession;
        $this->checkoutFactory = $checkoutFactory;
        $this->cache = $cache;
        $this->orderRepository = $orderRepository;
        $this->logger          = $logger;
        $this->productFactory = $productFactory;
        $this->imageHelper = $imageHelper;
        
        $this->objectManagement   = \Magento\Framework\App\ObjectManager::getInstance();
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
                        'items' => $this->getQuote()->getAllVisibleItems(),
                    ];
                    $this->logger->debug("Nimbbl: Creating order in NB with: " . json_encode($payload));

                    // $order = $this->nimbbl->order->create($payload);

                    $orderRes = $this->nimbbl->order->getOrderByInvoiceId($receipt_id);
                    if(count($orderRes) !== 0){
                        $orderId = $orderRes['order_id'];
                        $orderCheckRes = $this->nimbbl->order->retrieveOne($orderId);
                        if(count($orderCheckRes->error) === 0){
                            $order = $orderCheckRes->attributes;

                            $this->logger->debug("Nimbbl: Order already exists ".$order);

                            $responseContent = [
                                'success'           => true,
                                'nimbbl_order'      => $order['order_id'],
                                'order_id'          => $receipt_id,
                                'amount'            => $order['total_amount'],
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

                            $code=200;

                            $response = $this->resultFactory->create(ResultFactory::TYPE_JSON);
                            $response->setData($responseContent);
                            $response->setHttpResponseCode($code);

                            return $response;
                        }
                    }

                    $order = $this->createOrder($payload);

                    $responseContent = [
                        'message'   => 'Unable to create your order. Please contact support.',
                        'parameters' => []
                    ];

                    if (null !== $order && !empty($order['id']))
                    {
                        $this->logger->debug("Nimbbl: Order creation in NB done.");
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

                    }else{
                        $code = 400;
                        $responseContent = [
                            'message'   => $order['message'],
                            'parameters' => []
                        ];
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
        $this->logger->debug("Nimbbl: Invoking Nimbbl Nimbbl php sdk with payload: " . json_encode($payload));
        // [2021-05-09 21:00:52] main.DEBUG: Nimbbl: Invoking Nimbbl CreateOrder API with payload: {"amount":10000,"receipt":"11","currency":"USD","payment_capture":1,"app_offer":0,"billing_details":{"countryId":"IN","regionId":"553","regionCode":"MH","region":"Maharashtra","street":["901 Yash Orion","I B Patel Road","Goregaon East"],"company":"","telephone":"9987027067","postcode":"400091","city":"Mumbai","firstname":"Harish","lastname":"Patel","saveInAddressBook":null}} [] []
        $arg_order_item_data = array();
        foreach ($payload['items'] as $item_id => $item) {
            // $product = $this->productFactory->load($item->getProduct()->getId());
            $product_id = $item->getProduct()->getId();
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $orderproduct = $objectManager->create('Magento\Catalog\Model\Product')->load($product_id);
            $url = $this->imageHelper->init($orderproduct, 'product_thumbnail_image')->getUrl();
            $product = array(
                // $product_id    = $item['product_id']; // Get the product ID
                // $variation_id  = $item['variation_id']; // Get the variation ID
                "title" => $item['name'], // The product name
                "quantity" => $item['qty'],
                'uom' => '',
                'image_url' => $url,
                'description' => $item->getDescription(),
                'sku_id' => $item->get_sku(),

                // Get line item totals (non discounted)
                // $line_total     = $item['subtotal']; // or $item['line_subtotal'] -- The line item non discounted total
                // $line_total_tax = $item['subtotal_tax']; // or $item['line_subtotal_tax'] -- The line item non discounted tax total

                // Get line item totals (discounted)
                // 'rate' => $wc_product->get_sale_price(),
                'amount_before_tax' => $item['row_total'] - $item['tax_amount'],
                'tax' => $item['tax_amount'],
                "total_amount" => $item['row_total'], // or $item['line_total'] -- The line item non discounted total
                // $line_total_tax2 = $item['total_tax']; // The line item non discounted tax total

            );

            array_push($arg_order_item_data, $product);
        }
        $nimbblPayload = [
            "amount_before_tax"=>$payload['amount'] / 100,
            "currency"=>"INR",
            "invoice_id"=>$payload['receipt'],
            // "device_user_agent"=>"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0.4389.128 Safari/537.36",
            // "order_from_ip"=>"x.x.x.x",
            "tax"=>0,
            "user"=>[
                "mobile_number"=>$payload['billing_details']['telephone'],
                "email"=>$payload['email'],
                "first_name"=>$payload['billing_details']['firstname'],
                "last_name"=>$payload['billing_details']['lastname']
            ],
            "shipping_address"=>[
                // "address_1"=>"Some address",
                "street"=>implode(',', $payload['billing_details']['street']),
                // "landmark"=>"My landmark",
                "area"=>"",
                "city"=>$payload['billing_details']['city'],
                "state"=>$payload['billing_details']['region'],
                "pincode"=>$payload['billing_details']['postcode'],
                "address_type"=>"residential"
            
            ],
            "total_amount"=>$payload['amount'] / 100,
            "order_line_items" => $arg_order_item_data,
            // "order_line_items"=>[
            //     [
                    
            //         "referrer_platform_sku_id"=>"sku1",
            //         "title"=>"Designer Triangles",
            //         "description"=>"Wallpaper by  chenspec from Pixabay",
            //         "quantity"=>1,
            //         "rate"=>4,
            //         "amount"=>4,
            //         "total_amount"=>4,
            //         "image_url"=>"https://cdn.pixabay.com/photo/2021/02/15/15/25/rhomboid-6018215_960_720.jpg"
                
            //     ]
            // ]
        ];
        
        $order_response = $this->nimbbl->order->create($nimbblPayload);

        if(property_exists($order_response,"error") && !empty($order_response->error)){
            $this->logger->debug("Nimbbl: order response: " . json_encode($order_response->error));
            $order_response = $order_response->error;
            return $order_response;
        }else{
            $this->logger->debug("Nimbbl: order response: " . json_encode($order_response->attributes));

            $order_response = $order_response->attributes;
            $order_response['id']=$order_response['order_id'];
            $order_response['amount']=$order_response['total_amount'];
            return $order_response;
        }

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
