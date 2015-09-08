<?php
/**
	Käesoleva loomingu autoriõigused kuuluvad Revo Rästale ja Aktsiamaailm OÜ-le
	Litsentsitingimused on saadaval http://www.e-abi.ee/litsents
	@version 1.0
*/
if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');
if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
class plgVmPaymentnordea extends vmPSPlugin {
    // instance of class
    public static $_this = false;
    var $bank_down='nor';
    var $bank_UPPER='NOR';
    function __construct(& $subject, $config) {
	   parent::__construct($subject, $config);
	    $this->_loggable = true;
	    $this->tableFields = array_keys($this->getTableSQLFields());
	    $varsToPush = array('payment_currency' =>  array(0, 'int'),
        'status_success' => array(0,"int"),
        'status_canceled' => array(0,"int"),
        'payment_logos'          => array('', 'char'),
		'priv_key' => array('', 'text'),
		'pub_key' => array('', 'text'),
		'VK_SND_ID' => array('', 'string'),
		'priv_pass' => array('', 'string'),
        'countries' => array('', 'string'),
		'keyvar' => array(0,'int'),
		'nlang'=>array(0,'string'),
        'return' =>array('','text'),
        'cancel' =>array('',"text"),
        'url' =>array('','text')
	    );
	    $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }
    protected function getVmPluginCreateTableSQL() {
	   return $this->createTableSQL('Payment Nordea table');
    }
    function getTableSQLFields() {
    	    $SQLfields = array(
    	    'id' => 'int(11) unsigned NOT NULL AUTO_INCREMENT',
    	    'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
    	    'order_number' => 'char(32) DEFAULT NULL',
    	    'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
    	    'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
    	    'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
    	    'payment_currency' => 'char(3) ',
    	    'cost_per_transaction' => ' decimal(10,2) DEFAULT NULL ',
    	    'cost_percent_total' => ' decimal(10,2) DEFAULT NULL ',
    	    'tax_id' => 'smallint(11) DEFAULT NULL'
    	);
    	return $SQLfields;
    }
    function plgVmConfirmedOrder($cart, $order) {
    	if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
    	    return null;
    	}
    	if (!$this->selectedThisElement($method->payment_element)) {
    	    return false;
    	}
        $cart->_dataValidated="1";
    	$lang = JFactory::getLanguage();
    	$filename = 'com_virtuemart';
    	$lang->load($filename, JPATH_ADMINISTRATOR);
    	$vendorId = 0;
    	$html = "";
    	if (!class_exists('VirtueMartModelOrders'))
    	    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
    	$this->getPaymentCurrency($method);
   	    $q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
    	$db = &JFactory::getDBO();
    	$db->setQuery($q);
    	$currency_code_3 = $db->loadResult();
    	$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
    	$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
    	$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);
    	$this->_virtuemart_paymentmethod_id = $order['details']['BT']->virtuemart_paymentmethod_id;
    	$dbValues['payment_name'] = $this->renderPluginName($method);
    	$dbValues['order_number'] = $order['details']['BT']->order_number;
    	$dbValues['VK_SND_ID'] = $method->VK_SND_ID;
    	$dbValues['payment_currency'] = $method->payment_currency;
    	$dbValues['pub_key'] = $method->pub_key;
    	$dbValues['keyvar']=$method->keyvar;
    	$dbValuyes['nlang']=$method->nlang;
        $dbValues['url']=$method->url;
        $dbValues['return']=$method->return;
    	$this->storePSPluginInternalData($dbValues);
    	$html = '<table>' . "\n";
    	$html .= $this->getHtmlRow('NORDEA_PAYMENT_INFO', $dbValues['payment_name']);
    	if (!empty($payment_info)) {
    	    $lang = & JFactory::getLanguage();
    	   if ($lang->hasKey($method->payment_info)) {
    	       $payment_info = JTExt::_($method->payment_info);
    	   } else {
    	        $payment_info =  $method->payment_info;
    	   }
    	    $html .= $this->getHtmlRow($bank_UPPER._PAYMENTINFO, $payment_info);
    	}
    	//currency
    	$db = JFactory::getDBO();
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
    	
    	//currency
    	if (!class_exists('VirtueMartModelCurrency'))
    	    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
    	$currency = CurrencyDisplay::getInstance('', $order['details']['BT']->virtuemart_vendor_id);
    	$html .= $this->getHtmlRow('NORDEA_ORDER_NUMBER', $order['details']['BT']->order_number);
    	$html .= $this->getHtmlRow('NORDEA_AMOUNT', $currency->priceDisplay($order['details']['BT']->order_total));
    	$html .= '</table>' . "\n";
        $paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
    	$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total,false), 2);
        $bank_order_id= VirtueMartModelOrders::getOrderIdByOrderNumber($order['details']['BT']->order_number);
        $VK_a['SOLOPMT_VERSION'] = '0003';
        $VK_a['SOLOPMT_STAMP'] = hexdec($bank_order_id);
	$VK_a['SOLOPMT_RCV_ID']=$method->VK_SND_ID;
	$VK_a['SOLOPMT_LANGUAGE']=$method->nlang;
	$VK_a['SOLOPMT_AMOUNT']=$totalInPaymentCurrency;
	$VK_a['SOLOPMT_REF']=$this->generateRefNum($bank_order_id);
	$VK_a['SOLOPMT_DATE']="EXPRESS";
	$VK_a['SOLOPMT_MSG']="Arve ".$bank_order_id." tasumine";
	$VK_a['SOLOPMT_RETURN']=JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived');
	$VK_a['SOLOPMT_CANCEL']=$VK_a['SOLOPMT_RETURN'];
	$VK_a['SOLOPMT_REJECT']=$VK_a['SOLOPMT_RETURN'];
	$VK_a['SOLOPMT_CONFIRM']="YES";
	$VK_a['SOLOPMT_KEYVERS']=$method->keyvar;
	$VK_a['SOLOPMT_CUR']=$currency_code_3;
	$VK_a['SOLOPMT_MAC']=$this->getSoloStartMac($VK_a,$method->keyvar,$method->priv_key);
        $html.=( '<form method="post" action="'.$dbValues['url'].'">' );
        foreach( $VK_a as $VK_name => $VK_value ){
           $html.=( '<input type="hidden" name="' . htmlspecialchars($VK_name) . '" value="' . htmlspecialchars($VK_value) . '"/>'."\n"."\t" );
        }
        $html.=("</br>");
        $html.= ('<input type="submit" value="'.JText::_("VMPAYMENT_NORDEA_SUBMIT").'"/>' );
        $html.=( '</form>' );
        		JRequest::setVar ('html', $html);

