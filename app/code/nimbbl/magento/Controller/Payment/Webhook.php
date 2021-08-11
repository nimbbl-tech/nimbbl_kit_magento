<?php 

namespace Nimbbl\Magento\Controller\Payment;

use Nimbbl\Api\NimbblApi;
// use Razorpay\Api\Errors;
use Nimbbl\Magento\Model\Config;
use Nimbbl\Magento\Model\PaymentMethod;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;

class Webhook extends \Nimbbl\Magento\Controller\BaseController implements CsrfAwareActionInterface
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Quote\Model\QuoteRepository
     */
    protected $quoteRepository;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    protected $api;

    protected $logger;

    protected $quoteManagement;

    protected $objectManagement;

    protected $storeManager;

    protected $customerRepository;

    protected $cache;

    /**
     * @var ManagerInterface
     */
    private $eventManager;

    const STATUS_APPROVED = 'APPROVED';

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Nimbbl\Model\CheckoutFactory $checkoutFactory
     * @param \Nimbbl\Magento\Model\Config $config
     * @param \Magento\Quote\Model\QuoteRepository $quoteRepository,
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Magento\Quote\Model\QuoteManagement $quoteManagement
     * @param \Magento\Store\Model\StoreManagerInterface $storeManagement
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Nimbbl\Magento\Model\CheckoutFactory $checkoutFactory,
        \Nimbbl\Magento\Model\Config $config,
        \Magento\Quote\Model\QuoteRepository $quoteRepository,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Store\Model\StoreManagerInterface $storeManagement,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Psr\Log\LoggerInterface $logger
    ) 
    {
        parent::__construct(
            $context,
            $customerSession,
            $checkoutSession,
            $config
        );

        $keyId                 = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $keySecret             = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);

        $this->api             = new NimbblApi($keyId, $keySecret);
        $this->order           = $order;
        $this->logger          = $logger;

        $this->objectManagement   = \Magento\Framework\App\ObjectManager::getInstance();
        $this->quoteManagement    = $quoteManagement;
        $this->checkoutFactory    = $checkoutFactory;
        $this->quoteRepository    = $quoteRepository;
        $this->storeManagement    = $storeManagement;
        $this->customerRepository = $customerRepository;
        $this->eventManager       = $eventManager;
        $this->cache = $cache;
    }

    /**
     * Processes the incoming webhook
     */
    public function execute()
    {       
        // $post = $this->getPostData(); 
        $post = file_get_contents("php://input");
			//$logger = wc_get_logger();
        $webhook_data = json_decode($post, true);

        // $order = wc_get_order($webhook_data['order']['invoice_id']);
        $this->logger->info("Nimbbl Webhook processing started." . $webhook_data['nimbbl_signature']);
        
        if (json_last_error() !== 0)
        {
            return;
        }   
        if (($this->config->isWebhookEnabled() != 0))
        { 
            if (isset($webhook_data['nimbbl_signature']))
            {
                $webhookSecret = $this->config->getWebhookSecret();
                
                $this->logger->info("Nimbbl Webhook Secret." . json_encode($webhookSecret));
                //
                // To accept webhooks, the merchant must configure 
                // it on the magento backend by setting the secret
                // 
                if (empty($webhookSecret) === true)
                {
                    return;
                }

                
                $verified = $this->api->util->verifyPaymentSignature([
                    'nimbbl_signature' => $webhook_data['nimbbl_signature'],
                    'nimbbl_transaction_id' => $webhook_data['nimbbl_transaction_id'],
                    'merchant_order_id' => $webhook_data['order']['invoice_id'],
                ]);
                // $this->api->utility->verifyWebhookSignature(json_encode($post), $webhook_data['nimbbl_signature'], $webhookSecret);
                
                if($verified){
                    return $this->orderPaid($webhook_data);
                }
            }
        }

        $this->logger->info("Nimbbl Webhook processing completed.");
    }

    /**
     * Order Paid webhook
     * 
     * @param array $post
     */
    protected function orderPaid(array $post)
    {
        $paymentId = $post['nimbbl_transaction_id'];
        $nimbbl_order_id = $post['nimbbl_order_id'];

        if (isset($post['order']['invoice_id']) === false)
        {
            $this->logger->info("Nimbbl Webhook: Quote ID not set for Nimbbl payment_id(:$paymentId)");
            return;
        }

        $quoteId   = $post['order']['invoice_id'];


        $orderLinkCollection = $this->_objectManager->get('Nimbbl\Magento\Model\OrderLink')
                                                   ->getCollection()
                                                   ->addFilter('quote_id', $quoteId)
                                                   ->addFilter('nimbbl_order_id', $nimbbl_order_id)
                                                   ->getFirstItem();

        $orderLink = $orderLinkCollection->getData();

        if (empty($orderLink['entity_id']) === false)
        {
            if ($orderLink['order_placed'])
            {
                 $this->logger->info(__("Nimbbl Webhook: Quote order is inactive for quoteID: $quoteId and Nimbbl payment_id(:$paymentId) with Maze OrderID (:%1) ", $orderLink['increment_order_id']));

                return;
            }

            //set the 1st webhook notification time
            if ($orderLink['webhook_count'] < 1)
            {
                $orderLinkCollection->setWebhookFirstNotifiedAt(time());
            }

            $orderLinkCollection->setWebhookCount($orderLink['webhook_count'] + 1)
                                ->setNimbblPaymentId($paymentId)
                                ->save();


            // Check if front-end cache flag active
            if (empty($this->cache->load("quote_Front_processing_".$quoteId)) === false)
            {
                $this->logger->info("Nimbbl Webhook: Order processing is active for quoteID: $quoteId and Nimbbl payment_id(:$paymentId)");
                header('Status: 409 Conflict, too early for processing', true, 409);
                exit;
            }

            $webhookWaitTime = $this->config->getConfigData(Config::WEBHOOK_WAIT_TIME) ? $this->config->getConfigData(Config::WEBHOOK_WAIT_TIME) : 300;

            //ignore webhook call for some time as per config, from first webhook call
            if ((time() - $orderLinkCollection->getWebhookFirstNotifiedAt()) < $webhookWaitTime)
            {
                $this->logger->info(__("Nimbbl Webhook: Order processing is active for quoteID: $quoteId and Nimbbl payment_id(:$paymentId) and webhook attempt: %1", ($orderLink['webhook_count'] + 1)));
                header('Status: 409 Conflict, too early for processing', true, 409);

                exit;
            }
        }

         // Check if front-end cache flag active
        if (empty($this->cache->load("quote_Front_processing_".$quoteId)) === false)
        {
            $this->logger->info("Nimbbl Webhook: Order processing is active for quoteID: $quoteId and Nimbbl payment_id(:$paymentId)");
            header('Status: 409 Conflict, too early for processing', true, 409);

            exit;
        }

        $amount    = number_format($post['order']['total_amount'], 0, ".", "");

        $this->logger->info("Nimbbl Webhook processing started for Nimbbl payment_id(:$amount)");

        $payment_created_time = $post['order']['order_date'];

        //validate if the quote Order is still active
        $quote = $this->quoteRepository->get($quoteId);

        //exit if quote is not active
        if (!$quote->getIsActive())
        {
            $this->logger->info("Nimbbl Webhook: Quote order is inactive for quoteID: $quoteId and Nimbbl payment_id(:$paymentId)");

            return;
        }

        //validate amount before placing order
        $quoteAmount = (int) (number_format($quote->getGrandTotal(), 0, ".", ""));
        $this->logger->info("Nimbbl Webhook: Amount paid doesn't match with store order amount for Nimbbl payment_id(:$quoteAmount)");

        if ($quoteAmount != $amount)
        {
            $this->logger->info("Nimbbl Webhook: Amount paid doesn't match with store order amount for Nimbbl payment_id(:$paymentId)");

            return;
        }

        # fetch the related sales order and verify the payment ID with rzp payment id
        # To avoid duplicate order entry for same quote 
        $collection = $this->_objectManager->get('Magento\Sales\Model\Order')
                                           ->getCollection()
                                           ->addFieldToSelect('entity_id')
                                           ->addFilter('quote_id', $quoteId)
                                           ->getFirstItem();
        
        $salesOrder = $collection->getData();
        
        if (empty($salesOrder['entity_id']) === false)
        {
            $order = $this->order->load($salesOrder['entity_id']);
            $orderNimbblPaymentId = $order->getPayment()->getLastTransId();

            if ($orderNimbblPaymentId === $paymentId)
            {
                $this->logger->info("Nimbbl Webhook: Sales Order and payment already exist for Nimbbl payment_id(:$paymentId)");

                return;
            }
        }

        $quote = $this->getQuoteObject($post, $quoteId);

        //before creating order let wait for 5 sec and re-verify if the quote is active or not
        $this->logger->info("Nimbbl Webhook: Waiting for 5 sec with quoteID:$quoteId.");

        sleep(5);

        $this->logger->info("Nimbbl Webhook: Waiting of 5 sec over with quoteID:$quoteId.");

        //validate if the quote Order is still active
        $quoteUpdated = $this->quoteRepository->get($quoteId);

        //exit if quote is not active
        if (!$quoteUpdated->getIsActive())
        {
            $this->logger->info("Nimbbl Webhook: Quote order is inactive for quoteID: $quoteId and Nimbbl payment_id(:$paymentId)");

            return;
        }

        //verify Nimbbl OrderLink status
        $orderLinkCollection = $this->_objectManager->get('Nimbbl\Magento\Model\OrderLink')
                                                   ->getCollection()
                                                   ->addFilter('quote_id', $quoteId)
                                                   ->addFilter('nimbbl_order_id', $nimbbl_order_id)
                                                   ->getFirstItem();

        $orderLink = $orderLinkCollection->getData();

        if (empty($orderLink['entity_id']) === false)
        {
            if ($orderLink['order_placed'])
            {
                $this->logger->info(__("Nimbbl Webhook: Quote order is inactive for quoteID: $quoteId and Nimbbl payment_id(:$paymentId) with Maze OrderID (:%1) ", $orderLink['increment_order_id']));

                return;
            }
        }

        //Now start processing the new order creation through webhook

        $this->cache->save("started", "quote_processing_$quoteId", ["nimbbl"], 30);

        $order = $this->quoteManagement->submit($quote);

        $payment = $order->getPayment();        

        $payment->setAmountPaid($amount)
                ->setLastTransId($paymentId)
                ->setTransactionId($paymentId)
                ->setIsTransactionClosed(true)
                ->setShouldCloseParentTransaction(true);

        //set nimbbl webhook fields
        $order->setByNimbblWebhook(1);

        $order->save();

        //disable the quote
        $quote->setIsActive(0)->save();

        //dispatch the "nimbbl_webhook_order_placed_after" event
        $eventData = [
                        'nimbbl_payment_id' => $paymentId,
                        'magento_quote_id' => $quoteId,
                        'magento_order_id' => $order->getEntityId(),
                        'amount_captured' => $post['order']['total_amount']
                     ];

        $transport = new DataObject($eventData);

        $this->eventManager->dispatch(
            'nimbbl_webhook_order_placed_after',
            [
                'context'   => 'nimbbl_webhook_order',
                'payment'   => $paymentId,
                'transport' => $transport
            ]
        );

        $this->logger->info("Nimbbl Webhook Processed successfully for Nimbbl payment_id(:$paymentId): and quoteID(: $quoteId) and OrderID(: ". $order->getEntityId() .")");

        return;
    }

    protected function getQuoteObject($post, $quoteId)
    {
        $quote = $this->quoteRepository->get($quoteId);

        $firstName = $quote->getBillingAddress()->getFirstname() ?? 'null';
        $lastName  = $quote->getBillingAddress()->getLastname() ?? 'null';
        $email     = $quote->getBillingAddress()->getEmail() ?? $post['payload']['payment']['entity']['email'];

        $quote->getPayment()->setMethod(PaymentMethod::METHOD_CODE);

        $store = $quote->getStore();

        if(empty($store) === true)
        {
            $store = $this->storeManagement->getStore();
        }

        $websiteId = $store->getWebsiteId();

        $customer = $this->objectManagement->create('Magento\Customer\Model\Customer');
        
        $customer->setWebsiteId($websiteId);

        //get customer from quote , otherwise from payment email
        $customer = $customer->loadByEmail($email);
        
        //if quote billing address doesn't contains address, set it as customer default billing address
        if ((empty($quote->getBillingAddress()->getFirstname()) === true) and
            (empty($customer->getEntityId()) === false))
        {   
            $quote->getBillingAddress()->setCustomerAddressId($customer->getDefaultBillingAddress()['id']);
        }

        //If need to insert new customer as guest
        if ((empty($customer->getEntityId()) === true) or
            (empty($quote->getBillingAddress()->getCustomerId()) === true))
        {
            $quote->setCustomerFirstname($firstName);
            $quote->setCustomerLastname($lastName);
            $quote->setCustomerEmail($email);
            $quote->setCustomerIsGuest(true);
        }

        $quote->setStore($store);

        $quote->collectTotals();

        $quote->save();

        return $quote;
    }

    /**
     * @return Webhook post data as an array
     */
    protected function getPostData() : array
    {
        $request = file_get_contents('php://input');

        return json_decode($request, true);
    }
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}