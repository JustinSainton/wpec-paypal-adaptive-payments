<?php

class WPSC_Payment_Gateway_Paypal_Adaptive_Payments extends WPSC_Payment_Gateway {
  
  const SANDBOX_CHECKOUT_URL = 'https://www.sandbox.paypal.com/webscr?cmd=_ap-payment&paykey=';
  const SANDBOX_PREAPPROVAL_URL = 'https://www.sandbox.paypal.com/webscr?cmd=_ap-preapproval&preapprovalkey=';
  const LIVE_CHECKOUT_URL = 'https://www.paypal.com/webscr?cmd=_ap-payment&paykey=';
  const LIVE_PREAPPROVAL_URL = 'https://www.paypal.com/webscr?cmd=_ap-preapproval&preapprovalkey=';
	private $gateway;
  private $error;
  
	public function __construct() {
		parent::__construct();
		$this->title = __( 'PayPal Adaptive Payments', 'wpsc' );
		require_once( 'php-merchant/gateways/paypal-adaptive-payments.php' );
    
		$this->gateway = new PHP_Merchant_Paypal_Adaptive_Payments();
		$this->gateway->set_options( array(
			'api_username'     => $this->setting->get( 'api_username' ),
			'api_password'     => $this->setting->get( 'api_password' ),
			'api_signature'    => $this->setting->get( 'api_signature' ),
      'app_id'           => $this->setting->get( 'app_id' ),
      'fee_payer'        => $this->setting->get( 'fee_payer' ),
			'cancel_url'       => get_option( 'shopping_cart_url' ),
			'currency'         => $this->get_currency_code(),
			'test'             => (bool) $this->setting->get( 'sandbox_mode' ),
      'payment_type'     => $this->setting->get( 'payment_type' ),
		) );

		add_filter( 'wpsc_purchase_log_gateway_data', array( $this, 'pap_filter_purchase_log_gateway_data' ), 10, 2 );
	}
  
  public function get_gateway() {
    return $this->gateway;
  }
  
	public function pap_filter_purchase_log_gateway_data( $gateway_data, $data ) {
		// Because paypal express checkout API doesn't have full support for discount, we have to manually add an item here
		if ( isset( $gateway_data['discount'] ) && (float) $gateway_data['discount'] != 0 ) {
			$i =& $gateway_data['items'];
			$d =& $gateway_data['discount'];
			$s =& $gateway_data['subtotal'];

			// If discount amount is larger than or equal to the item total, we need to set item total to 0.01
			// because PayPal does not accept 0 item total.
			if ( $d >= $gateway_data['subtotal'] ) {
				$d = $s - 0.01;

				// if there's shipping, we'll take 0.01 from there
				if ( ! empty( $gateway_data['shipping'] ) )
					$gateway_data['shipping'] -= 0.01;
				else
					$gateway_data['amount'] = 0.01;
			}
			$s -= $d;

			$i[] = array(
				'name' => __( 'Discount', 'wpsc' ),
				'amount' => - $d,
				'quantity' => 1,
			);
		}
		return $gateway_data;
	}
  
	protected function get_return_url( $internal=false ) {
    if ( $internal ) {
  		$location = get_option( 'transact_url' );
    }
    else {
  		$location = add_query_arg( array(
  				'sessionid'                => $this->purchase_log->get( 'sessionid' ),
  				'payment_gateway'          => 'paypal-adaptive-payments',
  				'payment_gateway_callback' => 'process_transaction',
  			),
  			get_option( 'transact_url' )
  		);
    }
		return apply_filters( 'wpsc_paypal_adaptive_payments_return_url', $location );
	}
  
	protected function set_purchase_log_for_callbacks( $payment_id = false ) {
		$purchase_log = new WPSC_Purchase_Log( $payment_id );

		if ( ! $purchase_log->exists() )
			return;

		$this->set_purchase_log( $purchase_log );
	}
  
