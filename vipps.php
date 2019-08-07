<?php

defined('_JEXEC') or die('Restricted access');

/**
 * Vipps Express Checkout plugin
 * @author Daniel Paez - Tepuy AS
 *
 */

if (!class_exists('vmPSPlugin')) {
    require(VMPATH_PLUGINLIBS . DS . 'vmpsplugin.php');
}

class plgVmPaymentVipps extends vmPSPlugin
{

    function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_loggable = TRUE;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = $this->getVarsToPush();
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
        $this->setConvertable(array('min_amount', 'max_amount', 'cost_per_transaction', 'cost_min_transaction'));
        $this->setConvertDecimal(array('min_amount', 'max_amount', 'cost_per_transaction', 'cost_min_transaction', 'cost_percent_total'));
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     *
     * @author Valérie Isaksen
     */
    public function getVmPluginCreateTableSQL()
    {

        return $this->createTableSQL('Payment Standard Table');
    }

    /**
     * Fields to create the payment table
     *
     * @return string SQL Fileds
     */
    function getTableSQLFields()
    {

        $SQLfields = array(
            'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(5000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
            'email_currency' => 'char(3)',
            'cost_per_transaction' => 'decimal(10,2)',
            'cost_min_transaction' => 'decimal(10,2)',
            'cost_percent_total' => 'decimal(10,2)',
            'tax_id' => 'smallint(1)'
        );

        return $SQLfields;
    }


    static function getPaymentCurrency(&$method, $selectedUserCurrency = false)
    {

        if (empty($method->payment_currency)) {
            $vendor_model = VmModel::getModel('vendor');
            $vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
            $method->payment_currency = $vendor->vendor_currency;
            return $method->payment_currency;
        } else {

            $vendor_model = VmModel::getModel('vendor');
            $vendor_currencies = $vendor_model->getVendorAndAcceptedCurrencies($method->virtuemart_vendor_id);

            if (!$selectedUserCurrency) {
                if ($method->payment_currency == -1) {
                    $mainframe = JFactory::getApplication();
                    $selectedUserCurrency = $mainframe->getUserStateFromRequest("virtuemart_currency_id", 'virtuemart_currency_id', vRequest::getInt('virtuemart_currency_id', $vendor_currencies['vendor_currency']));
                } else {
                    $selectedUserCurrency = $method->payment_currency;
                }
            }

            $vendor_currencies['all_currencies'] = explode(',', $vendor_currencies['all_currencies']);
            if (in_array($selectedUserCurrency, $vendor_currencies['all_currencies'])) {
                $method->payment_currency = $selectedUserCurrency;
            } else {
                $method->payment_currency = $vendor_currencies['vendor_currency'];
            }

            return $method->payment_currency;
        }

    }

