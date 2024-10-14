<?php

namespace Nimbbl\Magento\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Nimbbl\Magento\Helper\Data;
use Magento\Checkout\Model\Session;
use Magento\Sales\Model\OrderFactory;
use Magento\Catalog\Helper\Image;
use Magento\Catalog\Model\ProductFactory;
use Magento\Sales\Model\Order;

/**
 * Class Redirect
 * @package Nimbbl\Magento\Block
 */
class Redirect extends Template
{
    /**
     * @var Data
     */
    protected $helper;
    /**
     * @var Session
     */
    protected $checkoutSession;
    /**
     * @var OrderFactory
     */
    protected $orderFactory;
    /**
     * @var Image
     */
    protected $imageHelper;
    /**
     * @var ProductFactory
     */
    protected $productFactory;

    /**
     * Redirect constructor.
     * @param Session $checkoutSession
     * @param OrderFactory $orderFactory
     * @param Context $context
     * @param Image $imageHelper
     * @param ProductFactory $productFactory
     * @param Data $helper
     */
    public function __construct(
        Session $checkoutSession,
        OrderFactory $orderFactory,
        Context $context,
        Image $imageHelper,
        ProductFactory $productFactory,
        Data $helper
    )
    {
        $this->orderFactory = $orderFactory;
        $this->checkoutSession = $checkoutSession;
        $this->helper = $helper;
        $this->imageHelper = $imageHelper;
        $this->productFactory = $productFactory;
        parent::__construct($context);
    }

    /**
     * @return Order
     */
    public function getOrder()
    {
        $orderIncrementId = $this->checkoutSession->getLastRealOrderId();
        $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);
        return $order;
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function getOrderValues()
    {
        $sendArr = [
            'success' => false,
            'orderId' => '',
            'orderToken' => '',
            'errorMessage' => 'Something went wrong, please try again after sometimes.',
        ];

        $orderIncrementId = $this->checkoutSession->getLastRealOrderId();
        $order = $this->orderFactory->create()->loadByIncrementId($orderIncrementId);

        try {

            $curl = curl_init();
            curl_setopt_array($curl, array(
                CURLOPT_URL => $this->helper->getTokenUrl(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => '{
                  "access_key": "' . $this->helper->getAccessKey() . '",
                  "access_secret": "' . $this->helper->getAccessSecret() . '"
                }',
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Accept: application/json'
                ),
            ));

            $response = curl_exec($curl);
            curl_close($curl);
            $finalAccessToken = json_decode($response, true);

            if (array_key_exists('token', $finalAccessToken)) {
                $accessToken = $finalAccessToken['token'];

                $jayParsedAry = [
                    "amount_before_tax" => round($order->getGrandTotal() - $order->getTaxAmount(),2),
                    "tax" => round($order->getTaxAmount(),2),
                    "total_amount" => round($order->getGrandTotal(),2),
                    "user" => [
                        "email" => $order->getCustomerEmail(),
                        "first_name" => $order->getCustomerFirstname(),
                        "last_name" => $order->getCustomerLastname(),
                        "mobile_number" => $order->getBillingAddress()->getTelephone()
                    ],
                    "shipping_address" => [
                        "address_1" => implode(",", $order->getBillingAddress()->getStreet()),
                        "area" => ".",
                        "city" => $order->getBillingAddress()->getCity(),
                        "state" => $order->getBillingAddress()->getRegion(),
                        "pincode" => $order->getBillingAddress()->getPostcode(),
                        "address_type" => "BillAddress"
                    ],
                    "currency" => $order->getOrderCurrencyCode(),
                    "invoice_id" => $orderIncrementId."_".rand(10,99),
                    "referrer_platform" => "Magento_Nimbbl",
                    "referrer_platform_version" => "1.0.0",
                    "custom_attributes" => [
                        "Environment" => "Magento"
                    ]
                ];

                $orderItemData = [];
                foreach ($order->getAllVisibleItems() as $orderItem) {

                    $product = $this->productFactory->create()->load($orderItem->getProductId());
                    $imageUrl = $this->imageHelper->init($product, 'product_page_image_small')
                        ->setImageFile($product->getSmallImage())
                        ->resize(380)
                        ->getUrl();

                    $orderItemData[] = [
                        "sku_id" => $orderItem->getSku(),
                        "title" => $orderItem->getName(),
                        "image_url" => $imageUrl,
                        "rate" => round($orderItem->getPrice(),2),
                        "quantity" => round($orderItem->getQtyOrdered()),
                        "tax" => round($orderItem->getTaxAmount(),2),
                        "total_amount" => round($orderItem->getRowTotal(),2),
                    ];
                }

                $jayParsedAry['order_line_items'] = $orderItemData;
                $this->helper->logger('Payment Request', $jayParsedAry);

                $payload = json_encode($jayParsedAry);

                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $this->helper->getGatewayUrl(),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => array(
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Authorization: Bearer ' . $accessToken
                    ),
                ));

                $orderResponse = curl_exec($curl);
                curl_close($curl);
                $finalOrderResponse = json_decode($orderResponse, true);

                $this->helper->logger('Payment Response', $finalOrderResponse);

                if (array_key_exists('order_id', $finalOrderResponse)) {
                    $orderId = $finalOrderResponse['order_id'];
                    $orderToken = $finalOrderResponse['token'];
                    $sendArr = [
                        'success' => true,
                        'orderId' => $orderId,
                        'orderToken' => $orderToken,
                    ];
                } else {
                    $error = "Something went wrong, please try again after sometimes.";
                    if (array_key_exists('error', $finalOrderResponse)) {
                        $error = $finalOrderResponse['error']['nimbbl_consumer_message'];
                    }
                    $sendArr['errorMessage'] = $error;
                    $order->addStatusHistoryComment($error,
                        Order::STATE_CANCELED)->setIsCustomerNotified(true);
                    $order->cancel();
                    $order->save();
                    $this->checkoutSession->restoreQuote();
                }
            } else {
                $error = "Something went wrong, please try again after sometimes.";
                if (array_key_exists('error', $finalAccessToken)) {
                    $error = $finalAccessToken['error']['nimbbl_consumer_message'];
                }
                $sendArr['errorMessage'] = $error;
                $order->addStatusHistoryComment($error,
                    Order::STATE_CANCELED)->setIsCustomerNotified(true);
                $order->cancel();
                $order->save();
                $this->checkoutSession->restoreQuote();
            }

        } catch (\Exception $e) {
            $this->helper->logger('Error',$e->getMessage());
            $error = "Something went wrong";
            $order->addStatusHistoryComment($error,
                Order::STATE_CANCELED)->setIsCustomerNotified(true);
            $order->cancel();
            $order->save();
            $this->checkoutSession->restoreQuote();

        }
        return $sendArr;
    }

    public function getErrorImgUrl()
    {
        return $this->helper->getErrorImgUrl();
    }

    /**
     * @return string
     */
    public function getCallbackURL(){
        return $this->getUrl('*/*/success');
    }

    /**
     * @return string
     */
    public function getJsURL(){
        return "https://apipp2.nimbbl.tech/static/assets/nimbblSDK.js";
    }
}