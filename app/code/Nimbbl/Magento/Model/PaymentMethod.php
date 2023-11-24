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
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
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

        $this->key_id = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $this->key_secret = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);

        // $this->rzp = new Api($this->key_id, $this->key_secret);

        $this->order = $order;

        // $this->rzp->setHeader('User-Agent', 'Nimbbl/'. $this->getChannel());
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

            $this->_logger->debug("Nimbbl: PaymentMethod authorize invoked with: " . json_encode($request));

            $isWebhookCall = false;

            if((empty($request) === true) and (isset($_POST['nimbbl_signature']) === true))
            {
                //set request data based on redirect flow
                $request['paymentMethod']['additional_data'] = [
                    'nimbbl_payment_id' => $_POST['nimbbl_payment_id'],
                    'nimbbl_order_id' => $_POST['nimbbl_order_id'],
                    'nimbbl_signature' => $_POST['nimbbl_signature']
                ];
            }

            if(empty($request['payload']['payment']['entity']['id']) === false)
            {
                $payment_id = $request['payload']['payment']['entity']['id'];
                $nimbbl_order_id = $request['payload']['order']['entity']['id'];

                $isWebhookCall = true;
                //validate that request is from webhook only

                // TODO: Implement our own webhook signature verification.
                // $this->validateWebhookSignature($request);
            }
            else
            {
                if (isset($request['paymentMethod']['additional_data']['nimbbl_payment_id'])) {
                    $payment_id = $request['paymentMethod']['additional_data']['nimbbl_payment_id'];
                }

                $nimbbl_order_id = $this->order->getOrderId();

                //validate NimbblOrderamount with quote/order amount before signature
                $orderAmount = (int) (number_format($order->getGrandTotal() * 100, 0, ".", ""));

                if ($orderAmount !== $this->order->getNimbblOrderAmount())
                {
                    $rzpOrderAmount = $order->getOrderCurrency()->formatTxt(number_format($this->order->getNimbblOrderAmount() / 100, 2, ".", ""));

                    throw new LocalizedException(__("Cart order amount = %1 doesn't match with amount paid = %2", $order->getOrderCurrency()->formatTxt($order->getGrandTotal()), $rzpOrderAmount));
                }

                // TODO: Implement our own signature verification.
                // $this->validateSignature($request);
            }

            $payment->setStatus(self::STATUS_APPROVED)
                    ->setAmountPaid($amount)
                    ->setLastTransId($payment_id)
                    ->setTransactionId($payment_id)
                    ->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true);

            // update the Nimbbl payment with corresponding created order ID of this quote ID
            $this->updatePaymentNote($payment_id, $order, $nimbbl_order_id, $isWebhookCall);
        }
        catch (\Exception $e)
        {
            $this->_logger->critical($e);
            throw new LocalizedException(__('Nimbbl Error: %1.', $e->getMessage()));
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
     * Update the payment note with Magento frontend OrderID
     *
     * @param string $paymentId
     * @param object $order
     * @param object $nimbblOrderId
     * @param object $$isWebhookCall
     */
    protected function updatePaymentNote($paymentId, $order, $nimbblOrderId, $isWebhookCall)
    {
        // TODO: Figure this out not sure what this is going to be used for...
        // update the Nimbbl payment with corresponding created order ID of this quote ID
        // $this->rzp->payment->fetch($paymentId)->edit(
        //     array(
        //         'notes' => array(
        //             'merchant_order_id' => $order->getIncrementId(),
        //             'merchant_quote_id' => $order->getQuoteId()
        //         )
        //     )
        // );

        //update orderLink
        $_objectManager  = \Magento\Framework\App\ObjectManager::getInstance();

        $orderLinkCollection = $_objectManager->get('Nimbbl\Magento\Model\OrderLink')
                                                   ->getCollection()
                                                   ->addFieldToSelect('entity_id')
                                                   ->addFilter('quote_id', $order->getQuoteId())
                                                   ->addFilter('nimbbl_order_id', $nimbblOrderId)
                                                   ->getFirstItem();

        $orderLink = $orderLinkCollection->getData();

        if (empty($orderLink['entity_id']) === false)
        {

            $orderLinkCollection->setNimbblPaymentId($paymentId)
                                ->setIncrementOrderId($order->getIncrementId());

            if ($isWebhookCall)
            {
                $orderLinkCollection->setByWebhook(true)->save();
            }
            else
            {
                $orderLinkCollection->setByFrontend(true)->save();
            }
        }

    }

    // protected function validateSignature($request)
    // {
    //     $attributes = array(
    //         'nimbbl_payment_id' => $request['paymentMethod']['additional_data']['nimbbl_payment_id'],
    //         'nimbbl_order_id'   => $this->order->getOrderId(),
    //         'nimbbl_signature'  => $request['paymentMethod']['additional_data']['nimbbl_signature'],
    //     );

    //     $this->rzp->utility->verifyPaymentSignature($attributes);
    // }

    // /**
    //  * [validateWebhookSignature Used in case of webhook request for payment auth]
    //  * @param  array  $post
    //  * @return [type]
    //  */
    // public  function validateWebhookSignature(array $post)
    // {
    //     $webhookSecret = $this->config->getWebhookSecret();

    //     $this->rzp->utility->verifyWebhookSignature(json_encode($post), $_SERVER['HTTP_X_NIMBBL_SIGNATURE'], $webhookSecret);
    // }

    protected function getPostData()
    {
        $request = file_get_contents('php://input');

        return json_decode($request, true);
    }

    /**
     * Refunds specified amount
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        return $this;
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
