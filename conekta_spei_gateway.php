<?php
if (!class_exists('Conekta')) {
    require_once("lib/conekta-php/lib/Conekta.php");
}
/*
 * Title   : Conekta Payment extension for WooCommerce
 * Author  : Conekta.io
 * Url     : https://wordpress.org/plugins/conekta-woocommerce
 */

class WC_Conekta_Spei_Gateway extends WC_Conekta_Plugin
{
    protected $GATEWAY_NAME              = "WC_Conekta_Spei_Gateway";
    protected $use_sandbox_api           = true;
    protected $order                     = null;
    protected $transaction_id            = null;
    protected $transaction_error_message = null;
    protected $conekta_test_api_key      = '';
    protected $conekta_live_api_key      = '';
    protected $publishable_key           = '';

    public function __construct()
    {
        $this->id              = 'conektaspei';
        $this->method_title    = __( 'Conekta Spei', 'woocommerce' );
        $this->has_fields      = true;
        $this->ckpg_init_form_fields();
        $this->init_settings();
        $this->title           = $this->settings['title'];
        $this->description     = '';
        $this->icon            = $this->settings['alternate_imageurl'] ?
                                 $this->settings['alternate_imageurl']  :
                                 WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/images/spei.png';
        $this->use_sandbox_api = strcmp($this->settings['debug'], 'yes') == 0;
        $this->test_api_key    = $this->settings['test_api_key'  ];
        $this->live_api_key    = $this->settings['live_api_key'  ];
        $this->account_owner   = $this->settings['account_owner'];
        $this->secret_key      = $this->use_sandbox_api ?
                                 $this->test_api_key :
                                 $this->live_api_key;

        $this->lang_options = parent::ckpg_set_locale_options()->ckpg_get_lang_options();
        wp_enqueue_script('conekta_spei_gateway', WP_PLUGIN_URL."/".plugin_basename(dirname(__FILE__)).'/assets/js/conekta_spei_gateway.js', array( 'jquery' ), '1.0', true);

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id ,
            array($this, 'process_admin_options')
        );
        add_action(
            'woocommerce_thankyou_' . $this->id,
            array( $this, 'ckpg_thankyou_page')
        );
        add_action(
            'woocommerce_email_before_order_table',
            array($this, 'ckpg_email_reference')
        );
        add_action(
            'woocommerce_email_before_order_table',
            array($this, 'ckpg_email_instructions')
        );
        add_action(
            'woocommerce_api_' . strtolower(get_class($this)),
            array($this, 'ckpg_webhook_handler')
        );
    }

    /**
     * Updates the status of the order.
     * Webhook needs to be added to Conekta account tusitio.com/wc-api/WC_Conekta_Spei_Gateway
     */
    public function ckpg_webhook_handler()
    {
        header('HTTP/1.1 200 OK');
        $body          = @file_get_contents('php://input');
        $event         = json_decode($body, true);
        $conekta_order = $event['data']['object'];
        $charge        = $conekta_order['charges']['data'][0];
        $order_id      = $conekta_order['metadata']['reference_id'];
        $paid_at       = date("Y-m-d", $charge['paid_at']);
        $order         = new WC_Order($order_id);

        if (strpos($event['type'], "order.paid") !== false
            && $charge['payment_method']['type'] === "spei")
            {
                update_post_meta( $order->get_id(), 'conekta-paid-at', $paid_at);
                $order->payment_complete();
                $order->add_order_note(sprintf("Payment completed in Spei and notification of payment received"));

                parent::ckpg_offline_payment_notification($order_id, $conekta_order['customer_info']['name']);
            }
    }

    public function ckpg_init_form_fields()
    {
        //Posiblemente hay que cambiar __('','') por define('','') para la versión mas reciente de PHP
        $elements = (new WC_Order())->get_data_keys();
        sort($elements);
        $order_metadata = array();
        foreach($elements as $key => $value){
            $order_metadata[$value] = $value;
        }
        $elements = (new WC_Order_Item_Product())->get_data_keys();
        sort($elements);
        $product_metadata = array();
        foreach($elements as $key => $value){
            $product_metadata[$value] = $value;
        }
        $this->form_fields = array(
            'enabled' => array(
                'type'        => 'checkbox',
                'title'       => __('Enable/Disable', 'woothemes'),
                'label'       => __('Enable Conekta Spei Payment', 'woothemes'),
                'default'     => 'yes'
            ),
            'debug' => array(
                'type'        => 'checkbox',
                'title'       => __('Testing', 'woothemes'),
                'label'       => __('Turn on testing', 'woothemes'),
                'default'     => 'no'
            ),
            'title' => array(
                'type'        => 'text',
                'title'       => __('Title', 'woothemes'),
                'description' => __('This controls the title which the user sees during checkout.', 'woothemes'),
                'default'     => __('Pago con SPEI', 'woothemes')
            ),
            'account_owner' => array(
                 'type'        => 'Account owner',
                 'title'       => __('Account owner', 'woothemes'),
                 'description' => __('This will be shown in SPEI success page as account description for CLABE reference', 'woothemes'),
                 'default'     => __('Conekta SPEI', 'woothemes')
             ),
            'test_api_key' => array(
                'type'        => 'password',
                'title'       => __('Conekta API Test Private key', 'woothemes'),
                'default'     => __('', 'woothemes')
            ),
            'live_api_key' => array(
                'type'        => 'password',
                'title'       => __('Conekta API Live Private key', 'woothemes'),
                'default'     => __('', 'woothemes')
            ),
            'alternate_imageurl' => array(
                'type'        => 'text',
                'title'       => __('Alternate Image to display on checkout, use fullly qualified url, served via https', 'woothemes'),
                'default'     => __('', 'woothemes')
            ),
            'description' => array(
                'title' => __( 'Description', 'woocommerce' ),
                'type' => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
                'default' =>__( 'Por favor realiza el pago en el portal de tu banco utilizando los datos que te enviamos por correo.', 'woocommerce' ),
                'desc_tip' => true,
            ),
            'instructions' => array(
                'title' => __( 'Instructions', 'woocommerce' ),
                'type' => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page and emails.', 'woocommerce' ),
                'default' =>__( 'Por favor realiza el pago en el portal de tu banco utilizando los datos que te enviamos por correo.', 'woocommerce' ),
                'desc_tip' => true,
            ),
            'order_metadata' => array(
                'title' => __( 'Additional Order Metadata', 'woocommerce' ),
                'type' => 'multiselect',
                'description' => __('More than one option can be chosen.', 'woocommerce'),
                'options' => $order_metadata
            ),
            'product_metadata' => array(
                'title' => __( 'Additional Product Metadata', 'woocommerce' ),
                'type' => 'multiselect',
                'description' => __('More than one option can be chosen.', 'woocommerce'),
                'options' => $product_metadata
            )
        );
    }

    /**
     * Output for the order received page.
     * @param string $order_id
     */
    function ckpg_thankyou_page($order_id) {
        $order = new WC_Order( $order_id );
        echo '<p><h4><strong>'.__('Clabe').':</strong> ' . get_post_meta( esc_html($order->get_id()), 'conekta-clabe', true ). '</h4></p>';
        echo '<p><h4><strong>'.esc_html(__('Beneficiario')).':</strong> '.$this->account_owner.'</h4></p>';
        echo '<p><h4><strong>'.esc_html(__('Banco Receptor')).':</strong>  Sistema de Transferencias y Pagos (STP)<h4></p>';
    }

    /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order
     */
    function ckpg_email_reference($order) {

        if (get_post_meta( $order->get_id(), 'conekta-clabe', true ) != null)
            {
                echo '<p><h4><strong>'.esc_html(__('Clabe')).':</strong> '
                . get_post_meta( esc_html($order->get_id()), 'conekta-clabe', true ). '</h4></p>';
                echo '<p><h4><strong>'.esc_html(__('Beneficiario')).':</strong> '.$this->account_owner.'</h4></p>';
                echo '<p><h4><strong>'.esc_html(__('Banco Receptor')).':</strong>  Sistema de Transferencias y Pagos (STP)<h4></p>';
            }
    }

    /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     */
    public function ckpg_email_instructions( $order, $sent_to_admin = false, $plain_text = false ) {
        $instructions = $this->form_fields['instructions'];
        if ( $instructions && 'on-hold' === $order->get_status() ) {
            echo wpautop( wptexturize( $this->settings['instructions'] ) ) . PHP_EOL;
        }
    }

    public function ckpg_admin_options()
    {
        include_once('templates/spei_admin.php');
    }

    public function payment_fields()
    {
        include_once('templates/spei.php');
    }

    protected function ckpg_send_to_conekta()
    {
        global $woocommerce;
        include_once('conekta_gateway_helper.php');
        \Conekta\Conekta::setApiKey($this->secret_key);
        \Conekta\Conekta::setApiVersion('2.0.0');
        \Conekta\Conekta::setPlugin($this->name);
        \Conekta\Conekta::setPluginVersion($this->version);
        \Conekta\Conekta::setLocale('es');

        $data             = ckpg_get_request_data($this->order);
        $amount           = (int) $data['amount'];
        $items            = $this->order->get_items();
        $taxes            = $this->order->get_taxes();
        $line_items       = ckpg_build_line_items($items, parent::ckpg_get_version());
        $discount_lines   = ckpg_build_discount_lines($data);
        $shipping_lines   = ckpg_build_shipping_lines($data);
        $shipping_contact = ckpg_build_shipping_contact($data);
        $tax_lines        = ckpg_build_tax_lines($taxes);
        $customer_info    = ckpg_build_customer_info($data);
        $order_metadata   = ckpg_build_order_metadata($this->order, $this->settings);
        $order_details    = array(
            'currency'         => $data['currency'],
            'line_items'       => $line_items,
            'customer_info'    => $customer_info,
            'shipping_lines'   => $shipping_lines,
            'discount_lines'   => $discount_lines,
            'tax_lines'        => $tax_lines
        );

        if (!empty($shipping_contact)) {
            $order_details = array_merge($order_details, array('shipping_contact' => $shipping_contact));
        }

        if (!empty($order_metadata)) {
            $order_details = array_merge($order_details, array('metadata' => $order_metadata));
        }

        $order_details = ckpg_check_balance($order_details, $amount);

        try {
            $conekta_order_id = get_post_meta($this->order->get_id(), 'conekta-order-id', true);
            if (!empty($conekta_order_id)) {
                $order = \Conekta\Order::find($conekta_order_id);
                $order->update($order_details);
            } else {
                $order = \Conekta\Order::create($order_details);
            }

            update_post_meta($this->order->get_id(), 'conekta-order-id', $order->id);

            $charge_details = array(
                'payment_method' => array('type' => 'spei'),
                'amount'         => $amount
            );

            $charge               = $order->createCharge($charge_details);
            $this->transaction_id = $charge->id;

            update_post_meta( $this->order->get_id(), 'conekta-id', $charge->id );
            update_post_meta( $this->order->get_id(), 'conekta-creado', $charge->created_at );
            update_post_meta( $this->order->get_id(), 'conekta-expira', $charge->payment_method->expires_at );
            update_post_meta( $this->order->get_id(), 'conekta-clabe', $charge->payment_method->clabe );
            return true;
        } catch(\Conekta\Handler $e) {
            $description = $e->getMessage();

            global $wp_version;
            if (version_compare($wp_version, '4.1', '>=')) {
                wc_add_notice(__('Error: ', 'woothemes') . $description , $notice_type = 'error');
            } else {
                error_log('Gateway Error:' . $description . "\n");
                $woocommerce->add_error(__('Error: ', 'woothemes') . $description);
            }
            return false;
        }
    }

    public function process_payment($order_id)
    {
        global $woocommerce;
        $this->order        = new WC_Order($order_id);
        if ($this->ckpg_send_to_conekta())
            {
                // Mark as on-hold (we're awaiting the notification of payment)
                $this->order->update_status('on-hold', __( 'Awaiting the conekta SPEI payment', 'woocommerce' ));

                // Remove cart
                $woocommerce->cart->empty_cart();
                unset($_SESSION['order_awaiting_payment']);
                $result = array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($this->order)
                );
                return $result;
            }
        else
            {
                $this->ckpg_mark_as_failed_payment();
                global $wp_version;
                if (version_compare($wp_version, '4.1', '>=')) {
                    wc_add_notice(__('Transaction Error: Could not complete the payment', 'woothemes'), $notice_type = 'error');
                } else {
                    $woocommerce->add_error(__('Transaction Error: Could not complete the payment'), 'woothemes');
                }
            }
    }

    protected function ckpg_mark_as_failed_payment()
    {
        $this->order->add_order_note(
            sprintf(
                "%s Spei Payment Failed : '%s'",
                $this->GATEWAY_NAME,
                $this->transaction_error_message
            )
        );
    }

    protected function ckpg_complete_order()
    {
        global $woocommerce;

        if ($this->order->get_status() == 'completed')
            return;

        $this->order->payment_complete();
        $woocommerce->cart->empty_cart();
        $this->order->add_order_note(
            sprintf(
                "%s payment completed with Transaction Id of '%s'",
                $this->GATEWAY_NAME,
                $this->transaction_id
            )
        );

        unset($_SESSION['order_awaiting_payment']);
    }

}

function ckpg_conekta_spei_order_status_completed($order_id = null)
{
    global $woocommerce;
    if (!$order_id){
        $order_id = sanitize_text_field((string) $_POST['order_id']);
    }

    $data = get_post_meta( $order_id );
    $total = $data['_order_total'][0] * 100;

    $params = array();
    $amount = floatval($_POST['amount']);
    if(isset($amount))
    {
        $params['amount'] = round($amount);
    }
}

function ckpg_conektacheckout_add_spei_gateway($methods)
{
    array_push($methods, 'WC_Conekta_Spei_Gateway');
    return $methods;
}

add_filter('woocommerce_payment_gateways',                      'ckpg_conektacheckout_add_spei_gateway');
add_action('woocommerce_order_status_processing_to_completed',  'ckpg_conekta_spei_order_status_completed' );