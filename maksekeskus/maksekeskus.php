<?php

/*
  @license@
 */

if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');
if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');


/**
 * Description of maksekeskus
 *
 * @author Matis
 */
class plgVmPaymentmaksekeskus extends vmPSPlugin {

    protected $_destination_url;
    protected $_shop_id;
    protected $_locale;
    protected $_api_secret;
    protected $_currency;
    
    private $_variablesInited = false;
    
    public function __construct(&$subject, $config) {
        parent::__construct($subject, $config);
    	JFactory::getLanguage()->load('com_virtuemart', JPATH_ADMINISTRATOR);
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush = array(
            'destination_url' => array('https://payment.maksekeskus.ee/pay/1/signed.html', 'string'),
            'shop_id' => array('', 'string'),
            'api_secret' => array('', 'string'),
            'currency' => array('', 'string'),
            'locale' => array('et', 'string'),
            'return' => array(JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived'), 'string'),
            'status_success' => array(0, 'int'),
            'status_canceled' => array(0, 'int'),
            'countries' => array('', 'string'),
        );
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    protected function getVmPluginCreateTableSQL() {
        return $this->createTableSQL('Payment MAKSEKESKUSSWtable');
    }

    function getTableSQLFields() {
        $SQLfields = array(
            'id' => 'int(11) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
            'order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'int(11) UNSIGNED DEFAULT NULL',
            'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'payment_currency' => 'char(3) ',
            'cost_per_transaction' => ' decimal(10,2) DEFAULT NULL ',
            'cost_percent_total' => ' decimal(10,2) DEFAULT NULL ',
            'tax_id' => 'int(11) DEFAULT NULL'
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
        $this->_initConfigVariables($method);
        
        $orderPaymentValues = array(
            'payment_name' => $this->renderPluginName($method),
            'order_number' => $order['details']['BT']->order_number,
            'payment_currency' => $this->_currency,
        );
        $this->storePSPluginInternalData($orderPaymentValues);
    	if (!class_exists('VirtueMartModelCurrency'))
    	    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
    	$currency = CurrencyDisplay::getInstance('', $order['details']['BT']->virtuemart_vendor_id);
        
    	$html = '<table>' . "\n";
    	$html .= $this->getHtmlRow('MAKSEKESKUS_PAYMENT_INFO', $orderPaymentValues['payment_name']);
        $html .= $this->getHtmlRow('MAKSEKESKUS_ORDER_NUMBER', $order['details']['BT']->order_number);
        $html .= $this->getHtmlRow('MAKSEKESKUS_AMOUNT', $currency->priceDisplay($order['details']['BT']->order_total));
        $html .= '</table>' . "\n";

        $bank_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order['details']['BT']->order_number);

        $paymentMessage = array(
            'shopId' => $this->_shop_id,
            'paymentId' => $bank_order_id,
            'amount' => number_format($this->_toTargetAmount($order['details']['BT']->order_total, CurrencyDisplay::getInstance($order['order_currency'])), 2, '.', ''),
        );
        $paymentMessage['signature'] = $this->_getStartSignature($paymentMessage, $this->_api_secret);


        $macFields = Array(
            'json' => json_encode($paymentMessage),
            'locale' => $this->_getPreferredLocale(),
        );

        $html.= '<form method="post" action="' . htmlspecialchars($this->_destination_url) . '">';
        foreach ($macFields as $key => $value) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '" />';
        }
        $html .= "</br>";
        $html .= '<input type="submit" value="'.JText::_("MAKSEKESKUS_SUBMIT").'"/>' ;
        $html .= '</form>';
        JRequest::setVar('html', $html);
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
        $html .= $this->getHtmlRowBE('MAKSEKESKUS_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= '</table>' . "\n";
        return $html;
    }
    
    
   function plgVmOnPaymentNotification(&$html) {
       return $this->plgVmOnPaymentResponseReceived($html);
       
   }
    
    function plgVmOnPaymentResponseReceived(&$html) {
        if (!class_exists('VirtueMartModelOrders')) {
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
        }


        $paymentParams = JRequest::get('request');
        ;


        foreach ((array) $paymentParams as $f => $v) {
            if ($f == 'json') {
                $macFields = $v;
            }
        }
        if (!$macFields) {
            return NULL;
        }
        $paymentMessage = @json_decode($macFields, true);
        if (!$paymentMessage) {
            $paymentMessage = @json_decode(htmlspecialchars_decode($paymentMessage), true);
        }
        if (!$paymentMessage || !isset($paymentMessage['signature']) || !$paymentMessage['signature']) {
            return NULL;
        }
        $virtuemart_order_id = $paymentMessage['paymentId'];
        $order = VirtueMartModelOrders::getOrder($virtuemart_order_id);
    	if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
    	    return null; // Another method was selected, do nothing
    	}
    	if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->_initConfigVariables($method);
        $paymentValidationResult = $this->validateBanklinkPayment($paymentParams);

        if ($paymentValidationResult['status'] == 'success') {
            if ($order['details']['BT']->order_status != $method->status_success) {
                $this->update_status($method->status_success, $virtuemart_order_id, "MAKSEKESKUS_PAYMENT_CONFIRMED");
            }
            echo JText::_("MAKSEKESKUS_SUCCESS_MESSAGE");
            if (!class_exists('VirtueMartCart'))
                require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
            $cart = VirtueMartCart::getCart();

            $cart->emptyCart();
            $url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=orders');
        } else if ($paymentValidationResult['status'] == 'cancelled') {
            if ($order['details']['BT']->order_status != $method->status_canceled) {
                $this->update_status($method->status_canceled, $virtuemart_order_id, "MAKSEKESKUS_PAYMENT_CANCELED");
            }
            echo JText::_("MAKSEKESKUS_FAIL_MESSAGE");
            $url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=cart');
            $this->handlePaymentUserCancel($virtuemart_order_id);
        } else if ($paymentValidationResult['status'] == 'received') {
            $url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart');
        } else {
            //total failure
            echo JText::_("MAKSEKESKUS_FAIL_MESSAGE");
            $url = JROUTE::_(JURI::root() . 'index.php');
        }
        echo '<head><meta http-equiv="Refresh" content="5;URL=' . $url . '"></head>';
        return true;


        
    }
    function update_status($status, $order_id, $text = 'MAKSEKESKUS_PAYMENT_CANCELED') {
        if ($order_id) {
            // send the email only if payment has been accepted
            if (!class_exists('VirtueMartModelOrders'))
                require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
            $modelOrder = new VirtueMartModelOrders();
            $order['order_status'] = $status;
            $order['virtuemart_order_id'] = $order_id;
            $order['customer_notified'] = 1;
            $order['comments'] = JTExt::sprintf($text, $order_id);
            $modelOrder->updateStatusForOneOrder($order_id, $order, true);
        }
    }

    function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
        return false; //set true to set payment cost to 1.00 EUR
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

    private function _toTargetAmount($input, CurrencyDisplay $currency) {
        //refactor to correct conversion
        if ($currency->getId() == $this->_currency) {
            return $input;
        }
        $input = round($currency->convertCurrencyTo($this->_currency, $input, false), 2);
        return $input;
    }

    private function _getStartSignature($paymentMessage, $apiSecret) {
        $variableOrder = array(
            'shopId',
            'paymentId',
            'amount',
        );
        $stringToHash = '';
        foreach ($variableOrder as $messagePart) {
            $stringToHash .= $paymentMessage[$messagePart];
        }
        return strtoupper(hash('sha512', $stringToHash . $apiSecret));
    }

    private function _getReturnSignature($paymentMessage, $apiSecret) {
        $variableOrder = array(
            'paymentId',
            'amount',
            'status',
        );
        $stringToHash = '';
        foreach ($variableOrder as $messagePart) {
            $stringToHash .= $paymentMessage[$messagePart];
        }
        return strtoupper(hash('sha512', $stringToHash . $apiSecret));
    }

    protected function _getPreferredLocale() {
        $defaultLocale = 'et';
        $locale = $this->_locale;
        if ($locale) {
            $localeParts = explode('_', $locale);
            if (strlen($localeParts[0]) == 2) {
                return strtolower($localeParts[0]);
            } else {
                return $defaultLocale;
            }
        }
        return $defaultLocale;
    }

    private function _initConfigVariables($method) {
        //always init return url!
        if (!$this->_variablesInited && $method && is_object($method)) {
            $this->_destination_url = $method->destination_url;
            $this->_shop_id = $method->shop_id;
            $this->_api_secret = $method->api_secret;
            //TODO: check if only one currency supplied
            $this->_currency = $method->currency;
            $this->_locale = $method->locale;
            
            $this->_variablesInited = true;
        }
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
            $address = array();
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

    private function validateBanklinkPayment($params) {

        $macFields = false;
        $result = array(
            'data' => '',
            'amount' => 0,
            'status' => 'failed',
        );
        foreach ((array) $params as $f => $v) {
            if ($f == 'json') {
                $macFields = $v;
            }
        }
        if (!$macFields) {
            return $result;
        }
        $paymentMessage = @json_decode($macFields, true);
        if (!$paymentMessage) {
            $paymentMessage = @json_decode(stripslashes($macFields), true);
        }
        if (!$paymentMessage) {
            $paymentMessage = @json_decode(htmlspecialchars_decode($paymentMessage), true);
        }
        if (!$paymentMessage || !isset($paymentMessage['signature']) || !$paymentMessage['signature']) {
            return $result;
        }
        $sentSignature = $paymentMessage['signature'];

        if (isset($paymentMessage['shopId'])) {
            $paymentFailure = $paymentMessage['shopId'] != $this->_shop_id;
        } else {
            $paymentFailure = false;
        }

        if ($this->_getReturnSignature($paymentMessage, $this->_api_secret) != $sentSignature || $paymentFailure) {
            return $result;
        } else {
            if ($paymentMessage['status'] == 'RECEIVED') {
                $result['status'] = 'received';
                $result['data'] = $paymentMessage['paymentId'];
                $result['amount'] = $paymentMessage['amount'];
            } else if ($paymentMessage['status'] == 'PAID') {
                $result['status'] = 'success';
                $result['data'] = $paymentMessage['paymentId'];
                $result['amount'] = $paymentMessage['amount'];
            } else if ($paymentMessage['status'] == 'CANCELLED') {
                $result['status'] = 'cancelled';
                $result['data'] = $paymentMessage['paymentId'];
            }
            return $result;
        }
    }

}

