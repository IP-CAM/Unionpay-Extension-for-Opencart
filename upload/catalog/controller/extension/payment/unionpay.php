<?php

require __DIR__ . '/../../../../system/library/unionpay/php-library/autoload.php';

use Yedpay\Client;
use Yedpay\Response\Error;

class ControllerExtensionPaymentUnionpay extends Controller
{

    public function index()
    {
        $total_requirement = $this->config->get('payment_unionpay_total') == null ? 1 : $this->config->get('payment_unionpay_total');
        if ($this->cart->countProducts() < $total_requirement) {
            die('The number of items does not reach the minimum requirement of system');
        }
        $data['button_confirm'] = $this->language->get('button_confirm');

        $this->load->model('checkout/order');

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $token = $this->config->get('payment_unionpay_token');
        $config = array(
            'token' => $this->config->get('payment_unionpay_token'),

        );

        $currency = $order_info['currency_code'];
        $out_trade_no = trim($order_info['order_id']);
        $subject = trim($this->config->get('config_name'));
        $total_amount = trim($this->currency->format($order_info['total'], $currency, '', false));

        $environment = $this->config->get('payment_unionpay_test') == "sandbox" ?
        'staging' :
        'production';
        $redirect_url = '';
        try {
            $currency_index = null;
            switch (strtoupper($currency)) {
                case 'HKD':
                    $currency_index = Client::INDEX_CURRENCY_HKD;
                    break;
                case 'RMB':
                    $currency_index = Client::INDEX_CURRENCY_RMB;
                    break;
                default:
                    die('Unsupported Currency for unionpay');
            };
            $yedpay = new Client($environment, $token);
            $yedpay->setGateway(Client::INDEX_GATEWAY_UNIONPAY)
                ->setCurrency($currency_index)
                ->setNotifyUrl($this->url->link('extension/payment/unionpay/expressNotify', '', true))
                ->setReturnUrl($this->url->link('extension/payment/unionpay/expressReturn', '', true));
            $extraParam = json_encode([
                'out_trade_no' => $out_trade_no,
            ]);
            $response = $yedpay->precreate($this->config->get('payment_unionpay_store_id'), $total_amount, $extraParam);
            if ($response instanceof Error) {
                $this->log->write('error: ' . $response->getMessage());                
                die('Transaction Error. Please try again later');
            }
            $links = $response->getData()->_links;
            foreach ($links as $link) {
                if ($link->rel == 'checkout') {
                    $redirect_url = $link->href;
                }

            }
            if (!isset($redirect_url)) {
                die('No response from payment gateway server. Try again later or contact the site administrator.');
            }

        } catch (Exception $e) {
            die('Exception occurs. Try again later or contact the site administrator.');
        }

        $data['action'] = $redirect_url; //$gateway_url . 'precreate/' . $store_id;//$config['gateway_url'] . "?charset=" . $this->model_extension_payment_unionpay->getPostCharset();
        $token_params = array();

        $token_params['notify_url'] = $this->url->link('extension/payment/alipayhk/expressNotify', '', true);
        $data['form_params'] = $token_params; //$response;

        return $this->load->view('extension/payment/unionpay', $data);
    }

    public function callback()
    {
        $result = false;
        $orderId = null;
        $this->log->write('unionpay pay notify:');
        $arr = $_POST;

        $this->load->model('extension/payment/unionpay');
        $this->log->write('POST' . var_export($_POST, true));
        if (!empty($_POST['extra_parameters'])) {
            $extraParam = json_decode($_POST['extra_parameters']);
            if (json_last_error() == JSON_ERROR_NONE && !empty($extraParam->out_trade_no)) {
                $orderId = $extraParam->out_trade_no;
                $result = true;
            }
        }
        if ($result) { //check successed
            $this->log->write('Unionpay check succeeded');
            $this->load->model('checkout/order');
            $this->model_checkout_order->addOrderHistory($orderId, $this->config->get('payment_unionpay_order_status_id'));
            return "success"; //Do not modified or deleted
        } else {
            $this->log->write('Unionpay check failed');
            //chedk failed
            return "fail";
        }
    }

    public function expressReturn()
    {
        if (isset($_GET['status']) && strtolower($_GET['status']) == 'paid') {
            $this->response->redirect($this->url->link('checkout/success'));
        } else {
            $this->session->data['error'] = 'Payment Failed.';
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
        }
    }

    public function expressNotify()
    {
        if (isset($_REQUEST['status']) && strtolower($_REQUEST['status']) == 'paid') {
            echo $this->callback();
        } else {
            echo "fail";
        }
        die;
    }

}
