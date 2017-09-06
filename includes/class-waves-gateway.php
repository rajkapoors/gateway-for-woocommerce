<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gateway class
 */
class WcWavesGateway extends WC_Payment_Gateway
{
    public $id;
    public $title;
    public $form_fields;
    public $addresses;

    public function __construct()
    {

        $this->id          			= 'waves';
        $this->title       			= $this->get_option('title');
        $this->description 			= $this->get_option('description');
        $this->address   			= $this->get_option('address');
        $this->secret   			= $this->get_option('secret');
        $this->order_button_text 	= __('Awaiting transfer..','waves-gateway-for-woocommerce');
        $this->has_fields 			= true;

        $this->initFormFields();

        $this->initSettings();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
            $this,
            'process_admin_options',
        ));
        add_action('wp_enqueue_scripts', array($this, 'paymentScripts'));

        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyouPage'));        

    }

    public function initFormFields()
    {
        parent::init_form_fields();
        $this->form_fields = WavesSettings::fields();
    }

    public function initSettings()
    {
    	// sha1( get_bloginfo() )
        parent::init_settings();
    }
   
    public function payment_fields()
    {
    	global $woocommerce;
    	$woocommerce->cart->get_cart();

        $user       = wp_get_current_user();
        $total_converted_old = WavesExchange::convert(get_woocommerce_currency(), $this->get_order_total());
        
		$Price = file_get_contents("http://marketdata.wavesplatform.com/api/trades/AxAmJaro7BJ4KasYiZhw7HkjwgYtt2nekPuF2CN9LMym/WAVES/1");
		$Price_JSON = json_decode( $Price, true);
		$Price_WNET = $Price_JSON[0]['price'];
        $total_waves_converted = round($total_converted_old / $Price_WNET, 0, PHP_ROUND_HALF_UP);
        $total_waves_formatted = number_format($total_waves_converted,0);
		$total_waves = $total_waves_converted;
		
		$total_converted = round($total_converted_old / $Price_WNET,0, PHP_ROUND_HALF_UP);
		
        $destination_tag = hexdec( substr(sha1(current_time(timestamp,1) . key ($woocommerce->cart->cart_contents )  ), 0, 7) );
        $base58 = new StephenHill\Base58();
        $destination_tag_encoded = $base58->encode(strval($destination_tag));
        // set session data 
        WC()->session->set('waves_payment_total', $total_waves);
        WC()->session->set('waves_destination_tag', $destination_tag_encoded);
        WC()->session->set('waves_data_hash', sha1( $this->secret . $total_converted ));

        echo '<div id="waves-form">';
        //QR uri
        
		$url = "waves://". $this->address ."?amount=". $total_waves."&asset=AxAmJaro7BJ4KasYiZhw7HkjwgYtt2nekPuF2CN9LMym&attachment=".$destination_tag;

        echo '<div class="waves-container">';
        echo '<div>';
      

        if ($this->description) {
        	echo '<div class="separator"></div>';
        	echo '<div id="waves-description">';
            echo apply_filters( 'wc_waves_description', wpautop(  $this->description ) );
            echo '</div>';
        }


        echo '<div class="separator"></div>';
        echo '<div class="waves-container">';
        
        // echo $destination_tag_encoded;
        
        $fiat_total = $this->get_order_total();
        $rate = $total_converted / $fiat_total;
        echo '<label class="waves-label">(1'. get_woocommerce_currency() .' = '.round($rate,6).' WNET)</label>';
        echo '<p class="waves-amount"><span class="copy" data-success-label="'. __('copied','waves-gateway-for-woocommerce') .'" data-clipboard-text="' . esc_attr($total_converted) . '">' . esc_attr($total_converted) . '</span></p>';
        echo '</div>';
        echo '</div>';
        echo '<div class="separator"></div>';
        echo '<div class="waves-container">';
        echo '<label class="waves-label">' . __('destination address', 'waves-gateway-for-woocommerce') . '</label>';
        echo '<p class="waves-address"><span class="copy" data-success-label="'. __('copied','waves-gateway-for-woocommerce') .'" data-clipboard-text="' . esc_attr($this->address) . '">' . esc_attr($this->address) . '</span></p>';
        echo '</div>';
        echo '<div class="separator"></div>';
        echo '<div class="waves-container">';
        echo '<label class="waves-label">' . __('attachment', 'waves-gateway-for-woocommerce') . '</label>';
        echo '<p class="waves-address"><span class="copy" data-success-label="'. __('copied','waves-gateway-for-woocommerce') .'" data-clipboard-text="' . esc_attr($destination_tag) . '">' . esc_attr($destination_tag) . '</span></p>';
        echo '</div>';
        echo '<div class="separator"></div>';

        echo '</div>';
        echo '<div id="waves-qr-code" data-contents="'. $url .'"></div>';
        echo '<div class="separator"></div>';
        echo '<div class="waves-container">';
        echo '<p>'. sprintf(__('Send a payment of exactly %s to the address above (click the links to copy or scan the QR code). We will check in the background and notify you when the payment has been validated.', 'waves-gateway-for-woocommerce'), '<strong>'. esc_attr($total_converted) .'</strong>' ) .'</p>';
        echo '<p>'. sprintf(__('Please send your payment within %s.', 'waves-gateway-for-woocommerce'), '<strong><span class="waves-countdown" data-minutes="10">10:00</span></strong>' ) .'</p>';
        echo '<p class="small">'. __('When the timer reaches 0 this form will refresh and update the attachment as well as the total amount using the latest conversion rate.', 'waves-gateway-for-woocommerce') .'</p>';
        echo '</div>';
        
        echo '<input type="hidden" name="tx_hash" id="tx_hash" value="0"/>';
        echo '</div>';

    }

    public function process_payment( $order_id ) 
    {
    	global $woocommerce;
        $this->order = new WC_Order( $order_id );
        
	    $payment_total   = WC()->session->get('waves_payment_total');
        $destination_tag = WC()->session->get('waves_destination_tag');

	    $ra = new WavesApi($this->address);
	    $transaction = $ra->getTransaction( $_POST['tx_hash']);
	    
        if($transaction->attachment != $destination_tag) {
	    	exit('destination');
	    	return array(
		        'result'    => 'failure',
		        'messages' 	=> 'attachment mismatch'
		    );
	    }
		
		if($transaction->assetId != 'AxAmJaro7BJ4KasYiZhw7HkjwgYtt2nekPuF2CN9LMym' ) {
			return array(
		        'result'    => 'failure',
		        'messages' 	=> 'Wrong Asset'
		    );
		}
		
	    if($transaction->amount != $payment_total) {
	    	return array(
		        'result'    => 'failure',
		        'messages' 	=> 'amount mismatch'
		    );
	    }
	    
        $this->order->payment_complete();

        $woocommerce->cart->empty_cart();
	   
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($this->order)
        );
	}

    public function paymentScripts()
    {
        wp_enqueue_script('qrcode', plugins_url('assets/js/jquery.qrcode.min.js', WcWaves::$plugin_basename), array('jquery'), WcWaves::$version, true);
        wp_enqueue_script('initialize', plugins_url('assets/js/jquery.initialize.js', WcWaves::$plugin_basename), array('jquery'), WcWaves::$version, true);
        
        wp_enqueue_script('clipboard', plugins_url('assets/js/clipboard.js', WcWaves::$plugin_basename), array('jquery'), WcWaves::$version, true);
        wp_enqueue_script('woocommerce_waves_js', plugins_url('assets/js/waves.js', WcWaves::$plugin_basename), array(
            'jquery',
        ), WcWaves::$version, true);
        wp_enqueue_style('woocommerce_waves_css', plugins_url('assets/css/waves.css', WcWaves::$plugin_basename), array(), WcWaves::$version);

        // //Add js variables
        $waves_vars = array(
            'wc_ajax_url' => WC()->ajax_url(),
            'nonce'      => wp_create_nonce("waves-gateway-for-woocommerce"),
        );

        wp_localize_script('woocommerce_waves_js', 'waves_vars', apply_filters('waves_vars', $waves_vars));

    }

}