//    	return $this->processConfirmedOrderPaymentResponse(true, $cart, $order, $html, , $method->status_canceled);
    }
    	function getSoloStartMac($params,$keyvar,$mac) {
		$variableOrder = array(
			'SOLOPMT_VERSION', 'SOLOPMT_STAMP', 'SOLOPMT_RCV_ID',
			'SOLOPMT_AMOUNT', 'SOLOPMT_REF', 'SOLOPMT_DATE',
			'SOLOPMT_CUR'
		);
		$res = '';
		foreach ($variableOrder as $i) {
			if (isset($params[$i])) {
				$res .= $params[$i]."&";
			}
		}
		$res .= $mac."&";
		if ($keyvar == '0001') {
			$res = md5($res);
		} else {
			$res = sha1($res);
		}
		return strtoupper($res);
	}

    
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) {
   	if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
    	    return null; // Another method was selected, do nothing
    	}
    	$db = JFactory::getDBO();
    	$q = 'SELECT * FROM `' . $this->_tablename . '` '
    		. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
    	$db->setQuery($q);
    	if (!($paymentTable = $db->loadObject())) {
    	    vmWarn(500, $q . " " . $db->getErrorMsg());
    	    return '';
    	}
    	$this->getPaymentCurrency($paymentTable);
    	$html = '<table class="adminlist">' . "\n";
    	$html .=$this->getHtmlHeaderBE();
    	$html .= $this->getHtmlRowBE('NORDEA_PAYMENT_NAME', $paymentTable->payment_name);
    	$html .= '</table>' . "\n";
    	return $html;
    }
    function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
    	return false;//set true to set payment cost to 1.00 EUR
    }
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
	   return $this->onStoreInstallPluginTable($jplugin_id);
    }
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
	   return $this->OnSelectCheck($cart);
    }
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
	   return $this->displayListFE($cart, $selected, $htmlIn);
    }
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
	   return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }
    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
    	if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
    	    return null; // Another method was selected, do nothing
    	}
    	if (!$this->selectedThisElement($method->payment_element)) {
    	    return false;
    	}
    	 $this->getPaymentCurrency($method);
    	$paymentCurrencyId = $method->payment_currency;
    }
    function plgVmonShowOrderPrintPayment($order_number, $method_id) {
	   return $this->onShowOrderPrint($order_number, $method_id);
    }
    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
	   return $this->declarePluginParams('payment', $name, $id, $data);
    }
    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
	   return $this->setOnTablePluginParams($name, $id, $table);
    }
   function plgVmOnPaymentNotification(&$html) {
       return plgVmOnPaymentResponseReceived($html);
    }
    
    function plgVmOnPaymentResponseReceived(&$html) {
        //language
        
        if (!class_exists('VirtueMartCart'))
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
       	if (!class_exists('VirtueMartModelOrders'))
    	    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );    
        $lang = JFactory::getLanguage();
    	$filename = 'com_virtuemart';
    	$lang->load($filename, JPATH_ADMINISTRATOR);
    	$vendorId = 0;
        $payment_data = JRequest::get('request');
        $order_id = substr($payment_data['SOLOPMT_RETURN_REF'], 0, -1);
        $pmethod=VirtueMartModelOrders::getOrder($order_id);
        $payment_method= $pmethod['details']['BT']->virtuemart_paymentmethod_id;
    	if (!($method = $this->getVmPluginMethod($payment_method))) {
    	    return null; // Another method was selected, do nothing
    	}
    	if (!$this->selectedThisElement($method->payment_element)) {
    	    return false;
    	 
    	}
    	$payment_name = $this->renderPluginName($method);
        
    	$vk_array=array();
        foreach ($payment_data as $key => $value) {
            if (substr($key, 0, 8) == "SOLOPMT_") {
                $vk_array[$key]=$value;
            }
        }
        $sig_result = $this->checkReturnMac($vk_array,$method->priv_key,$method->keyvar);
            $config =& JFactory::getConfig();
            $dbprefix =   $config->getValue( 'dbprefix' );
             $order= new VirtueMartModelOrders();
             $order=$order->getOrder($order_id);
             $cart = VirtueMartCart::getCart();
            
             //Currency
             	$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $order_id;
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
		   // JError::raiseWarning(500, $db->getErrorMsg());
		    return '';
		}
		$this->getPaymentCurrency($paymentTable);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $paymentTable->payment_currency . '" ';
		$db = &JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
             
             //currency
             
            if($sig_result==true){
            $html .= $this->_getPaymentResponseHtml($payment_data,$payment_name,round($order['details']['BT']->order_total,2),$currency_code_3);
           
                         if($order['details']['BT']->order_status!=$method->status_success){
                            $this->update_status($method->status_success,$order_id,"VMPAYMENT_NORDEA_PAYMENT_CONFIRMED");
                        }
                        echo JText::_("VMPAYMENT_NORDEA_SUCCESS_MESSAGE");
                  echo $html;
            }elseif($sig_result==false){// makse on tühistatud
                if($order['details']['BT']->order_status!=$method->status_canceled){
            	   $this->update_status($method->status_canceled,$order_id,"VMPAYMENT_NORDEA_PAYMENT_CANCELED");
                }
                echo JText::_("VMPAYMENT_NORDEA_FAIL_MESSAGE");
            }
            $cart->emptyCart();
            $url=JROUTE::_(JURI::root() .'index.php?option=com_virtuemart&view=orders');
            echo '<head><meta http-equiv="Refresh" content="5;URL='.$url.'"></head>';
            return true;
   }
    protected function checkConditions($cart, $method, $cart_prices) {
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address                          = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
            return true;
        }

        return false;
    }
    function update_status($status,$order_id,$text='VMPAYMENT_NORDEA_PAYMENT_CANCELED'){
        if ($order_id) {
	       // send the email only if payment has been accepted
	       if (!class_exists('VirtueMartModelOrders'))
		      require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
   	        $modelOrder = new VirtueMartModelOrders();
       	    $order['order_status'] = $status;
       	    $order['virtuemart_order_id'] = $order_id;
       	    $order['customer_notified'] =1;
   	        $order['comments'] = JTExt::sprintf($text, $order_id);
       	    $modelOrder->updateStatusForOneOrder($order_id, $order, true);
     	   }
    }
    function _getPaymentResponseHtml($bank_data, $payment_name,$amount,$cur) {
       	$html = '<table>' . "\n";
    	$html .= $this->getHtmlRow(NORDEA_PAYMENT_NAME, $payment_name);
        $stamp=dechex($bank_data['SOLOPMT_RETURN_STAMP']);//payment method number
    	$html .= $this->getHtmlRow(NORDEA_ORDER_NUMBER, $stamp);
    	$html .= $this->getHtmlRow(NORDEA_AMOUNT, $amount . " " . $cur);
    	$html .= '</table>' . "\n";
    	return $html;
    }
    function checkReturnMac($params,$mac,$keyvar) {
		$variableOrder = array(
			'SOLOPMT_RETURN_VERSION', 'SOLOPMT_RETURN_STAMP',
			'SOLOPMT_RETURN_REF', 'SOLOPMT_RETURN_PAID'
		);
		$res = '';
		foreach ($variableOrder as $i) {
			if (isset($params[$i])) {
				$res .= $params[$i]."&";
			} else {
				$res .= "&";
			}
		}
		$res .= $mac."&";
		if ($keyvar == '0001') {
			$res = md5($res);
		} else {
			$res = sha1($res);
		}
		if (isset($params['SOLOPMT_RETURN_MAC']) && strtoupper($res) == $params['SOLOPMT_RETURN_MAC']) {
			return true;
		} else {
			return false;
		}
	}
	function generateRefNum($stamp) {
		$chcs = array(7, 3, 1);
		$sum = 0;
		$pos = 0;
		for ($i = 0; $i < strlen($stamp); $i++) {
			$x = (int)(substr($stamp,strlen($stamp) - 1 - $i, 1));
			$sum = $sum + ($x * $chcs[$pos]);
			if ($pos == 2) {
				$pos = 0;
			} else {
				$pos = $pos + 1;
			}
		}
		$x = 10 - ($sum % 10);
		if ($x != 10) {
			$sum = $x;
		} else {
			$sum = 0;
		}
		return $stamp . $sum;
	}
}
