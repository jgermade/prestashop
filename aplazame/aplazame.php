<?php
if (!defined('_PS_VERSION_'))
    exit;

include_once(_PS_MODULE_DIR_ . '/aplazame/api/Serializers.php');
require_once(dirname(__FILE__) . '/api/RestClient.php');

class Aplazame extends PaymentModule {

    protected $config_form = false;

    const _version = '1.0.0';
    const USER_AGENT = 'Aplazame/0.0.2';
    const API_CHECKOUT_PATH = '/orders';

    public function __construct() {
        $this->name = 'aplazame';
        if(!isset($this->local_path) || empty($this->local_path)){
            $this->local_path = _PS_MODULE_DIR_.$this->name.'/';
        }
        $this->tab = 'payments_gateways';
        $this->version = self::_version;
        $this->author = 'WebImpacto';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Aplazame: compra ahora, paga después');
        $this->description = $this->l('Financiamos las compras a clientes y aumentamos un 18% las ventas en tu ecommerce.');

        $this->confirmUninstall = $this->l('¿Estás seguro de desinstalar el módulo?');

        $this->limited_countries = array('ES');

        $this->limited_currencies = array('EUR');
        $this->type = 'addonsPartner';
        $this->description_full = 'PAGA COMO QUIERAS<br/>

Tu decides cuándo y cómo quieres pagar todas tus compras de manera fácil, cómoda y segura.';
        $this->additional_description = "";
        $this->img = $this->_path . '/img/logo.png';
        $this->url = 'http://www.aplazame.com';
    }

    public function install() {


        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        $iso_code = Country::getIsoById(Configuration::get('PS_COUNTRY_DEFAULT'));

        if (in_array($iso_code, $this->limited_countries) == false) {
            $this->_errors[] = $this->l('This module is not available in your country');
            return false;
        }

        Configuration::updateValue('APLAZAME_LIVE_MODE', false);

        return parent::install() &&
                $this->registerHook('payment') &&
                $this->registerHook('paymentReturn') &&
                $this->registerHook('actionProductCancel') &&
                $this->registerHook('actionOrderDetail') &&
                $this->registerHook('actionOrderStatusPostUpdate') &&
                $this->registerHook('actionOrderStatusUpdate') &&
                $this->registerHook('actionPaymentConfirmation') &&
                $this->registerHook('actionValidateOrder') &&
                $this->registerHook('displayBeforePayment') &&
                $this->registerHook('displayFooter') &&
                $this->registerHook('displayAdminOrder') &&
                $this->registerHook('displayOrderConfirmation') &&
                $this->registerHook('displayPayment') &&
                $this->registerHook('displayPaymentReturn');
    }

