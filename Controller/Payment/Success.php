<?php

namespace Nimbbl\Magento\Controller\Payment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Nimbbl\Magento\Controller\Payment as NimbblPayment;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

/**
 * Class Success
 * @package Nimbbl\Magento\Controller\Payment
 */
class Success extends NimbblPayment implements CsrfAwareActionInterface
{
    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|void
     * @throws LocalizedException
     * @throws \Exception
     * @throws \Magento\Framework\Exception\MailException
     * @throws \Zend_Log_Exception
     */
    public function execute()
    {
        $params = $this->getRequest()->getParams();
        $this->helper->logger("Success from nimbbl", $params);

        if (is_array($params) && !empty($params) && isset($params['response'])) {

            $response = $params['response'];
            $mainResponse = json_decode(base64_decode($response), true);
            $this->helper->logger("Success from nimbbl", $mainResponse);

            $payload = $mainResponse['payload'];
            if (is_array($payload) && isset($payload['status']) && $payload['status'] == 'success') {

                if ($this->verifySignature($mainResponse['payload'])) {

                    $orderId = explode("_", $payload['order']['invoice_id']);
                    $order = $this->orderFactory->create()->loadByIncrementId($orderId['0']);

                    $payment = $order->getPayment();
                    $transactionID = $payload['transaction_id'];
                    $payment->setTransactionId($transactionID);
                    $payment->setLastTransId($transactionID);
                    $payment->setAdditionalInformation('transId', $transactionID);

                    foreach ($payload['transaction'] as $key => $val) {
                        if (!is_array($val)) {
                            $payment->setAdditionalInformation($key, $val);
                        } else {
                            if ($key == 'sub_payment_mode') {
                                foreach ($val as $subKey => $subVal) {
                                    $payment->setAdditionalInformation($subKey, $subVal);
                                }
                            }
                        }
                    }

                    $payment->setAdditionalInformation((array)$payment->getAdditionalInformation());
                    $trans = $this->transactionBuilder;
                    $transaction = $trans->setPayment($payment)
                        ->setOrder($order)
                        ->setTransactionId($transactionID)
                        ->setAdditionalInformation((array)$payment->getAdditionalInformation())
                        ->setFailSafe(true)
                        ->build(Transaction::TYPE_CAPTURE);

                    $payment->addTransactionCommentsToOrder($transaction, 'Transaction is approved by the bank');
                    $payment->setParentTransactionId(null);
                    $payment->save();

                    $this->orderSender->notify($order);

                    $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                    $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
                    $order->addStatusHistoryComment(__('Transaction is approved by the bank'),
                        Order::STATE_PROCESSING)->setIsCustomerNotified(true);
                    $order->save();

                    $transaction->save();

                    if ($this->helper->isAutoInvoice()) {
                        if (!$order->canInvoice()) {
                            $order->addStatusHistoryComment('Sorry, Order cannot be invoiced.', false);
                        }
                        $invoice = $this->invoiceService->prepareInvoice($order);
                        if (!$invoice) {
                            $order->addStatusHistoryComment('Can\'t generate the invoice right now.', false);
                        }

                        if (!$invoice->getTotalQty()) {
                            $order->addStatusHistoryComment('Can\'t generate an invoice without products.', false);
                        }
                        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                        $invoice->register();
                        $invoice->getOrder()->setCustomerNoteNotify(true);
                        $invoice->getOrder()->setIsInProcess(true);
                        $transactionSave = $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder());
                        $transactionSave->save();

                        try {
                            $this->invoiceSender->send($invoice);
                        } catch (LocalizedException $e) {
                            $order->addStatusHistoryComment('Can\'t send the invoice Email right now.', false);
                        }

                        $order->addStatusHistoryComment('Automatically Invoice Generated.', false);
                        $order->save();
                    }

                    $this->messageManager->addSuccessMessage('Transaction successful.');
                    $this->_redirect('checkout/onepage/success');

                } else {
                    $errorMsg = __('Signature not verify');
                    $this->messageManager->addErrorMessage($errorMsg);
                    $this->checkoutSession->restoreQuote();
                    $this->_redirect('checkout/cart');
                }
            } else {
                $errorMsg = __('Payment Rejected.');
                $this->messageManager->addErrorMessage($errorMsg);
                $this->checkoutSession->restoreQuote();
                $this->_redirect('checkout/cart');
            }

        } else {
            $errorMsg = __('There is a processing error with your Nimbbl payment response token.');
            $this->messageManager->addErrorMessage($errorMsg);
            $this->checkoutSession->restoreQuote();
            $this->_redirect('checkout/cart');
        }
    }

    /**
     * @param $mainResponse
     * @return bool
     * @throws \Zend_Log_Exception
     */
    public function verifySignature($mainResponse)
    {
        $invoiceId = $mainResponse['order']['invoice_id'];
        $transaction_id = $mainResponse['transaction']['transaction_id'];
        $total_amount = number_format((float)$mainResponse['transaction']['transaction_amount'], 2, '.', '');
        $currency = $mainResponse['transaction']['transaction_currency'];
        $status = $mainResponse['transaction']['status'];
        $transaction_type = $mainResponse['transaction']['transaction_type'];
        $raw = "$invoiceId|$transaction_id|$total_amount|$currency|$status|$transaction_type";

        $key = $this->helper->getAccessSecret();

        $generatedSignature = hash_hmac(
            'sha256',
            $raw,
            $key
        );

        $this->helper->logger('generated key', $generatedSignature);
        $this->helper->logger('responce key', $mainResponse['nimbbl_signature']);

        if ($generatedSignature == $mainResponse['nimbbl_signature']) {
            return true;
        }
        return false;
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
