<?php

/*
 * ***********************************************************************
 Copyright [2013] [PagSeguro Internet Ltda.]

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

 http://www.apache.org/licenses/LICENSE-2.0

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
 * ***********************************************************************
 */

include_once('PagSeguroLibrary/PagSeguroLibrary.php');
include_once('Defines.php');

/**
 * PagSeguro payment model
 */
class PagSeguro_PagSeguro_Model_PaymentMethod extends Mage_Payment_Model_Method_Abstract
{

	protected $_code = 'pagseguro';
	protected $_isGateway = true;
	protected $_canAuthorize = true;
	protected $_canCapture = true;
	protected $_canCapturePartial = false;
	protected $_canRefund = false;
	protected $_canVoid = true;
	protected $_canUseInternal = true;
	protected $_canUseCheckout = true;
	protected $_canUseForMultishipping = true;
	private $Module_Version = '1.4';
	private $Order;
	private $Shipping_Data;

	/**
	 * Get the shipping Data of the Order
	 *
	 * @return type
	 */
	private function getShippingData()
	{
		$isOrderVirtual = $this->Order->getIsVirtual();
		$OrderParams = NULL;
		if ($isOrderVirtual) {
			$OrderParams = $this->Order->getBillingAddress();
		} else {
			$OrderParams = $this->Order->getShippingAddress();
		}

		return $OrderParams->getData();
	}

	/**
	 * Set the Order of checkout session
	 *
	 * @param type $Order
	 */
	public function setOrder($Order)
	{
		if ($Order != NULL and !empty($Order)) {
			$this->Order = $Order;
			$this->Shipping_Data = $this->getShippingData();
		} else {
			throw new Exception("[PagSeguroModuleException] Message: Parâmetro Inválido para o método setOrder().");
		}
	}

	/**
	 * Return the created payment request html with payment url and set the PagSeguroConfig's
	 *
	 * @return PaymentRequestURL
	 */
	public function getRedirectPaymentHtml($Order) {
        $this->setPagSeguroConfig();
        $payment_url =  $this->createPaymentRequest();
        
        //limpar o cart
        $cart = Mage::getSingleton('checkout/cart');
        foreach (Mage::getSingleton('checkout/session')->getQuote()->getItemsCollection() as $item) {
           $cart->removeItem($item->getId());
        }
        $cart->save();
        
        $Order->save();
        
        return $payment_url;
        }
        
	/**
	 * Set Config's to PagSeguro API
	 *
	 */
	private function setPagSeguroConfig()
	{
		$_activeLog = $this->getConfigData('log');
		$_charset = $this->getConfigData('charset');

		Mage::getSingleton('PagSeguro_PagSeguro_Helper_Data')->saveAllStatusPagSeguro();

		//Module version
		PagSeguroLibrary::setModuleVersion('magento' . ':' . $this->Module_Version);

		//CMS version
		PagSeguroLibrary::setCMSVersion('magento' . ':' . Mage::getVersion());

		//Setup Charset
		if ($_charset != NULL and !empty($_charset)) {
			PagSeguroConfig::setApplicationCharset($_charset);
		}

		//Setup Log
		if ($_activeLog == 1) {
			$_log_file = $this->getConfigData('log_file');
			if (self::checkFile(Mage::getBaseDir() . '/' . $_log_file)) {
				PagSeguroConfig::activeLog(Mage::getBaseDir() . '/' . $_log_file);
			} else {
				PagSeguroConfig::activeLog(); //Default Log
				}
		}
	}

	/**
	 * Create PagSeguro payment request html with payment url
	 *
	 * @return string
	 */
	private function createPaymentRequest()
	{

		$PaymentRequest = new PagSeguroPaymentRequest();

		$PaymentRequest->setCurrency(PagSeguroCurrencies::getIsoCodeByName("REAL"));

		$PaymentRequest->setReference($this->Order->getId()); //Order ID

		$PaymentRequest->setShipping($this->getShippingInformation()); //Shipping
		$PaymentRequest->setSender($this->getSenderInformation()); //Sender
		$PaymentRequest->setItems($this->getItensInformation()); //Itens

		$PaymentRequest->setShippingType(SHIPPING_TYPE);
		$PaymentRequest->setShippingCost(number_format($this->Order->getShippingAmount(), 2, '.', ''));

		$PaymentRequest->setNotificationURL($this->getNotificationURL());

		//Define Redirect Url
		$redirect_url = $this->getRedirectUrl();
		if (!empty($redirect_url) and $redirect_url != NULL) {
			$PaymentRequest->setRedirectURL($redirect_url);
		} else {
			$PaymentRequest->setRedirectURL(Mage::getUrl() . 'checkout/onepage/success/');
		}

		//Define Extra Amount Information
		$PaymentRequest->setExtraAmount($this->_extraAmount());

		try {
            $payment_url = $PaymentRequest->register($this->getCredentialsInformation());
        } catch (PagSeguroServiceException $ex) {
            Mage::log($ex->getMessage());
            $this->_redirectUrl(Mage::getUrl() . 'checkout/onepage'); 
        }

        return $payment_url;
	}