    public function uninstall() {
        Configuration::deleteByName('APLAZAME_LIVE_MODE');


        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent() {
        /**
         * If values have been submitted in the form, process.
         */
        $style15 = '<style>
                label[for="active_on"],label[for="active_off"]{
                    float: none
                }
                </style>';
        
        if (((bool) Tools::isSubmit('submitAplazameModule')) == true) {
            $this->_postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
        
        if (_PS_VERSION_ < 1.6) {
            $output .= $style15;
        }
        
        return $output . $this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm() {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitAplazameModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
                . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm() {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => (_PS_VERSION_ >= 1.6) ? 'switch' : 'radio',
                        'label' => $this->l('Live mode - Sandbox Mode'),
                        'name' => 'APLAZAME_LIVE_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Use this module in live mode (Off equals to Sandbox Mode)'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-link"></i>',
                        'desc' => $this->l('Enter the Aplazame API URL'),
                        'name' => 'APLAZAME_API_URL',
                        'label' => $this->l('API URL'),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-external-link"></i>',
                        'desc' => $this->l('Enter the Aplazame API Version'),
                        'name' => 'APLAZAME_API_VERSION',
                        'label' => $this->l('API Version'),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-code"></i>',
                        'desc' => $this->l('Enter the Aplazame Button ID'),
                        'name' => 'APLAZAME_BUTTON_ID',
                        'label' => $this->l('Button'),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-code"></i>',
                        'desc' => $this->l('Enter the Aplazame Button Image that you want to show'),
                        'name' => 'APLAZAME_BUTTON_IMAGE',
                        'label' => $this->l('Button Image'),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'name' => 'APLAZAME_SECRET_KEY',
                        'label' => $this->l('Secret API Key'),
                        'desc' => $this->l('Enter the Aplazame Public Key'),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-key"></i>',
                        'desc' => $this->l('Enter the Aplazame Public Key'),
                        'name' => 'APLAZAME_PUBLIC_KEY',
                        'label' => $this->l('Public API Key'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues() {
        return array(
            'APLAZAME_LIVE_MODE' => Configuration::get('APLAZAME_LIVE_MODE', null),
            'APLAZAME_API_URL' => Configuration::get('APLAZAME_API_URL', null),
            'APLAZAME_API_VERSION' => Configuration::get('APLAZAME_API_VERSION', null),
            'APLAZAME_BUTTON_ID' => Configuration::get('APLAZAME_BUTTON_ID', null),
            'APLAZAME_SECRET_KEY' => Configuration::get('APLAZAME_SECRET_KEY', null),
            'APLAZAME_PUBLIC_KEY' => Configuration::get('APLAZAME_PUBLIC_KEY', null),
            'APLAZAME_BUTTON_IMAGE' => Configuration::get('APLAZAME_BUTTON_IMAGE', null),
        );
    }

    /**
     * Save form data.
     */
    protected function _postProcess() {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key)
            Configuration::updateValue($key, Tools::getValue($key));
    }

    /**
     * This method is used to render the payment button,
     * Take care if the button should be displayed or not.
     */
    public function hookPayment($params) {
        $currency_id = $params['cart']->id_currency;
        $currency = new Currency((int) $currency_id);

        if (in_array($currency->iso_code, $this->limited_currencies) == false)
            return false;

        $this->assignSmartyVars(array('module_dir'=> $this->_path));

        $this->assignSmartyVars(array(
            'aplazame_enabled_cookies' => true,
            'aplazame_version' => ConfigurationCore::get('APLAZAME_API_VERSION', null),
            'aplazame_url' => Configuration::get('APLAZAME_API_URL', null),
            'aplazame_public_key' => Configuration::get('APLAZAME_PUBLIC_KEY', null),
            'aplazame_button_id' => Configuration::get('APLAZAME_BUTTON_ID', null),
            'aplazame_mode' => Configuration::get('APLAZAME_LIVE_MODE', null) ? 'false' : 'true',
            'aplazame_currency_iso' => $currency->iso_code,
            'aplazame_cart_total' => self::formatDecimals($params['cart']->getOrderTotal()),
            'aplazame_button_image' => Configuration::get('APLAZAME_BUTTON_IMAGE', null),
        ));
        return $this->display(__FILE__, 'views/templates/hook/payment.tpl');
    }

    /**
     * This hook is used to display the order confirmation page.
     */
    public function hookPaymentReturn($params) {
        if ($this->active == false)
            return;

        $order = $params['objOrder'];

        if ($order->getCurrentOrderState()->id != Configuration::get('PS_OS_ERROR'))
            $this->assignSmartyVars(array('status'=> 'ok'));




        $this->assignSmartyVars(array(
            'id_order' => $order->id,
            'reference' => $order->reference,
            'params' => $params,
            'total' => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false),
        ));

        return $this->display(__FILE__, 'views/templates/hook/confirmation.tpl');
    }

    public function hookActionOrderDetail() {
        
    }

    public function refundAmount($Order,$amount){
        $price_refund = $this->formatDecimals($amount);
        $result = $this->callToRest('GET', self::API_CHECKOUT_PATH . '?mid=' . $Order->id_cart, null, false);
        $result['response'] = json_decode($result['response'], true);
        if ($result['code'] == '200' && isset($result['response']['results'][0]['id'])) {
            $resultOrder = $this->callToRest('POST', self::API_CHECKOUT_PATH . '/' . $result['response']['results'][0]['mid'].'/refund', array('amount'=>$price_refund), true);
            if($resultOrder['code'] != '200'){
                    $this->logError('Error: Cannot refund order #'.$Order->id_cart.' - ID AP: '.$result['response']['results'][0]['id']);
            }
        }else{
            $this->logError('Error: Cannot refund order mid #'.$Order->id_cart.' not exists on Aplazame');
        }
    }
    public function hookActionProductCancel($params){
        if (!Tools::isSubmit('generateDiscount') && !Tools::isSubmit('generateCreditSlip') ){                      
            $result = $this->callToRest('GET', self::API_CHECKOUT_PATH . '?mid=' . $params['order']->id_cart, null, false);
            $result['response'] = json_decode($result['response'], true);
            if ($result['code'] == '200' && isset($result['response']['results'][0]['id'])) {
                $checkout_data = $this->getCheckoutSerializer($params['order']->id, false);
                $order_data = array('order'=>$checkout_data['order']);
                $order_data['order']['shipping'] = $checkout_data['shipping'];
                $resultOrder = $this->callToRest('PUT', self::API_CHECKOUT_PATH . '/' . $result['response']['results'][0]['mid'], $order_data, true);
                $resultOrder['response'] = json_decode($resultOrder['response'], true);
                if($resultOrder['response']['success'] != 'true'){
                    $this->logError('Error: Cannot update order mid #'.$params['order']->id_cart.' - ID AP: '.$result['response']['results'][0]['id'].' with_response: '.json_encode($resultOrder).' with data: '.json_encode($order_data));
                }else{
                    $this->logError('Success on update order mid #'.$params['order']->id_cart.' - ID AP: '.$result['response']['results'][0]['id'].' with data: '.json_encode($order_data));
                }
            }else{
                $this->logError('Error: Cannot update order mid #'.$params['order']->id_cart.' not exists on Aplazame');
            }
        } 
    }
    
    public function hookActionOrderStatusPostUpdate($params) {
        $id_order = $params['id_order'];
        $statusObject = $params['newOrderStatus'];
        $Order = new Order($id_order);
        
        if ($statusObject->id == _PS_OS_CANCELED_)
        {
            $result = $this->callToRest('GET', self::API_CHECKOUT_PATH . '?mid=' . $Order->id_cart, null, false);
            $result['response'] = json_decode($result['response'], true);
            if ($result['code'] == '200' && isset($result['response']['results'][0]['id'])) {
                $result = $this->callToRest('POST', self::API_CHECKOUT_PATH . '/' . $result['response']['results'][0]['mid'].'/cancel', null, false);
                $result['response'] = json_decode($result['response'], true);
                if($result['response']['success'] != 'true'){
                    $this->logError('Error: Cannot cancel order mid #'.$Order->id_cart.' - ID AP: '.$result['response']['results'][0]['id']);
                }
            }else{
                $this->logError('Error: Cannot cancel order mid #'.$Order->id_cart.' not exists on Aplazame');
            }
        }
        
    }

    public function hookDisplayAdminOrder($params) {
        //if (_PS_VERSION_ < 1.6) {
        $id_order = $params['id_order'];
        $Order = new Order($id_order);

        if ($Order->module == $this->name) {

            $result = $this->callToRest('GET', self::API_CHECKOUT_PATH . '?mid=' . $Order->id_cart, null, false);
            $result['response'] = json_decode($result['response'], true);
            if ($result['code'] == '200' && isset($result['response']['results'][0]['id'])) {
                $result = $this->callToRest('GET', self::API_CHECKOUT_PATH . '/' . $result['response']['results'][0]['id'], null, false);
                $result['response'] = json_decode($result['response'], true);
                
                if($result['code'] == '200'){
                    $dataAplazame = array(
                        'instalments' => $result['response']['instalment_plan']['num_instalments'],
                        'annual_equivalent' => $result['response']['instalment_plan']['annual_equivalent'] / 100,
                        'total_interest_amount' => $result['response']['instalment_plan']['total_interest_amount'] / 100,
                        'total_to_pay' => ($result['response']['total_amount'] / 100) + ($result['response']['instalment_plan']['total_interest_amount'] / 100),
                        'uuid' => $result['response']['id'],
                        'mid' => $Order->id_cart
                    );
                    $dataAplazame['total_month'] = number_format((float) ($dataAplazame['total_to_pay'] / $dataAplazame['instalments']), 2, '.', '');

                    $this->assignSmartyVars(array(
                        'id_order' => $Order->id,
                        'reference' => $Order->reference,
                        'aplazame_data' => $dataAplazame,
                        'logo' => $this->img,
                    ));

                    return $this->display(__FILE__, 'views/templates/admin/order_16.tpl');
                }else{
                    $this->logError('Error: @2 #'.$id_order.' not exists on Aplazame #'.$result['code'] .'# '.var_export($result['response'],true));
                    return '<div class="error_aplazame" code="'.$result['code'] .'" style="display:none">'.var_export($result['response'],true).'</div>';
                }
            }else{
                $this->logError('Error: @1  #'.$id_order.' not exists on Aplazame #'.$result['code'] .'# '.var_export($result['response'],true));
            }
        }
        return '';
    }

    public function hookDisplayFooter() {
        if ($this->active == false)
            return;

        $this->assignSmartyVars(array(
            'aplazame_enabled_cookies' => true,
            'aplazame_version' => Configuration::get('APLAZAME_API_VERSION', null),
            'aplazame_url' => Configuration::get('APLAZAME_API_URL', null),
            'aplazame_public_key' => Configuration::get('APLAZAME_PUBLIC_KEY', null),
            'aplazame_mode' => Configuration::get('APLAZAME_LIVE_MODE', null) ? 'false' : 'true',
        ));
        return $this->display(__FILE__, 'views/templates/hook/footer.tpl');
    }

    public function hookDisplayOrderConfirmation($params) {
        return $this->hookPaymentReturn($params);
    }

    public function hookDisplayPayment($params) {
        return $this->hookPayment($params);
    }

    public function hookDisplayPaymentReturn($params) {
        //PrestaShop hook duplication problem. We keep this if we show a error on a client
        return false;
        //return $this->hookPaymentReturn($params);
    }

    public static function formatDecimals($amount = 0) {
        $negative = false;
        $str = sprintf("%.2f", $amount);
        if (strcmp($str[0], "-") === 0) {
            $str = substr($str, 1);
            $negative = true;
        }
        $parts = explode(".", $str, 2);
        if ($parts === false) {
            return 0;
        }
        if (empty($parts)) {
            return 0;
        }
        if (strcmp($parts[0], 0) === 0 && strcmp($parts[1], "00") === 0) {
            return 0;
        }
        $retVal = "";
        if ($negative) {
            $retVal .= "-";
        }
        $retVal .= ltrim($parts[0] . substr($parts[1], 0, 2), "0");
        return intval($retVal);
    }

    public function getCheckoutSerializer($id_order = 0, $id_cart = 0) {
        $serializer = new Aplazame_Serializers();
        $Order = new Order($id_order);
        $Cart = false;
        if($id_cart){
            $Cart = new Cart($id_cart);
        }
        return $serializer->getCheckout($Order, $Cart);
    }
    
    public function getCustomerHistory(Customer $customer,$limit){
        $serializer = new Aplazame_Serializers();
        return $serializer->getHistory($customer, $limit);
    }

    public function callToRest($method, $url, $values, $to_json = true) {

        $url = trim(Configuration::get('APLAZAME_API_URL', null), "/") . $url;

        $headers = array();
        if (in_array($method, array(
                    'POST', 'PUT', 'PATCH')) && $values) {
            $headers[] = 'Content-type: application/json';
        }

        $headers[] = 'Authorization: Bearer ' .
                Configuration::get('APLAZAME_SECRET_KEY', null);

        $headers[] = 'User-Agent: ' . self::USER_AGENT;

        $headers[] = 'Accept: ' . 'application/vnd.aplazame' .
                (Configuration::get('APLAZAME_LIVE_MODE', null) ? '-' : '.sandbox-') .
                Configuration::get('APLAZAME_API_VERSION', null) . '+json';

        if (extension_loaded('curl') == false || $method == 'PUT') {
            if($to_json && $values){
                $postdata = json_encode($values);
            }elseif($values){
                $postdata = http_build_query(
                    $values
                );
            }
            
            $opts = array('http' =>
                array(
                    'method' => $method,
                    'header' => $headers,
                    
                )
            );
            if(isset($postdata)){
                $opts['http']['content'] = $postdata;
            }

            $context = stream_context_create($opts);
            try {
                $response = file_get_contents($url, false, $context);
                $headersResponse = $this->parseHeaders($http_response_header);
                $result['response'] = $response;
                $result['code'] = $headersResponse['reponse_code'];
            } catch (Exception $e) {
                $this->logError($e->getMessage());
            }
        } else {
            $response = RestClient::$method($url, ($to_json) ? json_encode($values) : $values
                            , null, null, null, $headers);

            $result['response'] = $response->getResponse();
            $result['code'] = $response->getResponseCode();
        }

        return $result;
    }

    function parseHeaders($headers) {
        $head = array();
        foreach ($headers as $k => $v) {
            $t = explode(':', $v, 2);
            if (isset($t[1]))
                $head[trim($t[0])] = trim($t[1]);
            else {
                $head[] = $v;
                if (preg_match("#HTTP/[0-9\.]+\s+([0-9]+)#", $v, $out))
                    $head['reponse_code'] = intval($out[1]);
            }
        }
        return $head;
    }

    function getErrorMessage($error_code) {
        $error = "An error occurred while processing payment";
        switch ($error_code) {
            case "400": $error = "Bad Request - The data have not been correctly validated";
                break;
            case "401": $error = "Unauthorized - Token is not found in the request or it is wrong";
                break;
            case "403": $error = "Forbidden - You do not have permission to do this operation";
                break;
            case "404": $error = "Not Found - The object or the resource is not found";
                break;
            case "405": $error = "Method Not Allowed - You tried to access with an invalid method";
                break;
            case "406": $error = "Not Acceptable - You requested a format that is not valid";
                break;
            case "429": $error = "Too Many Requests - Multiple simultaneous requests are made. Slown down!";
                break;
            case "500": $error = "Internal Server Error	Houston, we have a problem. Try again later.";
                break;
            case "503": $error = "Service Unavailable	We’re temporarially offline for maintanance. Please try again later.";
                break;
        }
        return $error;
    }

    function logError($message) {
        file_put_contents(dirname(__FILE__) . '/logs/exception_log', PHP_EOL.date(DATE_ISO8601) . ' ' . $message . '\r\n', FILE_APPEND);
    }
    
    function validateController($id_order){
        $result = $this->callToRest('POST', self::API_CHECKOUT_PATH . '/' . $id_order . '/authorize', null, false);
        $result['response'] = json_decode($result['response'], true);


        $cart_id = $result['response']['id'];
        $amount = $result['response']['amount'] / 100;

        Context::getContext()->cart = new Cart((int) $cart_id);

        $customer_id = Context::getContext()->cart->id_customer;

        Context::getContext()->customer = new Customer((int) $customer_id);
        Context::getContext()->currency = new Currency((int) Context::getContext()->cart->id_currency);
        Context::getContext()->language = new Language((int) Context::getContext()->cart->id_lang);
        
        $secure_key = Context::getContext()->customer->secure_key;

        if ($this->isValidOrder($result['code']) === true) {
            $payment_status = Configuration::get('PS_OS_PAYMENT');
            $message = null;
            $module_name = $this->displayName;
            $currency_id = (int) Context::getContext()->currency->id;

            return $this->validateOrder($cart_id, $payment_status, $amount, $module_name, $message, array(), $currency_id, false, $secure_key);
        } else {

            $payment_status = Configuration::get('PS_OS_ERROR');

            $error = $this->getErrorMessage($result['code']);
            $message = $this->l($error);
            $this->logError($message);
            return false;
        }

        
    }
    
    function assignSmartyVars($array){
        if(_PS_VERSION_ >= 1.6 || isset($this->smarty)){
            $this->smarty->assign($array);
        }else{
            $this->context->smarty->assign($array);
        }
    }
    
    protected function isValidOrder($code) {
        if ($code == '200') {
            return true;
        } else {
            return false;
        }
    }

}
