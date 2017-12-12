<?php
/**
* BssCommerce Co.
*
* NOTICE OF LICENSE
*
* This source file is subject to the EULA
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://bsscommerce.com/Bss-Commerce-License.txt
*
* =================================================================
*                 MAGENTO EDITION USAGE NOTICE
* =================================================================
* This package designed for Magento COMMUNITY edition
* BssCommerce does not guarantee correct work of this extension
* on any other Magento edition except Magento COMMUNITY edition.
* BssCommerce does not provide extension support in case of
* incorrect edition usage.
* =================================================================
*
* @category   BSS
* @package    Bss_Autoinvoice
* @author     Trung <kiutisuperking@gmail.com>
* @copyright  Copyright (c) 2014-2016 BssCommerce Co. (http://bsscommerce.com)
* @license    http://bsscommerce.com/Bss-Commerce-License.txt
*/
class Bss_Autoinvoice_Model_Order_Payment extends Mage_Sales_Model_Order_Payment
{
	public function capture($invoice)
    {
    	if(Mage::getStoreConfig('autoinvoice/settings/active') && Mage::getStoreConfig('autoinvoice/settings/invoice')) {
    		$payment = $this->getOrder()->getPayment()->getMethodInstance()->getCode();
    		if(!in_array($payment,explode(',',Mage::getStoreConfig('autoinvoice/settings/payment_methods'))) )  {
    			return $this;
    		}
    	}
    	
        if (is_null($invoice)) {
            $invoice = $this->_invoice();
            $this->setCreatedInvoice($invoice);
            return $this; // @see Mage_Sales_Model_Order_Invoice::capture()
        }

        $amountToCapture = $this->_formatAmount($invoice->getBaseGrandTotal());
        $order = $this->getOrder();

        // prepare parent transaction and its amount
        $paidWorkaround = 0;
        if (!$invoice->wasPayCalled()) {
            $paidWorkaround = (float)$amountToCapture;
        }
        $this->_isCaptureFinal($paidWorkaround);

        $this->_generateTransactionId(
            Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE,
            $this->getAuthorizationTransaction()
        );

        Mage::dispatchEvent('sales_order_payment_capture', array('payment' => $this, 'invoice' => $invoice));

        /**
         * Fetch an update about existing transaction. It can determine whether the transaction can be paid
         * Capture attempt will happen only when invoice is not yet paid and the transaction can be paid
         */
        if ($invoice->getTransactionId()) {
            $this->getMethodInstance()
                ->setStore($order->getStoreId())
                ->fetchTransactionInfo($this, $invoice->getTransactionId());
        }
        $status = true;
        if (!$invoice->getIsPaid() && !$this->getIsTransactionPending()) {
            // attempt to capture: this can trigger "is_transaction_pending"
            $this->getMethodInstance()->setStore($order->getStoreId())->capture($this, $amountToCapture);

            $transaction = $this->_addTransaction(
                Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE,
                $invoice,
                true
            );

            if ($this->getIsTransactionPending()) {
                $message = Mage::helper('sales')->__('Capturing amount of %s is pending approval on gateway.', $this->_formatPrice($amountToCapture));
                $state = Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW;
                if ($this->getIsFraudDetected()) {
                    $status = Mage_Sales_Model_Order::STATUS_FRAUD;
                }
                $invoice->setIsPaid(false);
            } else { // normal online capture: invoice is marked as "paid"
                $message = Mage::helper('sales')->__('Captured amount of %s online.', $this->_formatPrice($amountToCapture));
                $state = Mage_Sales_Model_Order::STATE_PROCESSING;
                $invoice->setIsPaid(true);
                $this->_updateTotals(array('base_amount_paid_online' => $amountToCapture));
            }
            if ($order->isNominal()) {
                $message = $this->_prependMessage(Mage::helper('sales')->__('Nominal order registered.'));
            } else {
                $message = $this->_prependMessage($message);
                $message = $this->_appendTransactionToMessage($transaction, $message);
            }
            $order->setState($state, $status, $message);
            $this->getMethodInstance()->processInvoice($invoice, $this); // should be deprecated
            return $this;
        }
        Mage::throwException(
            Mage::helper('sales')->__('The transaction "%s" cannot be captured yet.', $invoice->getTransactionId())
        );
    }
}