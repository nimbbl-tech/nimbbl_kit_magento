<?php

namespace Nimbbl\Magento\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

/**
 * Renders a compact Nimbbl-specific info panel on the admin Order View page.
 *
 * Displayed fields (all sourced from the nimbbl_sales_order OrderLink row):
 *   - Nimbbl Order ID     (nimbbl_order_id)
 *   - Nimbbl Transaction ID (nimbbl_payment_id)
 *   - Fulfilled via        (Webhook / Frontend / —)
 */
class NimbblInfo extends Template
{
    /** @var Registry */
    protected $coreRegistry;

    /** @var array|null */
    protected $orderLinkData = null;

    public function __construct(
        Context  $context,
        Registry $coreRegistry,
        array    $data = []
    ) {
        parent::__construct($context, $data);
        $this->coreRegistry = $coreRegistry;
    }

    /**
     * Returns the current sales order.
     *
     * @return \Magento\Sales\Model\Order|null
     */
    public function getOrder(): ?\Magento\Sales\Model\Order
    {
        return $this->coreRegistry->registry('current_order');
    }

    /**
     * Returns the matching OrderLink row for the current order, or null if not found.
     *
     * @return array|null
     */
    public function getNimbblOrderLink(): ?array
    {
        if ($this->orderLinkData !== null) {
            return $this->orderLinkData ?: null;
        }

        $this->orderLinkData = [];

        $order = $this->getOrder();
        if (!$order || !$order->getPayment()) {
            return null;
        }

        $methodCode = $order->getPayment()->getMethod();
        if ($methodCode !== \Nimbbl\Magento\Model\PaymentMethod::METHOD_CODE) {
            return null;
        }

        $quoteId = $order->getQuoteId();
        if (empty($quoteId)) {
            return null;
        }

        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $row = $objectManager
                ->get('Nimbbl\Magento\Model\OrderLink')
                ->getCollection()
                ->addFilter('quote_id', $quoteId)
                ->getFirstItem()
                ->getData();

            $this->orderLinkData = $row ?: [];
        } catch (\Exception $e) {
            $this->orderLinkData = [];
        }

        return $this->orderLinkData ?: null;
    }

    /**
     * @return string  Nimbbl Order ID, or empty string
     */
    public function getNimbblOrderId(): string
    {
        return (string) ($this->getNimbblOrderLink()['nimbbl_order_id'] ?? '');
    }

    /**
     * @return string  Nimbbl Transaction ID, or empty string
     */
    public function getNimbblTransactionId(): string
    {
        return (string) ($this->getNimbblOrderLink()['nimbbl_payment_id'] ?? '');
    }

    /**
     * @return string  "Webhook" | "Frontend" | "—"
     */
    public function getFulfilledVia(): string
    {
        $link = $this->getNimbblOrderLink();
        if (!$link) {
            return '—';
        }
        if (!empty($link['by_webhook'])) {
            return 'Webhook';
        }
        if (!empty($link['by_frontend'])) {
            return 'Frontend callback';
        }
        return '—';
    }

    /**
     * Returns the Nimbbl invoice_id that was sent to the Nimbbl API for this order.
     *
     * Format: inv_nimbbl_magento_{quoteId}_{uniqid}
     * Stored on the Sales Order payment additional information after authorization.
     *
     * @return string
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
     * Returns the payment mode reported by Nimbbl (e.g. UPI, Card, Cash On Delivery).
     *
     * Populated from the Transaction Enquiry API response (popup/redirect paths)
     * or from the webhook payload (webhook path).
     *
     * @return string
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
