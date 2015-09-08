<?php /**
 * Käesoleva loomingu autoriõigused kuuluvad Revo Rästale ja Aktsiamaailm OÜ-le
 * Litsentsitingimused on saadaval http://www.e-abi.ee/litsents
 * @version 1.0
 */
if (!defined('_JEXEC'))
       die('Direct Access to '.basename(__file__).' is not allowed.');
if (!class_exists('vmPSPlugin'))
       require (JPATH_VM_PLUGINS.DS.'vmpsplugin.php');
class plgVmShipmentSmartpost extends vmPSPlugin {
       // instance of class
       public static $_this = false;
       function __construct(&$subject, $config) {
              parent::__construct($subject, $config);
              $this->_loggable = true;
              $this->tableFields = array_keys($this->getTableSQLFields());
              $varsToPush = array(
                     'Client_Title' => array('', 'char'),
                     'Short_Names' => array('', 'int'),
                     'Free_Shipping' => array('', 'int'),
                     'Free_From' => array('', 'char'),
                     'Only_Estonia' => array('', 'int'),
                     'small' => array('', 'char'),
                     'medium' => array('', 'char'),
                     'large' => array('', 'char'),
                     'extra_large' => array('', 'char'),
                     'calc_shipping' => array('', 'int'),
                     'tax_id' => array('', 'int'));
              $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
       }
       protected function getVmPluginCreateTableSQL() {
              return $this->createTableSQL('Table for Itella SmartPOST');
       }
       function getTableSQLFields() {
              $SQLfields = array(
                     'id' => ' int(11) unsigned NOT NULL AUTO_INCREMENT',
                     'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
                     'order_number' => 'char(32) DEFAULT NULL',
                     'virtuemart_shipmentmethod_id' => 'int(11) UNSIGNED DEFAULT NULL',
                     'shipment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
                     'selected_parcel' => 'int UNSIGNED DEFAULT NULL',
                     'phone' => 'int(11) UNSIGNED DEFAULT NULL',
                     'shipment_package_fee' => 'decimal(10,2) DEFAULT NULL',
                     'tax_id' => 'int(11) DEFAULT NULL');
              return $SQLfields;
       }
       public function plgVmOnShowOrderFEShipment($virtuemart_order_id, $virtuemart_shipmentmethod_id,
              &$shipment_name) {
              $this->onShowOrderFE($virtuemart_order_id, $virtuemart_shipmentmethod_id, $shipment_name);
       }
       function plgVmConfirmedOrder(VirtueMartCart $cart, $order) {
              if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_shipmentmethod_id)))
                     return null; // Another method was selected, do nothing
              $shipping_id = $order['details']['BT']->virtuemart_shipmentmethod_id;
              if (!$this->selectedThisElement($method->shipment_element))
                     return false;
              $values['order_number'] = $order['details']['BT']->order_number;
              $values['virtuemart_shipment_id'] = $order['details']['BT']->virtuemart_shipmentmethod_id;
              $values['virtuemart_shipmentmethod_id'] = $shipping_id;
              $values['shipment_name'] = $this->renderPluginName($method);
              $values['selected_parcel'] = $_SESSION['smartpost_selected_parcel'];
              if (isset($order['details']['BT']->phone_1)) {
                     $phone = $order['details']['BT']->phone_1;
              } else {
                     $phone = $order['details']['BT']->phone_2;
              }
              $values['phone'] = $phone;
              $values['shipment_package_fee'] = $method->package_fee;
              $values['tax_id'] = $method->tax_id;
              unset($_SESSION['smartpost_selected_parcel']);
              $this->storePSPluginInternalData($values);
              return true;
       }
       public function plgVmOnShowOrderBEShipment($virtuemart_order_id, $virtuemart_shipmentmethod_id) {
              if (!($this->selectedThisByMethodId($virtuemart_shipmentmethod_id))) {
                     return null;
              }
              $html = $this->getOrderShipmentHtml($virtuemart_order_id);
              return $html;
       }
       function getOrderShipmentHtml($virtuemart_order_id) {
              $db = JFactory::getDBO();
              $q = 'SELECT * FROM `'.$this->_tablename.'` '.'WHERE `virtuemart_order_id` = '.
                     $virtuemart_order_id;
              $db->setQuery($q);
              if (!($method = $db->loadObject())) {
                     vmWarn(500, $q." ".$db->getErrorMsg());
                     return '';
              }
              if (!class_exists('CurrencyDisplay'))
                     require (JPATH_VM_ADMINISTRATOR.DS.'helpers'.DS.'currencydisplay.php');
              $currency = CurrencyDisplay::getInstance();
              $tax = ShopFunctions::getTaxByID($method->tax_id);
              $taxDisplay = is_array($tax)?$tax['calc_value'].' '.$tax['calc_value_mathop']:$method->tax_id;
              $taxDisplay = ($taxDisplay == -1)?JText::_('COM_VIRTUEMART_PRODUCT_TAX_NONE'):$taxDisplay;
              //geting selected parcel
              $parcels = $this->_quote();
              $title = $parcels[$method->selected_parcel]['title'];
              $html = '<table class="adminlist">'."\n";
              $html .= $this->getHtmlHeaderBE();
              $html .= $this->getHtmlRowBE('SMARTPOST_SM', 'Itella SmartPOST');
              $html .= $this->getHtmlRowBE('SMARTPOST_SELECTED_PARCEL', $title);
              $html .= $this->getHtmlRowBE('SMARTPOST_TOPHONE', $method->phone);
              $html .= '</table>'."\n";
              return $html;
       }
       public static $productSizes;
       public function getProductSizes($cart) {
              if (self::$productSizes == null) {
                     $cartProducts = $cart->products;
                     self::$productSizes = array();
                     //$this->debug($cart);
                     foreach ($cartProducts as $product) {
                            self::$productSizes[] = array(
                                   'dimensions' => array(
                                          'w' => $product->product_width,
                                          'h' => $product->product_height,
                                          'l' => $product->product_length,
                                          'q' => $product->quantity,
                                          ),
                                   'unit' => $product->product_lwh_uom,
                                   );
                     }
              }
              return self::$productSizes;
       }
       function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
              //getting method value for shipping cost calculation
              $prodSizes = $this->getProductSizes($cart);
              $shippingCosts = array();
              foreach ($prodSizes as $prodSize) {
                     $size = array(
                            $prodSize['dimensions']['w'],
                            $prodSize['dimensions']['h'],
                            $prodSize['dimensions']['l']);
                     sort($size);
                     //swap the sizes
                     if ($prodSize['unit'] == "M") {
                            $ex = '100';
                     } elseif ($prodSize['unit'] == 'CM') {
                            $ex = '1';
                     } elseif ($prodSize['unit'] == 'MM') {
                            $ex = '0.1';
                     }
                     if ($method->calc_shipping == 1) {
                            if ($size[0] * $ex <= 12  && $size[1] * $ex <= 36) {
                                   $shippingCosts[] = $method->small * $prodSize['dimensions']['q'];
                            } elseif ($size[0] * $ex <= 20  && $size[1] * $ex <= 36) {
                                    $shippingCosts[] = $method->medium * $prodSize['dimensions']['q'];
                            } elseif ($size[0] * $ex <= 36  && $size[1] * $ex <= 38) {
                                    $shippingCosts[] = $method->large * $prodSize['dimensions']['q'];
                            } elseif ($size[0] * $ex <= 36  && $size[1] * $ex <= 60) {
                                   $shippingCosts[] = $method->extra_large * $prodSize['dimensions']['q'];
                            }
                     } else {
                            if ($size[0] * $ex <= 12  && $size[1] * $ex <= 36) {
                                   $shippingCosts[] = $method->small;
                            } elseif ($size[0] * $ex <= 20  && $size[1] * $ex <= 36) {
                                    $shippingCosts[] = $method->medium;
                            } elseif ($size[0] * $ex <= 36  && $size[1] * $ex <= 38) {
                                    $shippingCosts[] = $method->large;
                            } elseif ($size[0] * $ex <= 36  && $size[1] * $ex <= 60) {
                                    $shippingCosts[] = $method->extra_large;
                            }
                     }
              }
              $shippingCost = 0;
              //iterator completed
              if ($method->calc_shipping == 1 && !empty($shippingCosts)) {
                     $shippingCost = array_sum($shippingCosts);
              } elseif ($method->calc_shipping == 0 && !empty($shippingCosts)) {
                     $shippingCost = max($shippingCosts);
              }
              if ($method->Free_Shipping == 1 && $cart_prices['salesPrice'] >= $method->Free_From) {
                     return 0;
              } else {
                     return ($shippingCost);
              }
       }
       protected function checkConditions($cart, $method, $cart_prices) {
              $address = (($cart->ST == 0)?$cart->BT:$cart->ST);
              $nbShipment = 0;
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
                     $address['zip'] = 0;
                     $address['virtuemart_country_id'] = 0;
              }
              if (isset($address['zip'])) {
                     $zip_cond = $this->_zipCond($address['zip'], $method);
              } else {
                     $zip_cond = true;
              }
              if (!isset($address['virtuemart_country_id']))
                     $address['virtuemart_country_id'] = 0;
              if (in_array($address['virtuemart_country_id'], $countries) || count($countries) ==
                     0) {
                     if ($zip_cond) {
                            return true;
                     }
              }
              //For Itella SmartPOST
              $prodSizes = $this->getProductSizes($cart);
              if (!is_array($prodSizes) || !$this->_isShippable($prodSizes)) {
				return false;
              }
              return false;
       }
       private function _zipCond($zip, $method) {
              if (!empty($zip)) {
                     $zip_cond = (($zip >= $method->zip_start and $zip <= $method->zip_stop) or ($method->zip_start <=
                            $zip and ($method->zip_stop == 0)));
              } else {
                     $zip_cond = true;
              }
              return $zip_cond;
       }
       private function _isShippable($productSize) {
              $size = array(
                     $productSize['dimensions']['w'],
                     $productSize['dimensions']['h'],
                     $productSize['dimensions']['l']);
              sort($size);
              $ex = 0;
				 if ($productSize['unit'] == "M") {
						$ex = '100';
				 } elseif ($productSize['unit'] == 'CM') {
						$ex = '1';
				 } elseif ($productSize['unit'] == 'MM') {
						$ex = '0.1';
				 }
              if (max($size) * $ex > 60 || $size[0] * $ex > 36)
                     return false;
              return true;
       }
       function plgVmOnStoreInstallShipmentPluginTable($jplugin_id) {
              return $this->onStoreInstallPluginTable($jplugin_id);
       }
       /**
        * Meetodit kasutatakse siis, kui smartpost moodul on valitud.
        * 
        */
       public function plgVmOnSelectCheckShipment(VirtueMartCart $cart) {
              //get smartpost id
              $smart_id = $_REQUEST['virtuemart_shipmentmethod_id'];
              if (!$method = $this->getVmPluginMethod($smart_id)) {
                     unset($_SESSION['smartpost_selected_parcel']);
                     return null; // Another method was selected, do nothing
              }
              if (!$this->selectedThisElement($method->shipment_element)) {
                     unset($_SESSION['smartpost_selected_parcel']); //unset selected parcel because another method was selected.
                     return true;
              }
              //for no errors let's disable estonian smartpost
              $selected_parcel = $_REQUEST['srates'];
              if ($selected_parcel == 0 && $this->selectedThisElement($method->shipment_element) ==
                     1) {
                     JError::raiseWarning(100, "Please select Itella SmartPOST parcel");
                     unset($_SESSION['smartpost_selected_parcel']);
              }
              $_SESSION['smartpost_selected_parcel'] = $selected_parcel;
              return $this->OnSelectCheck($cart);
       }
       public function control_size($cart) {
              $products_Sizes = $this->getProductSizes($cart);
              foreach ($products_Sizes as $sizes) {
                     $size = array(
                            $sizes['dimensions']['w'],
                            $sizes['dimensions']['h'],
                            $sizes['dimensions']['l']);
                     sort($size);
                     
				  if (is_array($size)) {
						 if (max($size) > 60) {
								return false;
						 }
				  }
                     
              }
			  return true;
       }
       public function plgVmDisplayListFEShipment(VirtueMartCart $cart, $selected = 0,
              &$htmlIn) {
              //get Itella SmartPOST id
              $smart_id = $this->get_smart_id();
              if (!($method = $this->getVmPluginMethod($smart_id))) {
                     return null; // Another method was selected, do nothing
              }
              if (!$this->selectedThisElement($method->shipment_element) || $method->published ==
                     0) {
                     return null;
              }
              if (!$this->control_size($cart)) {
                     return null;
              }
              if (!class_exists('CurrencyDisplay'))
                     require (JPATH_VM_ADMINISTRATOR.DS.'helpers'.DS.'currencydisplay.php');
              //if shipTo is not int then take billTo
              if (!is_int($cart->lists['shipTo'])) {
                     $ship_to_info_id = $cart->lists['billTo'];
              } else {
                     $ship_to_info_id = $cart->lists['shipTo'];
              }
              if ($ship_to_info_id == 0) { //unregistered client
                     if ($cart->ST == 0) {
                            $country_id = $cart->BT['virtuemart_country_id'];
                     } else { //BT is null
                            $country_id = $cart->ST['virtuemart_country_id'];
                     }
                     $dbu = JFactory::getDBO();
                     $q = "SELECT country_3_code FROM #__virtuemart_countries as b WHERE b.virtuemart_country_id='".
                            $country_id."' LIMIT 1";
                     $dbu->setQuery($q);
                     $result = $dbu->loadResult();
                     $country_3_code = $result;
              } else { //registered client
                     $currency = CurrencyDisplay::getInstance();
                     $dbu = JFactory::getDBO();
                     $q = "SELECT country_3_code FROM #__virtuemart_userinfos as a, #__virtuemart_countries as b WHERE virtuemart_userinfo_id = '".
                            $ship_to_info_id."' AND b.virtuemart_country_id=a.virtuemart_country_id LIMIT 1";
                     $dbu->setQuery($q);
                     $result = $dbu->loadResult();
                     $country_3_code = $result;
              }
              if ($_SESSION['auth']['show_price_including_tax'] != 1) {
                     $taxrate = 1;
                     $order_total = $total + $tax_total;
              } else {
                     $taxrate = $this->get_tax_rate() + 1;
                     $order_total = $total;
              }
              $currency = CurrencyDisplay::getInstance();
              if ($country_3_code != "EST" && $method->Only_Estonia == 1) {
                     return null;
              }
              if ($method->Free_Shipping == 1 && $method->Free_From >= 0) {
                     //handle free shipping
                     $freeLimit = $currency->convertCurrencyTo($cart->pricesCurrency, $method->Free_From, true);
                     if ($order_total >= $freeLimit) {
                            $Handling_Fee = 0;
                     } else {
                            $Handling_Fee = $this->getCosts($cart, $method, $cart->prices);
                     }
              } else {
                     $Handling_Fee = $this->getCosts($cart, $method, $cart->prices);
              }
              $rates = $this->_quote();
              $formattedRates = array();
              $sRates = array();
              $formattedRates["0"] = '--'.JText::_('VMSHIPMENT_SMARTPOST_SELECT_PARCEL').'--';
              foreach ($rates as $rate) {
                     $formattedRates[$rate['id']] = $rate['title'];
              }
              if ($cart->virtuemart_shipmentmethod_id != $smart_id) {
                     $html .= '<input type="radio" name="virtuemart_shipmentmethod_id" id="shipment_id_'.
                            $smart_id.'" value="'.$smart_id.'">';
              } else {
                     $html .= '<input type="radio" name="virtuemart_shipmentmethod_id" id="shipment_id_'.
                            $smart_id.'" value="'.$smart_id.'" checked="">';
              }
              
              
				$methodSalesPrice = $this->calculateSalesPrice($cart, $method, $cart->pricesUnformatted);
				$Handling_Fee = $methodSalesPrice;
              
              $html .= '<label for="shipment_id_'.$smart_id.'"><span class="vmshipment">';
              $html .= '<span class="vmshipment_name">'.$method->Client_Title.'</span>';
              $html .= '<span class="vmshipment_description">';
              $html .= $this->getSelect('srates', $formattedRates, $_SESSION['smartpost_selected_parcel'],
                     "style='width: 200px;' id='smpselect' onChange='jQuery(\"#shipment_id_".$smart_id.
                     "\").attr(\"checked\",\"\");' onClick='jQuery(\"#shipment_id_".$smart_id."\").attr(\"checked\",\"\"); return false;'");
              $html .= '</span>';
              $html .= '<span class="vmshipment_cost">('.JText::_('VMSHIPMENT_SMARTPOST_FEE').
                     number_format($Handling_Fee, 2, ",", "").' €)</span></span></label>';
              $htmlIn[] = array($html);
              return true;
       }
       function getSelect($name, $values, $selected, $d = '') {
              $str = '';
              $str .= '<select name="'.$name.'" '.$d.'">'."\r\n";
              foreach ($values as $k => $v) {
                     if ($selected == $k) {
                            $str .= "<option value='$k' selected='selected'>$v</option>\r\n";
                     } else {
                            $str .= "<option value='$k'>$v</option>\r\n";
                     }
              }
              $str .= '</select>'."\r\n";
              return $str;
       }
       public function plgVmOnCheckoutCheckData($psType, VirtueMartCart $cart) {
              $smart_id = $this->get_smart_id();
              if (!$method = $this->getVmPluginMethod($smart_id)) {
                     return null; // Another method was selected, do nothing
              }
              if (!$this->selectedThisElement($method->shipment_element)) {
                     return null;
              }
              if (!$this->control_size($cart)) {
                     return null;
              } else {
                     return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
              }
       }
       public function plgVmonSelectedCalculatePriceShipment(VirtueMartCart $cart,
              array & $cart_prices, &$cart_prices_name) {
              $smart_id = $this->get_smart_id();
              if (!$method = $this->getVmPluginMethod($smart_id)) {
                     return null; // Another method was selected, do nothing
              }
              if (!$this->selectedThisElement($method->shipment_element)) {
                     return null;
              } else {
                     return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
              }
       }
       function plgVmOnCheckAutomaticSelectedShipment(VirtueMartCart $cart, array $cart_prices =
              array()) {
              return 0;
       }
       function plgVmonShowOrderPrint($order_number, $method_id) {
              return $this->onShowOrderPrint($order_number, $method_id);
       }
       function plgVmDeclarePluginParamsShipment($name, $id, &$data) {
              return $this->declarePluginParams('shipment', $name, $id, $data);
       }
       function plgVmSetOnTablePluginParamsShipment($name, $id, &$table) {
              return $this->setOnTablePluginParams($name, $id, $table);
       }
              function _quote($city = '', $state = '') {
              $smart_id = $this->get_smart_id();
              if (!($method = $this->getVmPluginMethod($smart_id))) {
                     return null; // Another method was selected, do nothing
              }
              if ($city === null)
                     $city = '';
              if ($state === null)
                     $state = '';
              $city = htmlentities($city, ENT_COMPAT, "UTF-8");
              $state = htmlentities($state, ENT_COMPAT, "UTF-8");
              if (file_exists("smpostcache.txt") && (time() - filemtime("smpostcache.txt")) <
                     86400) {
                     $body = file_get_contents("smpostcache.txt");
              } else {
                     $body = file_get_contents("http://www.smartpost.ee/places.php");
                     if (is_writable("smpostcache.txt") || is_writable(getcwd())) {
                            file_put_contents("smpostcache.txt", $body);
                     }
              }
              $response = unserialize($body);
              $result = array();
              foreach ($response as $r) {
                     //      var_dump($r);
                     if ($r['active'] == 1) {
                            if (($city == '' && $state == '') || (($city != '' && $state != '') && (stripos
                                   (htmlentities($r['name'], ENT_COMPAT, "UTF-8"), $city) !== false || stripos(htmlentities
                                   ($r['group_name'], ENT_COMPAT, "UTF-8"), $state) !== false))) {
                                   if ($method->Short_Names == 0) {
                                          $result[$r['place_id']] = array(
                                                 'id' => $r['place_id'],
                                                 'title' => htmlentities($r['name'], ENT_COMPAT, "UTF-8"),
                                                 'group_name' => $r['group_name'],
                                                 'desc' => $r['name'],
                                                 );
                                   } else {
                                          $result[$r['place_id']] = array(
                                                 'id' => $r['place_id'],
                                                 'title' => htmlentities($r['name'].", ".$r['description'].", ".$r['opened'].
                                                        " (".$r['address']." ".$r['city'].", ".$r['group_name'].")", ENT_COMPAT, "UTF-8"),
                                                 'group_name' => $r['group_name'],
                                                 'desc' => $r['name'],
                                                 );
                                   }
                            }
                     }
              }
              //sort the results
              if ($method->Sort_Names == 1) {
                     uasort($result, array('plgVmShipmentSmartpost', 'sort'));
              }
              return $result;
       }
       public function get_smart_id() {
              $database = &JFactory::getDBO();
              $qs = "SELECT virtuemart_shipmentmethod_id as smartid FROM #__virtuemart_shipmentmethods WHERE shipment_element='smartpost' LIMIT 1";
              $database->setQuery($qs);
              $smart_id = $database->loadResult();
              return $smart_id;
       }
       public function debug($what) {
              echo '<pre>';
              print_r($what);
              echo '</pre';
       }
}
// No closing tag