	public function callback_ipn() {
    // IPN is only kept in case a user does not return to the site and trigger the updates.
    if ( isset( $_GET['payment_id'] ) ) {
      $this->set_purchase_log_for_callbacks( $_GET['payment_id'] );
      switch ( $_POST['transaction_type'] ) {
        case 'Adaptive Payment PAY':
        case 'Adaptive Payment Pay':
          $pay_key = wpsc_get_purchase_meta( $_GET['payment_id'], '_wpsc_pap_pay_key', true );
          if ( $pay_key == $_POST['pay_key'] ) { //&& get_post_status( $_GET['payment_id'] ) != 'publish' ) { add back in later pending order statuses (research it)
            wpsc_update_purchase_log_status( $_GET['payment_id'], WPSC_Purchase_Log::ACCEPTED_PAYMENT );
            $this->purchase_log->set( 'processed', WPSC_Purchase_Log::ACCEPTED_PAYMENT );
            $this->purchase_log->save();
            transaction_results( $sessionid, false );
          }
          break;
        case 'Adaptive Payment PREAPPROVAL':
        case 'Adaptive Payment Preapproval':
          $preapproval_key = wpsc_get_purchase_meta( $_GET['payment_id'], '_wpsc_pap_preapproval_key', true );
          if ( $preapproval_key == $_POST['preapproval_key'] ) {
            switch( $_POST['status'] ) {
              case 'CANCELED':
                //edd_update_payment_status( $_GET['payment_id'], 'cancelled' );
                break;
              case 'ACTIVE':
                //if ( get_post_status( $_GET['payment_id'] ) != 'publish' ) {
                //  edd_update_payment_status( $_GET['payment_id'], 'preapproval' );
                //}
                break;
            }
            wpsc_update_purchase_meta( $_GET['payment_id'], '_wpsc_pap_paid', $_POST['current_total_amount_of_all_payments'] );
            wpsc_update_purchase_log_status( $_GET['payment_id'], WPSC_Purchase_Log::ORDER_RECEIVED );
            $this->purchase_log->set( 'processed', WPSC_Purchase_Log::ORDER_RECEIVED );
            $this->purchase_log->save();
            transaction_results( $this->purchase_log->get( 'sessionid' ), false );
          }
          break;
        default:
          //edd_record_gateway_error( __( 'PayPal Adaptive IPN Response', 'wpsc' ), sprintf( __( 'IPN Response for an unknown type: %s', 'wpsc' ), json_encode( $_POST ), $_GET['payment_id'] ) );
          break;
      }
      return true;
    }
    /*if ( function_exists( 'edd_get_purchase_session' ) ) { // UPDATE TO WORK WITH THIS LATER
      // This is a failsafe for IPNs that do not work properly
      $session = edd_get_purchase_session();
      if ( isset( $_GET[ 'payment_key' ] ) ) {
        $payment_key = urldecode( $_GET[ 'payment_key' ] );
      } else if ( $session ) {
        $payment_key = $session[ 'purchase_key' ];
      }
  
      // No key found
      if ( ! isset( $payment_key ) )
        return false;
  
      $payment_id = edd_get_purchase_id_by_key( $payment_key );
      $payment_email = edd_get_payment_user_email( $payment_id );
  
      $payment_token = md5( $payment_id . $payment_email );
  
      if ( isset( $_GET['preapproval_token'] ) ) {
        $token = $_GET['preapproval_token'];
    
        if ( $payment_token == $token && get_post_status( $payment_id ) != 'publish' ) {
          edd_update_payment_status( $payment_id, 'preapproval' );
        }
      }
      elseif ( isset( $_GET['payment_token'] ) ) {
        $token = $_GET['payment_token'];
    
        if ( $payment_token == $token ) {
          $pay_key = get_post_meta( $payment_id, '_wpsc_pap_pay_key', true );
          if ( get_post_status( $_GET['payment_id'] ) != 'publish' ) {
            edd_insert_payment_note( $payment_id, sprintf( __( 'PayPal Transaction ID: %s', 'wpsc' ) , $pay_key ) );
            edd_update_payment_status( $payment_id, 'publish' );
          }
        }
    
      }
    }*/
	}
  
