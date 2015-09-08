<?php /**

 * Käesoleva loomingu autoriõigused kuuluvad Revo Rästale ja Aktsiamaailm OÜ-le

 * Litsentsitingimused on saadaval http://www.e-abi.ee/litsents

 * @version 1.0

 */

if (!defined('_JEXEC'))

       die('Direct Access to '.basename(__file__).' is not allowed.');

if (!class_exists('vmPSPlugin'))

       require (JPATH_VM_PLUGINS.DS.'vmpsplugin.php');

class plgVmShipmentpost24 extends vmPSPlugin {

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

                     'calc_shipping' => array('', 'int'),

                     'tax_id' => array('', 'int'));

              $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);

       }

       protected function getVmPluginCreateTableSQL() {

              return $this->createTableSQL('Table for Omniva');

       }

       function getTableSQLFields() {

              $SQLfields = array(

                     'id' => ' int(11) unsigned NOT NULL AUTO_INCREMENT',

                     'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',

                     'order_number' => 'char(32) DEFAULT NULL',

                     'virtuemart_shipmentmethod_id' => 'int(11) UNSIGNED DEFAULT NULL',

                     'shipment_name' => 'char(255) NOT NULL DEFAULT \'\' ',

                     'selected_parcel' => 'int(11) UNSIGNED DEFAULT NULL',

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

              $values['selected_parcel'] = $_SESSION['post24_selected_parcel'];

              if (isset($order['details']['BT']->phone_1)) {

                     $phone = $order['details']['BT']->phone_1;

              } else {

                     $phone = $order['details']['BT']->phone_2;

              }

              $values['phone'] = $phone;

              $values['shipment_package_fee'] = $method->package_fee;

              $values['tax_id'] = $method->tax_id;

              unset($_SESSION['post24_selected_parcel']);

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

              $html .= $this->getHtmlRowBE('POST24_SM', 'Omniva');

              $html .= $this->getHtmlRowBE('POST24_SELECTED_PARCEL', $title);

              $html .= $this->getHtmlRowBE('POST24_TOPHONE', $method->phone);

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

                     if ($size[1] > 38) {

                                   $swap = $size[1];

                                   $size[1] = $size[0];

                             $size[0] = $swap;

                            }

                     if ($prodSize['unit'] == "M") {

                            $ex = '100';

                     } elseif ($prodSize['unit'] == 'CM') {

                            $ex = '1';

                     } elseif ($prodSize['unit'] == 'MM') {

                            $ex = '0.1';

                     }

                     if ($method->calc_shipping == 1) {

                            if ($size[0] * $ex <= 8) {

                                   $shippingCosts[] = $method->small * $prodSize['dimensions']['q'];

                            } elseif ($size[0] * $ex <= 19) {

                                    $shippingCosts[] = $method->medium * $prodSize['dimensions']['q'];

                            } elseif ($size[0] * $ex <= 41) {

                                    $shippingCosts[] = $method->large * $prodSize['dimensions']['q'];

                            }

                     } else {

                            if ($size[0] * $ex <= 8) {

                                   $shippingCosts[] = $method->small;

                            } elseif ($size[0] * $ex <= 19) {

                                    $shippingCosts[] = $method->medium;

                            } elseif ($size[0] * $ex <= 41) {

                                    $shippingCosts[] = $method->large;

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

              if (!is_array($prodSizes)) {

                     if (!$this->_isShippable($prodSizes)) {

                            return false;

                     }

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

              if ($size[2] > 64) return false;

                     if ($size[1] > 41) return false;

                     if ($size[0] > 38) return false;

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

              $post24_id = $_REQUEST['virtuemart_shipmentmethod_id'];

              if (!$method = $this->getVmPluginMethod($post24_id)) {

                     unset($_SESSION['post24_selected_parcel']);

                     return null; // Another method was selected, do nothing

              }

              if (!$this->selectedThisElement($method->shipment_element)) {

                     unset($_SESSION['post24_selected_parcel']); //unset selected parcel because another method was selected.

                     return false;

              }

              //for no errors let's disable estonian smartpost

              $selected_parcel = $_REQUEST['sratespost24'];

              if ($selected_parcel == 0 && $this->selectedThisElement($method->shipment_element) ==

                     1) {

                     JError::raiseWarning(100, "Please select Omniva parcel");

                     unset($_SESSION['post24_selected_parcel']);

              }

              $_SESSION['post24_selected_parcel'] = $selected_parcel;

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

       public function plgVmDisplayListFEShipment(VirtueMartCart $cart, $selected = 0,&$htmlIn) {

              //get Itella SmartPOST id

              $post24_id = $this->get_post24_id();

              if (!($method = $this->getVmPluginMethod($post24_id))) {

                     return null; // Another method was selected, do nothing

              }

              if (!$this->selectedThisElement($method->shipment_element) || $method->published ==0) {

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

              

                            $methodSalesPrice = $this->calculateSalesPrice($cart, $method, $cart->pricesUnformatted);

                            $Handling_Fee = $methodSalesPrice;

              

              $rates = $this->_quote();

              $formattedRates = array();

              $sRates = array();

              $formattedRates["0"] = '--'.JText::_('VMSHIPMENT_POST24_SELECT_PARCEL').'--';

              foreach ($rates as $rate) {

                     $formattedRates[$rate['id']] = $rate['title'];

              }

              if ($cart->virtuemart_shipmentmethod_id != $post24_id) {

                     $html .= '<input type="radio" name="virtuemart_shipmentmethod_id" id="shipment_id_'.

                            $post24_id.'" value="'.$post24_id.'">';

              } else {

                     $html .= '<input type="radio" name="virtuemart_shipmentmethod_id" id="shipment_id_'.

                            $post24_id.'" value="'.$post24_id.'" checked="">';

              }

              $html .= '<label for="shipment_id_'.$post24_id.'"><span class="vmshipment">';

              $html .= '<span class="vmshipment_name">'.$method->Client_Title.'</span>';

              $html .= '<span class="vmshipment_description">';

              $html .= $this->getSelect('sratespost24', $formattedRates, $_SESSION['post24_selected_parcel'],

                     "style='width: 200px;' id='smpselectpost24' onChange='jQuery(\"#shipment_id_".$post24_id.

                    "\").attr(\"checked\",\"\"); return false;' onClick='jQuery(\"#shipment_id_".$post24_id."\").attr(\"checked\",\"\"); return false;'");

              $html .= '</span>';

              $html .= '<span class="vmshipment_cost">('.JText::_('VMSHIPMENT_POST24_FEE').

                     number_format($Handling_Fee, 2, ",", "").' €)</span></span></label>';

              $htmlIn[] = array($html);

              return true;

       }

       function myCmp(&$a, &$b) {

              return $a["province"] > $b["title"];

       }

       public function plgVmOnCheckoutCheckData($psType, VirtueMartCart $cart) {

              $post24_id = $this->get_post24_id();

              if (!$method = $this->getVmPluginMethod($post24_id)) {

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

              $post24_id = $this->get_post24_id();

              if (!$method = $this->getVmPluginMethod($post24_id)) {

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

              $post24_id = $this->get_post24_id();

              if (!($method = $this->getVmPluginMethod($post24_id))) {

                     return null; // Another method was selected, do nothing

              }

             if ($city === NULL) $city = '';

              if ($state === NULL) $state = '';

              $city = htmlentities($city, ENT_COMPAT, "UTF-8");

              $state = htmlentities($state, ENT_COMPAT, "UTF-8");

              if (file_exists("post24cache.txt") && (time() - filemtime("post24cache.txt")) < 86400) {

                     $body = file_get_contents("post24cache.txt");

              } else {

                     $body = file("http://www.post.ee/?tpl=1053&get_csv=2");

                     $parcelTerminals = array();

                     foreach ($body as $bodyElement) {

                            $bodyCsv = $this->csv2array(utf8_encode($bodyElement), ';');

                            if (isset($bodyCsv[10]) && ((int)$bodyCsv[10]) == '1') {

                                   $parcelTerminals[] = array(

                                          'id' => $bodyCsv[0],

                                          'name' => $bodyCsv[2],

                                          'address' => $bodyCsv[3],

                                          'city' => $bodyCsv[4],

                                          'active' => 1,

                                          'group_sort' => $this->getGroupSort($bodyCsv[4]),

                                   );

                            }

                     }

                     if (count($parcelTerminals) == 0 && file_exists("post24cache.txt")) {

                            $parcelTerminals = unserialize(file_get_contents('post24cache.txt'));

                            if (!is_array($parcelTerminals)) {

                                   echo 'Omniva serverist pakiautomaatide laadimine ebaõnnestus';

                            }

                     }

                     $body = serialize($parcelTerminals);

                     if (is_writable("post24cache.txt") || is_writable(getcwd())) {

                            file_put_contents("post24cache.txt", $body);

                     }

                      }

              $response = unserialize($body);

              $result = array();

              foreach ($response as $r) {

                     if ($r['active'] == 1) {

                            if (($city == '' && $state == '') || (($city != '' && $state != '') && (stripos

                                   (htmlentities($r['name'], ENT_COMPAT, "UTF-8"), $city) !== false || stripos(htmlentities

                                   ($r['group_name'], ENT_COMPAT, "UTF-8"), $state) !== false))) {

                                   if ($method->Short_Names == 0) {

                                          $result[$r['id']] = array('id' => $r['id'],

                                                               'title' => htmlentities($r['name'], ENT_COMPAT, "UTF-8"),

                                                               'desc' => $r['name'],
                                                               'group_sort' => $r['group_sort'],

                                                        );

                                   } else {

                                          $result[$r['id']] = array('id' => $r['id'],

                                                               'title' => htmlentities($r['name']." (".$r['address']." ".$r['city'].")", ENT_COMPAT, "UTF-8"),

                                                               'desc' => $r['name'],
                                                               'group_sort' => $r['group_sort'],

                                                        );

                                   }

                            }

                     }

              }

              //sort the results


                     uasort($result, array(__CLASS__, 'sort'));



              return $result;

       }

    public static function sort($a, $b) {
        $a = str_pad(100 - $a['group_sort'], 3, "0", STR_PAD_LEFT) . $a['title'];
        $b = str_pad(100 - $b['group_sort'], 3, "0", STR_PAD_LEFT) . $b['title'];
        return strcmp($a, $b);
    }


              function getSelect($name, $values, $selected, $d = '') {

              $str = '';

              $str .= '<select name="'.$name.'" '.$d.'>'."\r\n";

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

       public function get_post24_id() {

              $database = &JFactory::getDBO();

              $qs = "SELECT virtuemart_shipmentmethod_id as smartid FROM #__virtuemart_shipmentmethods WHERE shipment_element='post24' LIMIT 1";

              $database->setQuery($qs);

              $post24 = $database->loadResult();

              return $post24;

       }

       public function debug($what) {

              echo '<pre>';

              print_r($what);

              echo '</pre';

       }

              private function csv2array($input,$delimiter=',',$enclosure='"',$escape='\\'){

           $fields=explode($enclosure.$delimiter.$enclosure,substr($input,1,-1));

           foreach ($fields as $key=>$value)

               $fields[$key]=str_replace($escape.$enclosure,$enclosure,$value);

           return($fields);

       }

    private function getGroupSort($city) {

              $sorts = array(

              'Tallinn' => 20,

              'Tartu'=>19,

              'Pärnu'=>18,

              'Jõhvi'=>17,

              'Sillamäe'=>17,

              'Narva'=>17,

              'Kohtla Järve'=>17,

              'Kohtla-Järve'=>17,

              'Kärdla'=>16,

              'Põltsamaa'=>15,

              'Jõgeva'=>15,

              'Türi'=>14,

              'Paide'=>14,

              'Tapa'=>13,

              'Rakvere'=>13,

              'Haapsalu'=>12,

              'Põlva'=>11,

              'Rapla'=>10,

              'Märjamaa'=>10,

              'Kuressaare'=>9,

              'Otepää'=>8,

              'Tõrva'=>8,

              'Valga'=>8,

              'Viljandi'=>7,

              'Võru'=>6,

              );

              if (isset($sorts[$city])) return $sorts[$city];

              return 0;

       }

}

// No closing tag

