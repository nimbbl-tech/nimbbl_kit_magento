<?php

namespace Nimbbl\Magento\Block\Frontend\Order;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;

/**
 * Customer-facing Nimbbl payment details block.
 *
 * Displayed on:
 *   - Checkout success page   (checkout_onepage_success.xml)
 *   - My Account > Order View (sales_order_view.xml)
 *
 * Order resolution strategy:
 *   1. Magento Framework Registry key 'current_order' — set by the My Account
 *      order-view controller; this is the authoritative source on that page.
 *   2. Checkout session lastOrderId — set immediately after order placement;
 *      used on the checkout success page.
 *
 * The block renders nothing if the resolved order was not paid via Nimbbl,
 * or if the order does not belong to the currently logged-in customer.
 */
class NimbblInfo extends Template
{
    /** @var Registry */
    protected $coreRegistry;

    /** @var OrderRepositoryInterface */
    protected $orderRepository;

    /** @var CheckoutSession */
    protected $checkoutSession;

    /** @var CustomerSession */
    protected $customerSession;

    /** @var Order|false|null  null = not yet resolved; false = not a Nimbbl order */
    protected $resolvedOrder = null;

    public function __construct(
        Context                  $context,
        Registry                 $coreRegistry,
        OrderRepositoryInterface $orderRepository,
        CheckoutSession          $checkoutSession,
        CustomerSession          $customerSession,
        array                    $data = []
    ) {
        parent::__construct($context, $data);
        $this->coreRegistry    = $coreRegistry;
        $this->orderRepository = $orderRepository;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
    }

    /**
     * Resolve the current Sales Order.
     *
     * @return Order|null
     */
    public function getOrder(): ?Order
    {
        if ($this->resolvedOrder !== null) {
            return $this->resolvedOrder ?: null;
        }

        $this->resolvedOrder = false; // sentinel — avoids repeated DB lookups on re-entry

        $order = null;

        // 1. My Account order-view: the controller registers 'current_order' in the registry.
        $registryOrder = $this->coreRegistry->registry('current_order');
        if ($registryOrder instanceof Order && $registryOrder->getId()) {
            $order = $registryOrder;
        }

        // 2. Checkout success page: read the last-placed order from the checkout session.
        if (!$order) {
            $orderId = (int) $this->checkoutSession->getLastOrderId();
            if ($orderId) {
                try {
                    $order = $this->orderRepository->get($orderId);
                } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                    return null;
                } catch (\Exception $e) {
                    return null;
                }
            }
        }

        if (!$order) {
            return null;
        }

        // Only render for Nimbbl-paid orders.
        if (!$order->getPayment()
            || $order->getPayment()->getMethod() !== \Nimbbl\Magento\Model\PaymentMethod::METHOD_CODE
        ) {
            return null;
        }

        // Ownership check: a logged-in customer must only see their own orders.
        // Guest orders (customer_id = 0) are allowed because the checkout session
        // already scopes them to this browser session.
        $customerId = (int) $this->customerSession->getCustomerId();
        if ($customerId > 0 && (int) $order->getCustomerId() !== $customerId) {
            return null;
        }

        $this->resolvedOrder = $order;
        return $order;
    }

    /**
     * Returns true when the current order was paid through Nimbbl.
     * The template uses this as its early-exit guard.
     */
    public function isNimbblOrder(): bool
    {
        return $this->getOrder() !== null;
    }

    /**
     * @return string  Nimbbl Order ID (nimbbl_order_id from OrderLink), or empty string
     */
    public function getNimbblOrderId(): string
    {
        $order = $this->getOrder();
        if (!$order) {
            return '';
        }

        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $row = $objectManager->get('Nimbbl\Magento\Model\OrderLink')
                ->getCollection()
                ->addFilter('quote_id', $order->getQuoteId())
                ->getFirstItem()
                ->getData();

            return (string) ($row['nimbbl_order_id'] ?? '');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @return string  Our invoice_id sent to Nimbbl (Format: inv_nimbbl_magento_{quoteId}_{uniqid}),
     *                 or empty string
     */
    public function getInvoiceId(): string
    {
        $order = $this->getOrder();
        if (!$order || !$order->getPayment()) {
            return '';
        }
        return (string) ($order->getPayment()->getAdditionalInformation('nimbbl_invoice_id') ?? '');
    }

    /**
     * @return string  Nimbbl Transaction ID, or empty string
     */
    public function getNimbblTransactionId(): string
    {
        $order = $this->getOrder();
        if (!$order || !$order->getPayment()) {
            return '';
        }
        return (string) ($order->getPayment()->getLastTransId() ?? '');
    }

    /**
     * @return string  Payment mode (e.g. UPI, Card, Cash On Delivery), or empty string
     */
    public function getPaymentMode(): string
    {
        $order = $this->getOrder();
        if (!$order || !$order->getPayment()) {
            return '';
        }
        return (string) ($order->getPayment()->getAdditionalInformation('nimbbl_payment_mode') ?? '');
    }
}