	public function callback_process_transaction() {
		if ( ! isset( $_REQUEST['sessionid'] ) || ! isset( $_REQUEST['token'] ) || ! isset( $_REQUEST['PayerID'] ) )
			return;
		$this->set_purchase_log_for_callbacks();
    $this->callback_process_confirmed_payment();
	}
  
	public function callback_display_paypal_error() {
		add_filter( 'wpsc_get_transaction_html_output', array( $this, 'filter_paypal_error_page' ) );
	}

	public function callback_display_generic_error() {
		add_filter( 'wpsc_get_transaction_html_output', array( $this, 'filter_generic_error_page' ) );
	}

	public function callback_process_confirmed_payment() {
		$args = array_map( 'urldecode', $_GET );
		extract( $args, EXTR_SKIP );
		if ( ! isset( $sessionid ) || ! isset( $token ) || ! isset( $PayerID ) )
			return;

		$this->set_purchase_log_for_callbacks();

		$total = $this->convert( $this->purchase_log->get( 'totalprice' ) );
		$options = array(
			'token'    => $token,
			'payer_id' => $PayerID,
			'invoice'  => $this->purchase_log->get( 'sessionid' ),
		);
		$options += $this->checkout_data->get_gateway_data();
		$options += $this->purchase_log->get_gateway_data( parent::get_currency_code(), $this->get_currency_code() );

		$response = $this->gateway->purchase( $options );
		$location = remove_query_arg( 'payment_gateway_callback' );

		if ( $response->has_errors() ) {
			$_SESSION['paypal_adaptive_payments_errors'] = serialize( $response->get_errors() );
			$location = add_query_arg( array( 'payment_gateway_callback' => 'display_paypal_error' ) );
		} elseif ( $response->is_payment_completed() || $response->is_payment_pending() ) {
			$location = remove_query_arg( 'payment_gateway' );

			if ( $response->is_payment_completed() ) {
				$this->purchase_log->set( 'processed', WPSC_Purchase_Log::ACCEPTED_PAYMENT );
        wpsc_update_purchase_log_status( $sessionid, WPSC_Purchase_Log::ACCEPTED_PAYMENT, 'sessionid' );
      }
			else {
				$this->purchase_log->set( 'processed', WPSC_Purchase_Log::ORDER_RECEIVED );
        wpsc_update_purchase_log_status( $sessionid, WPSC_Purchase_Log::ORDER_RECEIVED, 'sessionid' );
      }
			$this->purchase_log->set( 'transactid', $response->get( 'transaction_id' ) )
			                   ->set( 'date', time() )
			                   ->save();
		} else {
			$location = add_query_arg( array( 'payment_gateway_callback' => 'display_generic_error' ) );
		}

		wp_redirect( $location );
		exit;
	}
  
	public function filter_paypal_error_page() {
		$errors = unserialize( $_SESSION['paypal_adaptive_payments_errors'] );
		ob_start();
		?>
		<p>
			<?php _e( 'Sorry, your transaction could not be processed by PayPal. Please contact the site administrator. The following errors are returned:' ); ?>
		</p>
		<ul>
			<?php foreach ( $errors as $error ): ?>
				<li><?php echo esc_html( $error['details'] ) ?> (<?php echo esc_html( $error['code'] ); ?>)</li>
			<?php endforeach; ?>
		</ul>
		<p><a href="<?php echo esc_attr( get_option( 'shopping_cart_url' ) ); ?>"><?php _e( 'Click here to go back to the checkout page.') ?></a></p>
		<?php
		$output = apply_filters( 'wpsc_paypal_adaptive_payments_gateway_error_message', ob_get_clean(), $errors );
		return $output;
	}

