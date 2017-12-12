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
class Bss_Autoinvoice_Model_Observer {
	/* @var Magento_Sales_Model_Order_Invoice */
	public $_invoice;
	
    /**
     * Mage::dispatchEvent($this->_eventPrefix.'_save_after', $this->_getEventData());
     * protected $_eventPrefix = 'sales_order';
     * protected $_eventObject = 'order';
     * event: sales_order_save_after
     */
    public function autoInvoice($observer) {
		if(Mage::getStoreConfig('autoinvoice/settings/active')) {
			$order = $observer->getEvent()->getOrder();
			$orders = Mage::getModel('sales/order_invoice')->getCollection()
							->addAttributeToFilter('order_id', array('eq'=>$order->getId()));
			$orders->getSelect()->limit(1);
			if ((int)$orders->count() !== 0) {
				return $this;
			}
			if(in_array($order->getPayment()->getMethod(),explode(',',Mage::getStoreConfig('autoinvoice/settings/payment_methods'))))            {
			if ($order->getState() == Mage_Sales_Model_Order::STATE_NEW) {
				try {
					
					if(!$order->canInvoice()) {
						$order->addStatusHistoryComment('Bss AutoInvoice: Order cannot be invoiced.', false);
						$order->save();
					}
					if(Mage::getStoreConfig('autoinvoice/settings/invoice')) {
						//START Handle Invoice
						$invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
						$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
						$invoice->register();
						$invoice->getOrder()->setCustomerNoteNotify(false);
						$invoice->getOrder()->setIsInProcess(true);
						$order->addStatusHistoryComment('Automatically Invoiced by Bss AutoInvoice.', false);
						$transactionSave = Mage::getModel('core/resource_transaction')
							->addObject($invoice)
							->addObject($invoice->getOrder());
						$transactionSave->save();
						//END Handle Invoice
						 //START Handle Shipment
						if(Mage::getStoreConfig('autoinvoice/settings/shipment')) {
							$shipment = $order->prepareShipment();
							$shipment->register();
							$order->setIsInProcess(true);
							$order->addStatusHistoryComment('Automatically Shipped by Bss Autoinvoice.', false);
							$transactionSave = Mage::getModel('core/resource_transaction')
								->addObject($shipment)
								->addObject($shipment->getOrder())
								->save();
						 }
						//END Handle Shipment
					}
	
				} catch (Exception $e) {
					$order->addStatusHistoryComment('Bss AutoInvoice: Exception occurred during automaticallyInvoiceShipCompleteOrder action. Exception message: '.$e->getMessage(), false);
					$order->save();
				}
			}
			}
		return $this;
		}
    }
	
	public function automaticallyInvoiceShipCompleteOrder($observer) {
	   try {
		  /* @var $order Magento_Sales_Model_Order_Invoice */
		  $this->_invoice = $observer->getEvent()->getInvoice();
		  //Mage::log('automatically send invoice'.$this->_invoice, null, 'bsslog1.log');
		  $this->_invoice->sendEmail();
		
	   } catch (Mage_Core_Exception $e) {
		   Mage::log("automatically send invoice: Fehler #58 " . $e->getMessage());
	   }

	   return $this;
	}
	
}