	/**
	 * Extra Amount
	 * @return extra amount
	 */
	private function _extraAmount()
	{
		$_tax_amount = self::toFloat($this->Order->getTaxAmount());
		$_discount_amount = self::toFloat($this->Order->getBaseDiscountAmount());

		return PagSeguroHelper::decimalFormat($_discount_amount + $_tax_amount);
	}

	/**
	 *
	 * @return Notification URL
	 */
	private function getNotificationURL()
	{

		$notification_url = $this->getConfigData('notification');

		return ($notification_url != null && $notification_url != "") ? $notification_url : Mage::getUrl() . 'pagseguro/notification/send/';
	}
        
        private function _addressConfig($fullAddress) {
            require_once(dirname(__FILE__).'/AddressConfig.php');
            return AddressConfig::trataEndereco($fullAddress);                
        }

	/**
	 *
	 * @return PagSeguroShipping
	 */
	private function getShippingInformation()
	{
                $fullAddress = $this->_addressConfig($this->Shipping_Data['street']);
                
                $street = $fullAddress[0] != '' ? $fullAddress[0] : $this->_addressConfig($this->Shipping_Data['street']);
                $number = is_null($fullAddress[1]) ? '' : $fullAddress[1];
                $complement = is_null($fullAddress[2]) ? '' : $fullAddress[2];
                $district = is_null($fullAddress[3]) ? '' : $fullAddress[3];
                
		$PagSeguroShipping = new PagSeguroShipping();

		$PagSeguroAddress = new PagSeguroAddress();
		$PagSeguroAddress->setCity($this->Shipping_Data['city']);
		$PagSeguroAddress->setPostalCode($this->Shipping_Data['postcode']);
		$PagSeguroAddress->setState($this->Shipping_Data['region']);
		$PagSeguroAddress->setStreet($street);
                $PagSeguroAddress->setNumber($number);
                $PagSeguroAddress->setComplement($complement);
                $PagSeguroAddress->setDistrict($district);
                
		$PagSeguroShipping->setAddress($PagSeguroAddress);
                

		return $PagSeguroShipping;
	}

	/**
	 *
	 * @return PagSeguroItem
	 */
	private function getItensInformation()
	{
		$Itens = $this->Order->getAllVisibleItems();

		$PagSeguroItens = array();

		//CarShop Items
		foreach ($Itens as $item) {
			$PagSeguroItem = new PagSeguroItem();
			$PagSeguroItem->setId($item->getId());
			$PagSeguroItem->setDescription(self::fixStringLength($item->getName(), 255));
			$PagSeguroItem->setQuantity(self::toFloat($item->getQtyOrdered()));
			$PagSeguroItem->setWeight(round($item->getWeight()));
			$PagSeguroItem->setAmount(self::toFloat($item->getPrice()));

			array_push($PagSeguroItens, $PagSeguroItem);
		}

		return $PagSeguroItens;
	}

	/**
	 *
	 * @return PagSeguroAccountCredentials
	 */
	public function getCredentialsInformation()
	{
		$adm_email = $this->getConfigData('email');
		$adm_token = $this->getConfigData('token');
		$credentials = new PagSeguroAccountCredentials($adm_email, $adm_token);

		return $credentials;
	}

	/**
	 *
	 * @return PagSeguroSender
	 */
	private function getSenderInformation()
	{
		$PagSeguroSender = new PagSeguroSender();

		$PagSeguroSender->setEmail($this->Order['customer_email']);
		$PagSeguroSender->setName($this->Order['customer_firstname'] . ' ' . $this->Order['customer_lastname']);

		return $PagSeguroSender;
	}

	/**
	 * Redirect to pagseguro request controller after user click in 'PLACE ORDER'
	 *
	 */
	public function getOrderPlaceRedirectUrl()
	{
		return Mage::getUrl($this->getCode() . '/payment/request');
	}

	/*
	 * Get the redirect url configured by user
	 * @return string
	 */

	private function getRedirectUrl()
	{
		return $this->getConfigData('url');
	}

	/**
	 * Concat char's in string.
	 *
	 * @param string $_value
	 * @param int $_legth
	 * @param string $_endchars
	 * @return string
	 */
	private static function fixStringLength($_value, $_length, $_endchars = '...')
	{
		if (!empty($_value) and !empty($_length)) {

			$_cut_len = (int) $_length - (int) strlen($_endchars);

			if (strlen($_value) > $_length) {
				$str_cut = substr($_value, 0, $_cut_len);
				$_value = $str_cut . $_endchars;
			}
		}

		return $_value;
	}

	/**
	 * Convert value to float.
	 *
	 * @param $_value
	 * @return float
	 */
	private static function toFloat($_value)
	{
		return (float) $_value;
	}

	/**
	 * If file not exist, try create.
	 *
	 * @param string $filename
	 * @return boolean
	 */
	private static function checkFile($_file)
	{
		try
		{
			$f = fopen($_file, 'a');
			$_file_exist = TRUE;
			fclose($f);
		}
		catch (Exception $ex)
		{
			$_file_exist = FALSE;
			Mage::logException($ex);
		}
		return $_file_exist;
	}

}