    /**
     * Gets access token from Vipps
     * @author Daniel Paez
     */
    function getAccessToken($url, $payment_params)
    {
        $secret = explode('=', $payment_params[2],2);
        $clientSecret = str_replace('"', "", $secret[1]);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS,"");
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            "Ocp-Apim-Subscription-Key: ".$payment_params['Ocp-Apim-Subscription-Key'],
            "client_id: ".$payment_params['clientId'],
            "client_secret: ".$clientSecret,
        ));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return "cURL Error #:" . $err;
        } else {

            return $response;
        }

    }

    function getVippsParameters($paymentMethodId) {

        vmLanguage::loadJLang('com_virtuemart', true);
        vmLanguage::loadJLang('com_virtuemart_orders', TRUE);

        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }

        $method = $this->getVmPluginMethod($paymentMethodId);

        $payment_params = explode("|", $method->payment_params);
        $payment_params = preg_replace('/\\\\/', '', $payment_params);
        $secret = explode('=', $payment_params[2],2);
        $clientSecret = str_replace('"', "", $secret[1]);
        foreach ($payment_params as $payment_param) {
            if (empty($payment_param)) {
                continue;
            }
            $param = explode('=', $payment_param);
            $payment_params[$param[0]] = substr($param[1], 1, -1);
        }

        return $payment_params;
    }


    /**
     * @author Valérie Isaksen
     * @author Daniel Paez
     */
    function plgVmConfirmedOrder($cart, $order)
    {

        if(!($method = $this->getVmPluginMethod($order["details"]["BT"]->virtuemart_paymentmethod_id)))
            return null;
        // Another method was selected, do nothing

        if(!$this->selectedThisElement($method->payment_element))
            return false;

        $payment_params = $this->getVippsParameters($order["details"]["BT"]->virtuemart_paymentmethod_id);
        $subscriptionKey = $payment_params['Ocp-Apim-Subscription-Key'];
        $clientId = $payment_params['clientId'];
        $baseUrl = $payment_params['baseUrl'];
        $this->getPaymentCurrency($method, $order['details']['BT']->payment_currency_id);
        $currency_code_3 = shopFunctions::getCurrencyByID($method->payment_currency, 'currency_code_3');
        $email_currency = $this->getEmailCurrency($method);
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);
        $ordersalesPrice = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_salesPrice, $method->payment_currency);

        if (!empty($method->payment_info)) {
            $lang = JFactory::getLanguage();
            if ($lang->hasKey($method->payment_info)) {
                $method->payment_info = vmText::_($method->payment_info);
            }
        }

        $dbValues['payment_name'] = $this->renderPluginName($method) . '<br />' . $method->payment_info;
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['order_number'] = $order['details']['BT']->virtuemart_order_id;
        $dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
        $dbValues['cost_min_transaction'] = $method->cost_min_transaction;
        $dbValues['cost_percent_total'] = $method->cost_percent_total;
        $dbValues['payment_currency'] = $currency_code_3;
        $dbValues['email_currency'] = $email_currency;
        $dbValues['payment_order_total'] = $totalInPaymentCurrency['value'];
        $dbValues['order_salesPrice'] = $ordersalesPrice['value'];
        $dbValues['tax_id'] = $method->tax_id;
        $this->storePSPluginInternalData($dbValues);

        if (!class_exists('VirtueMartModelCurrency')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'currency.php');
        }
        $currency = CurrencyDisplay::getInstance('', $order['details']['BT']->virtuemart_vendor_id);

        $html = $this->renderByLayout('post_payment', array(
            'order_number' => $order['details']['BT']->order_number,
            'order_pass' => $order['details']['BT']->order_pass,
            'payment_name' => $dbValues['payment_name'],
            'displayTotalInPaymentCurrency' => $totalInPaymentCurrency['display']
        ));
        $modelOrder = VmModel::getModel('orders');
        $order['order_status'] = $this->getNewStatus($method);
        $order['customer_notified'] = 1;
        $order['comments'] = '';
        $modelOrder->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, TRUE);

        /**
         * Initiate Vipps payment
         * @author Daniel Paez
         */
        function callVippsPaymentAPI($apiMethod, $url, $data, $token, $payment_params)
        {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                // Request headers
                'Authorization: ' . $token["token_type"] . ' ' . $token["access_token"],
                'X-Request-Id: ',
                'X-TimeStamp: ',
                'Content-Type: application/json',
                'Ocp-Apim-Subscription-Key: ' . $payment_params['Ocp-Apim-Subscription-Key'],
            ));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);


            $result = curl_exec($curl);
            if (!$result) {
                die("Connection Failure");
            }

            curl_close($curl);
            $vippsUrl = json_decode($result, true);

            header('Location: ' . $vippsUrl['url']);

            return $result;
        }

        $response = json_decode($initiatePayment, true);
        $errors = $response['response']['errors'];
        $data = $response['response']['data'][0];

        $data_array = array(
            'merchantInfo' =>
                array(
                    'merchantSerialNumber' => $payment_params['merchantSerialNumber'],
                    'callbackPrefix'=> $payment_params['callbackPrefix'].'/index.php?callback=1&option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on='.$order['details']['BT']->virtuemart_order_id.'&paymentMethodId='.$order["details"]["BT"]->virtuemart_paymentmethod_id,
                    'shippingDetailsPrefix' => $payment_params['shippingDetailsPrefix'],
                    'consentRemovalPrefix' => $payment_params['consentRemovalPrefix'],
                    'paymentType' => 'eComm Express Payment',
                    'fallBack' => $payment_params['callbackPrefix'].'/index.php?callback=1&option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on='.$order['details']['BT']->virtuemart_order_id.'&paymentMethodId='.$order["details"]["BT"]->virtuemart_paymentmethod_id,
                    'authToken' => '{{$guid}}',
                    'isApp' => false,
                ),
            'customerInfo' =>
                array(
                    'mobileNumber' => '',
                ),
            'transaction' =>
                array(
                    'orderId' => $dbValues['order_number'],
                    'amount' => ($dbValues['order_salesPrice']*100),
                    'transactionText' => 'Order number: ' . $dbValues['order_number'],
                ),
        );

        $token = $this->getAccessToken($baseUrl . "accesstoken/get", $payment_params);
        $tokenResponse = json_decode($token, true);

        $initiateVippsPayment = callVippsPaymentAPI('POST', $baseUrl.'ecomm/v2/payments/', json_encode($data_array), $tokenResponse, $payment_params);
        $response = json_decode($initiatePayment, true);
        $cart->emptyCart();
        vRequest::setVar('html', $html);

        /* Will be used before calling the Vipps subscription API */
        /*
        function productHasSuscription($cart) {

	        $order_id = $cart->virtuemart_order_id;
	        $payment_subscription = 0;

	        // Loop through each product
	        foreach ($cart->products as $key => $product) {

	            $q = 'SELECT product_attribute FROM #__virtuemart_order_items WHERE virtuemart_order_id ="'  . $order_id . '" ';
	            $db =  & JFactory::getDBO();
	            $db->setQuery($q);

	            $product_attribute = $db->loadResult();
	            $product_attribute_object = json_decode($product_attribute);
	            $product_attribute_id = $product_attribute_object->{'103'};

	            $q = 'SELECT customfield_value FROM #__virtuemart_product_customfields WHERE virtuemart_customfield_id ="'  . $product_attribute_id . '" ';
	            $db =  & JFactory::getDBO();
	            $db->setQuery($q);

	            $subscription_value = $db->loadResult();

	            if(strpos($subscription_value, 'året.' ) !== false ) {
	                return = true;
				}
			}
        }
        $hasSuscription = fetchSubscriptions($cart);
        */


        return TRUE;
    }


    /**
     * @author Daniel Paez
     * @param string $html
     */
    function plgVmOnPaymentResponseReceived(&$html = "")

    {

        $payment_data = $_GET;
        $virtuemart_order_id = $payment_data['on'];
        $paymentMethodId = $payment_data['paymentMethodId'];

        if ($paymentMethodId == 6) {

            $payment_params = $this->getVippsParameters($paymentMethodId);


            function callVippsStatusAPI($url, $payment_params, $token)
            {
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_POSTFIELDS, "");
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
                curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                    // Request headers
                    'Authorization: ' . $token["token_type"] . ' ' . $token["access_token"],
                    'X-Request-Id: ',
                    'X-TimeStamp: ',
                    'Content-Type: application/json',
                    'Ocp-Apim-Subscription-Key: ' . $payment_params['Ocp-Apim-Subscription-Key'],
                ));
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

                $result = curl_exec($curl);
                if (!$result) {
                    die("Connection Failure");
                }

                curl_close($curl);

                return $result;
            }

            $token = $this->getAccessToken($payment_params['baseUrl'] . "accesstoken/get", $payment_params);
            $token = json_decode($token, true);
            $checkVippsOrderStatus = callVippsStatusAPI($payment_params['baseUrl'] . '/ecomm/v2/payments/'.$virtuemart_order_id.'/status', $payment_params, $token);
            $statusResponse = json_decode($checkVippsOrderStatus, true);
            $statusResponse = $statusResponse['transactionInfo']['status'];

            if($statusResponse == "RESERVE") {

                $email_sent = "SELECT email_sent FROM #__virtuemart_orders WHERE virtuemart_order_id=" . $virtuemart_order_id;
                $db = JFactory::getDBO();
                $db->setQuery($email_sent);
                $email_is_sent = $db->loadResult();

                if ($email_is_sent == null) {
                    if($virtuemart_order_id)
                    {
                        // send the email only if payment has been accepted
                        if(!class_exists('VirtueMartModelOrders'))
                            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
                        $modelOrder = new VirtueMartModelOrders();
                        $order["order_status"] = "C";
                        $order["virtuemart_order_id"] = $virtuemart_order_id;
                        $order["customer_notified"] = 1;
                        $order['comments'] = JText::sprintf('VMPAYMENT_VIPPS_PAYMENT_STATUS_CONFIRMED', $payment_data["orderid"]);
                        $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);

                        $db = JFactory::getDBO();
                        $q = "UPDATE #__virtuemart_orders SET email_sent = 1 WHERE virtuemart_order_id=" . $virtuemart_order_id;
                        $db->setQuery($q);
                        $db->query();

                        function addSubscriptions($virtuemart_order_id)
                        {
                            if(!class_exists('VirtueMartModelSubscription'))
                                require (JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'subscription.php');

                            $subscriptionModel = new VirtueMartModelSubscription();
                            $order_item_detail = $subscriptionModel->getOrderItemDetailByOrderId($virtuemart_order_id);

                            foreach ($order_item_detail as $key => $value) {

                                $product_attribute = json_decode($value['product_attribute'],true);
                                $subscription_details['virtuemart_order_item_id'] = $value['virtuemart_order_item_id'];

                                foreach ($product_attribute as $key1 => $value1) {

                                    $payment_subscription = 0;
                                    $subscription_type = 0;

                                    $q = 'SELECT product_attribute FROM #__virtuemart_order_items WHERE virtuemart_order_item_id ="'  . $subscription_details['virtuemart_order_item_id'] . '" ';
                                    $db =  JFactory::getDBO();
                                    $db->setQuery($q);

                                    $product_attribute = $db->loadResult();
                                    $product_attribute_object = json_decode($product_attribute);
                                    $product_attribute_id = $product_attribute_object->{'103'};

                                    $q = 'SELECT customfield_value FROM #__virtuemart_product_customfields WHERE virtuemart_customfield_id ="'  . $product_attribute_id . '" ';
                                    $db =  JFactory::getDBO();
                                    $db->setQuery($q);
                                    $subscription_value = $db->loadResult();

                                    if (strpos($subscription_value, '2 ganger' ) !== false ) {
                                        $payment_subscription = 2;
                                        $subscription_type = 2;
                                    } else if(strpos($subscription_value, '1 gang') !== false ) {
                                        $payment_subscription = 1;
                                        $subscription_type = 1;
                                    } else {
                                        $payment_subscription = 0;
                                        $subscription_type = 0;
                                    }

                                    $order_subscription_id = $subscriptionModel->checkIfProductSubscriptionPresent($subscription_details);

                                    if(empty($order_subscription_id) && $subscription_type !== 0) {
                                        $subscription_details['created_on'] = date("Y-m-d H:i:s");
                                        $subscription_details['subscriptionid'] = $subscription_details['virtuemart_order_item_id'];
                                        $subscription_details['subscription_type'] = $subscription_type;
                                        $subscription_details['card_expiry_month'] = '';
                                        $subscription_details['card_expiry_year'] = '';
                                        $order_subscription_id = $subscriptionModel->insertSubscriptionDetails($subscription_details);
                                        $subscription_details['virtuemart_order_subscription_id'] = $order_subscription_id;
                                    }

                                }


                            }
                        }

                        addSubscriptions($virtuemart_order_id);

                    }
                }

                function capturePayment($payment_params,$virtuemart_order_id,$token)
                {
                    $body = array(
                        'merchantInfo' =>
                            array(
                                'merchantSerialNumber' => $payment_params['merchantSerialNumber'],
                            ),
                        'transaction' =>
                            array(
                                'transactionText' => 'Captured',
                            ),
                    );

                    $curl = curl_init();
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
                    curl_setopt($curl, CURLOPT_URL, $payment_params['baseUrl'] . '/ecomm/v2/payments/'.$virtuemart_order_id.'/capture');
                    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
                    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                        // Request headers
                        'Authorization: ' . $token["token_type"] . ' ' . $token["access_token"],
                        'X-Request-Id: ',
                        'X-TimeStamp: ',
                        'Content-Type: application/json',
                        'Ocp-Apim-Subscription-Key: ' . $payment_params['Ocp-Apim-Subscription-Key'],
                    ));
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                    $result = curl_exec($curl);
                    if (!$result) {
                        die("Connection Failure");
                    }
                    curl_close($curl);
                    return $result;
                }
                $capturePayment = capturePayment($payment_params,$virtuemart_order_id,$token);


                $db =  JFactory::getDBO();
                $query = "SELECT * FROM #__virtuemart_orders WHERE virtuemart_order_id =" . $virtuemart_order_id;
                $db->setQuery($query);
                $payment = $db->loadObject();

                $html = $this->_getPaymentResponseHtml($payment);

            }
            else {
                header('Location: '.$payment_params['fallBack']);

            }
        }

    }

    function _getPaymentResponseHtml($payment)

    {
        $html = '<table>' . "\n";
        $html .= $this->getHtmlRow(JText::sprintf('COM_VIRTUEMART_ORDER_PRINT_PAYMENT_LBL'), 'Vipps Express Checkout');
        $html .= $this->getHtmlRow(JText::sprintf('COM_VIRTUEMART_ORDER_LIST_ORDER_NUMBER'), $payment->order_number);
        $html .= $this->getHtmlRow(JText::sprintf('COM_VIRTUEMART_ORDER_PRINT_SHIPPING'),  $payment->order_shipment + $payment->order_shipment_tax. ' Kr');
        $html .= $this->getHtmlRow(JText::sprintf('COM_VIRTUEMART_ORDER_PRINT_TOTAL_TAX'),  number_format($payment->order_billTaxAmount, 2). ' Kr');
        $html .= $this->getHtmlRow(JText::sprintf('COM_VIRTUEMART_ORDER_PRINT_TOTAL'),  number_format($payment->order_total, 2). ' Kr');
        $html .= '</table>' . "\n";
        return $html;
    }


    /*
     * Keep backwards compatibility
     * a new parameter has been added in the xml file
     */
    function getNewStatus($method)
    {

        if (isset($method->status_pending) and $method->status_pending != "") {
            return $method->status_pending;
        } else {
            return 'P';
        }
    }

    /**
     * Display stored payment data for an order
     *
     */
    function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id)
    {

        if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
            return NULL; // Another method was selected, do nothing
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return NULL;
        }
        vmLanguage::loadJLang('com_virtuemart');

        $html = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
        $html .= $this->getHtmlRowBE('STANDARD_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
        if ($paymentTable->email_currency) {
            $html .= $this->getHtmlRowBE('STANDARD_EMAIL_CURRENCY', $paymentTable->email_currency);
        }
        $html .= '</table>' . "\n";
        return $html;
    }


    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @author: Valerie Isaksen
     *
     * @param $cart_prices : cart prices
     * @param $payment
     * @return true: if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {

        $this->convert_condition_amount($method);
        $amount = $this->getCartAmount($cart_prices);
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        if ($this->_toConvert) {
            $this->convertToVendorCurrency($method);
        }
        //vmdebug('standard checkConditions',  $amount, $cart_prices['salesPrice'],  $cart_prices['salesPriceCoupon']);
        $amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
            OR
            ($method->min_amount <= $amount AND ($method->max_amount == 0)));
        if (!$amount_cond) {
            return FALSE;
        }
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
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries)) {
            return TRUE;
        }

        return FALSE;
    }


    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     *
     * @author Valérie Isaksen
     *
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {

        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author Valérie isaksen
     *
     * @param VirtueMartCart $cart : the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     */
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {

        return $this->OnSelectCheck($cart);
    }

    /**
     * plgVmDisplayListFEPayment
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */
    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {

        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /*
    * plgVmonSelectedCalculatePricePayment
    * Calculate the price (value, tax_id) of the selected method
    * It is called by the calculator
    * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
    * @author Valerie Isaksen
    * @cart: VirtueMartCart the current cart
    * @cart_prices: array the new cart prices
    * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
    *
    *
    */

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {

        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return FALSE;
        }
        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
        return;
    }

    /**
     * plgVmOnCheckAutomaticSelectedPayment
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @author Valerie Isaksen
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {

        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param integer $order_id The order ID
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     * @author Max Milbers
     * @author Valerie Isaksen
     */
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {

        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * @param $orderDetails
     * @param $data
     * @return null
     */

    function plgVmOnUserInvoice($orderDetails, &$data)
    {

        if (!($method = $this->getVmPluginMethod($orderDetails['virtuemart_paymentmethod_id']))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return NULL;
        }
        //vmdebug('plgVmOnUserInvoice',$orderDetails, $method);

        if (!isset($method->send_invoice_on_order_null) or $method->send_invoice_on_order_null == 1 or $orderDetails['order_total'] > 0.00) {
            return NULL;
        }

        if ($orderDetails['order_salesPrice'] == 0.00) {
            $data['invoice_number'] = 'reservedByPayment_' . $orderDetails['order_number']; // Nerver send the invoice via email
        }

    }

    /**
     * @param $virtuemart_paymentmethod_id
     * @param $paymentCurrencyId
     * @return bool|null
     */
    function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId)
    {

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return FALSE;
        }

        if (empty($method->email_currency)) {

        } else if ($method->email_currency == 'vendor') {
            $vendor_model = VmModel::getModel('vendor');
            $vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
            $emailCurrencyId = $vendor->vendor_currency;
        } else if ($method->email_currency == 'payment') {
            $emailCurrencyId = $this->getPaymentCurrency($method);
        }


    }

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */
    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {

        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {

        return $this->setOnTablePluginParams($name, $id, $table);
    }


}