	public function filter_generic_error_page() {
		ob_start();
		?>
			<p><?php _e( 'Sorry, but your transaction could not be processed by PayPal for some reason. Please contact the site administrator.' ); ?></p>
			<p><a href="<?php echo esc_attr( get_option( 'shopping_cart_url' ) ); ?>"><?php _e( 'Click here to go back to the checkout page.') ?></a></p>
		<?php
		$output = apply_filters( 'wpsc_paypal_adaptive_payments_generic_error_message', ob_get_clean() );
		return $output;
	}
  
	public function setup_form() {
		$paypal_currency = $this->get_currency_code();
		?>
		<tr>
			<td>
				<label for="wpsc-paypal-adaptive-api-username"><?php _e( 'API Username', 'wpsc' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'api_username' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'api_username' ) ); ?>" id="wpsc-paypal-adaptive-api-username" />
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-paypal-adaptive-api-password"><?php _e( 'API Password', 'wpsc' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'api_password' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'api_password' ) ); ?>" id="wpsc-paypal-adaptive-api-password" />
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-paypal-adaptive-api-signature"><?php _e( 'API Signature', 'wpsc' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'api_signature' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'api_signature' ) ); ?>" id="wpsc-paypal-adaptive-api-signature" />
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-paypal-adaptive-app-id"><?php _e( 'APP ID', 'wpsc' ); ?></label>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $this->setting->get_field_name( 'app_id' ) ); ?>" value="<?php echo esc_attr( $this->setting->get( 'app_id' ) ); ?>" id="wpsc-paypal-adaptive-app-id" />
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Sandbox Mode', 'wpsc' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'sandbox_mode' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wpsc' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( (bool) $this->setting->get( 'sandbox_mode' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'sandbox_mode' ) ); ?>" value="0" /> <?php _e( 'No', 'wpsc' ); ?></label>
			</td>
		</tr>
    <tr>
			<td>
				<label><?php _e( 'IPN', 'wpsc' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'ipn' ) ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'ipn' ) ); ?>" value="1" /> <?php _e( 'Yes', 'wpsc' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( (bool) $this->setting->get( 'ipn' ), false ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'ipn' ) ); ?>" value="0" /> <?php _e( 'No', 'wpsc' ); ?></label>
			</td>
		</tr>
		<tr>
			<td>
				<label for="wpsc-paypal-adaptive-receivers"><?php _e( 'PayPal Adaptive Receivers', 'wpsc' ); ?></label>
			</td>
			<td>
				<textarea rows="5" name="<?php echo esc_attr( $this->setting->get_field_name( 'receivers' ) ); ?>" id="wpsc-paypal-adaptive-receivers"><?php echo esc_attr( $this->setting->get( 'receivers' ) ); ?></textarea>
        <p class="description"><?php _e( 'Enter each receiver email on a new line and add a pipe bracket with the percentage of the payout afterwards. NOTE: The percentages MUST equal 100% and you can only have a maximum of 6 receivers. Example:<br /><br />test@test.com|50<br />test2@test.com|30<br />test3@test.com|20', 'wpsc' ); ?></p>
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Payment Type', 'wpsc' ); ?></label>
			</td>
			<td>
				<label><input <?php checked( $this->setting->get( 'payment_type' ), 'chained' ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'payment_type' ) ); ?>" value="chained" /> <?php _e( 'Chained Payments', 'wpsc' ); ?></label>&nbsp;&nbsp;&nbsp;
				<label><input <?php checked( $this->setting->get( 'payment_type' ), 'parallel' ); ?> type="radio" name="<?php echo esc_attr( $this->setting->get_field_name( 'payment_type' ) ); ?>" value="parallel" /> <?php _e( 'Parallel Payments', 'wpsc' ); ?></label>
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Require Preapproval', 'wpsc' ); ?></label>
			</td>
			<td>
        <input type="hidden" name="<?php echo esc_attr( $this->setting->get_field_name( 'require_preapproval' ) ); ?>" value="" />
				<label><input <?php checked( $this->setting->get( 'require_preapproval' ), 'require_preapproval' ); ?> type="checkbox" name="<?php echo esc_attr( $this->setting->get_field_name( 'require_preapproval' ) ); ?>" value="require_preapproval" /> <?php _e( 'Enable this option to require Pre-Approval before charging a customer', 'wpsc' ); ?></label>
			</td>
		</tr>
		<tr>
			<td>
				<label><?php _e( 'Fee Payment', 'wpsc' ); ?></label>
			</td>
			<td>
				<label>
          <select name="<?php echo esc_attr( $this->setting->get_field_name( 'fee_payer' ) ); ?>">
            <option value="EACHRECEIVER"<?php selected( $this->setting->get( 'fee_payer' ), 'EACHRECEIVER' ); ?>><?php _e( 'Each Receiver', 'wpsc' ); ?></option>
            <option value="SENDER"<?php selected( $this->setting->get( 'fee_payer' ), 'SENDER' ); ?>><?php _e( 'Sender (only the customer)', 'wpsc' ); ?></option>
            <option value="PRIMARYRECEIVER"<?php selected( $this->setting->get( 'fee_payer' ), 'PRIMARYRECEIVER' ); ?>><?php _e( 'Primary Receiver (first person in receivers list)', 'wpsc' ); ?></option>
            <option value="SECONDARYONLY"<?php selected( $this->setting->get( 'fee_payer' ), 'SECONDARYONLY' ); ?>><?php _e( 'Secondary Only (excluding the first person in receivers list)', 'wpsc' ); ?></option>
          </select>
          <?php _e( 'Enable this option to require Pre-Approval before charging a customer', 'wpsc' ); ?>
        </label>
			</td>
		</tr>
		<?php if ( ! $this->is_currency_supported() ): ?>
			<tr>
				<td colspan="2">
					<h4><?php _e( 'Currency Conversion', 'wpsc' ); ?></h4>
				</td>
			</tr>
			<tr>
				<td colspan="2">
					<p><?php _e( 'Your base currency is currently not accepted by PayPal. As a result, before a payment request is sent to PayPal, WP e-Commerce has to convert the amounts into one of PayPal supported currencies. Please select your preferred currency below.', 'wpsc' ); ?></p>
				</td>
			</tr>
			<tr>
				<td>
					<label for "wpsc-paypal-adaptive-currency"><?php _e( 'PayPal Currency', 'wpsc' ); ?></label>
				</td>
				<td>
					<select name="<?php echo esc_attr( $this->setting->get_field_name( 'currency' ) ); ?>" id="wpsc-paypal-adaptive-currency">
						<?php foreach ( $this->gateway->get_supported_currencies() as $currency ): ?>
							<option <?php selected( $currency, $paypal_currency ); ?> value="<?php echo esc_attr( $currency ); ?>"><?php echo esc_html( $currency ); ?></option>
						<?php endforeach ?>
					</select>
				</td>
			</tr>
		<?php endif ?>

		<?php
	}
  
	protected function is_currency_supported() {
		$code = parent::get_currency_code();
		return in_array( $code, $this->gateway->get_supported_currencies() );
	}

	public function get_currency_code() {
		$code = parent::get_currency_code();
		if ( ! in_array( $code, $this->gateway->get_supported_currencies() ) )
			$code = $this->setting->get( 'currency', 'USD' );
		return $code;
	}

	protected function convert( $amt ) {
		if ( $this->is_currency_supported() )
			return $amt;

		return wpsc_convert_currency( $amt, parent::get_currency_code(), $this->get_currency_code() );
	}

	public function process() {
		$options = array(
			'return_url' => $this->get_return_url(),
			'invoice'    => $this->purchase_log->get( 'id' ),
      'app_id'     => $this->setting->get( 'app_id' ),
      
		); // get rid of?
		$options += $this->checkout_data->get_gateway_data(); // get rid of?
		$options += $this->purchase_log->get_gateway_data( parent::get_currency_code(), $this->get_currency_code() ); // get rid of?
    $total = $this->convert( $this->purchase_log->get( 'totalprice' ) );
    $receivers = $this->gateway->divide_total( apply_filters( 'paypal_adaptive_receivers', $this->setting->get( 'receivers') ), $total );
    $this->gateway->set_options( array(
      'receivers'   => $receivers,
      'return_url'  => $this->get_return_url(),
    ));
    
    $type      = 'pay';
    $token = md5( $this->purchase_log->get( 'id' ) . 'email' ); // add in email here?
    if ( $this->setting->get( 'require_preapproval') ) { // check for setting about preapproval
      $response = $this->gateway->preapproval( $this->purchase_log->get( 'id' ), $total, $token );
      $type = 'preapproval';
    }
    else {
      $response = $this->gateway->pay( $this->purchase_log->get( 'id' ), $receivers, $token );
    }
    $responsecode = strtoupper( $response['responseEnvelope']['ack'] );
    if ( ( $responsecode == 'SUCCESS' || $responsecode == 'SUCCESSWITHWARNING' ) ) {
    
      if ( isset( $response['preapprovalKey']) ) {
        $preapproval_key = $response['preapprovalKey'];
        $preapproval_details = $this->gateway->get_preapproval_details( $preapproval_key );
        
        wpsc_add_purchase_meta( $this->purchase_log->get( 'id' ), '_wpsc_pap_preapproval_key', $preapproval_key, true );
        wpsc_add_purchase_meta( $this->purchase_log->get( 'id' ), '_wpsc_pap_paid', 0, true );
        
        $url = ( $this->setting->get( 'sandbox_mode' ) ? self::SANDBOX_PREAPPROVAL_URL : self::LIVE_PREAPPROVAL_URL );
        if ( function_exists( 'wpmd_is_device' ) && wpmd_is_device() ) {
          $url =  add_query_arg( 'expType', 'mini', $url );
        }
        wp_redirect( $url . $preapproval_key );
        exit;
      }
      else {
        $pay_key = $response['payKey'];
        
        wpsc_add_purchase_meta( $this->purchase_log->get( 'id' ), '_wpsc_pap_pay_key', $pay_key, true );
        
        $url = ( $this->setting->get( 'sandbox_mode' ) ? self::SANDBOX_CHECKOUT_URL : self::LIVE_CHECKOUT_URL );
        if ( function_exists( 'wpmd_is_device' ) && wpmd_is_device() ) {
          $url =  add_query_arg( 'expType', 'mini', $url );
        }
        wp_redirect( $url . $pay_key );
        exit;
      }
    
    } else {
			$_SESSION['paypal_adaptive_payments_errors'] = serialize( $response );
			$url = add_query_arg( array(
				'payment_gateway'          => 'paypal-adaptive-payment',
				'payment_gateway_callback' => 'display_paypal_error',
			), $this->get_return_url() );
    }
	}
  
  public function wpsc_pap_process_preapproval() {
    if ( isset( $_GET['id'] ) && isset( $_GET['action'] ) ) { // add in the check for the settings page here (if set to preapproval otherwise die);
      $processed = false;
      $item_id = $_GET['id'];
      
      $remove_id = '';
      if ( !isset( $_GET['c'] ) ) {
        $remove_id = 'id';
      }
      // Process Preapproval
      if ( $_GET['action'] == 'process_preapproval' ) {
        $preapproval_key = wpsc_get_purchase_meta( $item_id, '_wpsc_pap_preapproval_key', true );
        $preapproval_details = $this->gateway->get_preapproval_details( $preapproval_key );
        if( $preapproval_details ) {
          $sender_email     = $preapproval_details[ 'senderEmail' ];
          $amount           = $preapproval_details[ 'maxTotalAmountOfAllPayments' ];
          $paid             = wpsc_get_purchase_meta( $item_id, '_wpsc_pap_paid', true ) ? wpsc_get_purchase_meta( $payment_id, '_wpsc_pap_paid', true ) : 0;
          if ( $amount > $paid ) {
            $receivers = $this->setting->get( 'receivers');
            
            $this->set_purchase_log_for_callbacks( $item_id );
            $this->gateway->set_options( array(
              'return_url'  => $this->get_return_url( true ),
            ));
            $payment = $this->gateway->pay_preapprovals( $item_id, $preapproval_key, $sender_email, $amount, $receivers );
            if ( $payment ) {
              $responsecode = strtoupper( $payment['responseEnvelope']['ack'] );
              $paymentStatus = strtoupper( $payment[ 'paymentExecStatus' ] );
              if ( ( $responsecode == 'SUCCESS' || $responsecode == 'SUCCESSWITHWARNING' ) && ( $paymentStatus == 'COMPLETED' ) ) {
                $pay_key = $payment['payKey'];
                wpsc_add_purchase_meta( $item_id, '_wpsc_pap_pay_key', $pay_key );
                wpsc_add_purchase_meta( $item_id, '_wpsc_pap_preapproval_paid', true );
                wpsc_update_purchase_log_status( $item_id, WPSC_Purchase_Log::ACCEPTED_PAYMENT );
                wp_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2', $remove_id ), add_query_arg( 'process_preapproval', 'success', stripslashes( $_SERVER['REQUEST_URI'] ) ) ) );
                exit;
              } else {
                $this->wpsc_pap_display_error_message( $payment );
              }
            } else {
              $this->wpsc_pap_display_error_message( $payment );
            }
          } else {
            $this->wpsc_pap_display_error_message( $preapproval_details );
          }
        } else {
          $this->wpsc_pap_display_error_message( $preapproval_details );
        }
      
      }
      // Process a cancellation of the preapproval
      if ( $_GET['action'] == 'cancel_preapproval' ) {
        $preapproval_key = wpsc_get_purchase_meta( $item_id, '_wpsc_pap_preapproval_key', true );
        $cancellation = $this->gateway->cancel_preapprovals( $preapproval_key );
        if ( $cancellation ) {
          $responsecode = strtoupper( $cancellation['responseEnvelope']['ack'] );
          if ( ( $responsecode == 'SUCCESS' || $responsecode == 'SUCCESSWITHWARNING' ) ) {
            //edd_update_payment_status( $payment_id, 'cancelled' ); // payment status?
            wpsc_add_purchase_meta( $item_id, '_wpsc_pap_preapproval_cancelled', true );
            wp_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2', $remove_id ), add_query_arg( 'cancel_preapproval', 'success', stripslashes( $_SERVER['REQUEST_URI'] ) ) ) );
            exit;
          } else {
            $this->wpsc_pap_display_error_message( $cancellation );
          }
        } else {
          $this->wpsc_pap_display_error_message( $cancellation );
        }
      }
    }
  }
  
  public function wpsc_pap_display_error_message( $raw_data ) {
    $this->error = __( 'An unknown error occured', 'wpsc' );
    if ( isset( $raw_data['error'] ) ) {
      $count = 0;
      foreach ( $raw_data['error'] as $e ) {
        $count++;
        $br = '';
        if ( count( $raw_data['error'] ) == $count ) {
          $br = '<br />';
        }
        if ( isset( $e['errorId'] ) && isset( $e['message'] ) ) {
          $this->error = '<strong>Error ' . $e['errorId'] . '</strong>: ' . $e['message'] . $br;
        }
      }
    }
    add_action( 'admin_notices', array( $this, 'wpsc_pap_preapproval_errors' ) );
  }
  
  public function wpsc_pap_preapproval_errors() {
    ?>
    	<div id="message" class="error fade">
    		<p><?php echo $this->error; ?></p>
    	</div>
    <?php
  }
  
}