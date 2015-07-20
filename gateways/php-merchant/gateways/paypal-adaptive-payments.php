<?php

class PHP_Merchant_Paypal_Adaptive_Payments {

	const SANDBOX_URL = 'https://svcs.sandbox.paypal.com/AdaptivePayments/';
	const LIVE_URL = 'https://svcs.paypal.com/AdaptivePayments/';

  private $options = array();
  private static $supported_currencies = array(
    'AUD',
    'BRL',
    'CAD',
    'CHF',
    'CZK',
    'DKK',
    'EUR',
    'GBP',
    'HKD',
    'HUF',
    'ILS',
    'JPY',
    'MXN',
    'MYR',
    'NOK',
    'NZD',
    'PHP',
    'PLN',
    'SEK',
    'SGD',
    'THB',
    'TWD',
    'USD',
  );

  public function get_supported_currencies() {
    return self::$supported_currencies;
  }

  public function get_envelope() {
    return array(
      'errorLanguage' => 'en_US',
      'detailLevel' => 'returnAll'
    );
  }

  public function get_headers() {
    return array(
      'X-PAYPAL-SECURITY-USERID: ' . $this->options['api_username'],
      'X-PAYPAL-SECURITY-PASSWORD: ' . $this->options['api_password'],
      'X-PAYPAL-SECURITY-SIGNATURE: ' . $this->options['api_signature'],
      'X-PAYPAL-REQUEST-DATA-FORMAT: JSON',
      'X-PAYPAL-RESPONSE-DATA-FORMAT: JSON',
      'X-PAYPAL-APPLICATION-ID: ' . $this->options['app_id']
    );
  }

  public function get_preapproval_details( $preapproval_key ) {
    $response = false;
    $create_packet = array(
      'preapprovalKey'  => $preapproval_key,
      'requestEnvelope' => $this->get_envelope()
    );
    $response = $this->_paypal_send( $create_packet, 'PreapprovalDetails' );
    return $response;
  }

	protected function get_notify_url( $payment_id ) {
		$location = add_query_arg( array(
			'payment_gateway'          => 'paypal-adaptive-payments',
			'payment_gateway_callback' => 'ipn',
      'payment_id'               => $payment_id,
		), home_url( 'index.php' ) );

		return apply_filters( 'wpsc_paypal_adaptive_payments_notify_url', $location );
	}

  public function pay_preapprovals( $payment_id, $preapproval_key, $sender_email, $amount, $receivers=null ) {
    $pay_response = false;
    $receivers = isset( $receivers ) ? $receivers : apply_filters( 'wpsc_pap_adaptive_receivers', $this->options['receivers'], $payment_id );

    $receivers = $this->divide_total( $receivers, $amount );

    $create_packet = array(
      'actionType'         => 'CREATE',
      'preapprovalKey'     => $preapproval_key,
      'senderEmail'        => $sender_email,
      'clientDetails'      => array( 'applicationId' => $this->options['app_id'], 'ipAddress' => $_SERVER['SERVER_ADDR'] ),
      'feesPayer'          => isset( $this->options['fee_payer'] ) ? $this->options['fee_payer'] : 'EACHRECEIVER',
      'currencyCode'       => $this->options['currency'],
      'receiverList'       => array( 'receiver' => $receivers ),
      'returnUrl'          => $this->options['return_url'],
      'cancelUrl'          => $this->options['cancel_url'],
      'ipnNotificationUrl' => $this->get_notify_url( $payment_id ),
      'requestEnvelope'    => $this->get_envelope()
    );
    $pay_response = $this->_paypal_send( $create_packet, 'Pay' );
    $responsecode = strtoupper( $pay_response['responseEnvelope']['ack'] );
    if ( ( $responsecode == 'SUCCESS' || $responsecode == 'SUCCESSWITHWARNING' ) ) {
      $set_response = $this->set_payment_options( $pay_response['payKey'] );
      $responsecode = strtoupper( $set_response['responseEnvelope']['ack'] );
      if ( ( $responsecode == 'SUCCESS' || $responsecode == 'SUCCESSWITHWARNING' ) ) {
        $execute_response = $this->execute_payment( $pay_response['payKey'] );
        return $execute_response;
      }
      else {
        return $pay_response;
      }
    }
    else {
      return $pay_response;
    }
  }

  public function cancel_preapprovals( $preapproval_key ) {
    $create_packet = array(
      'requestEnvelope' => $this->get_envelope(),
      'preapprovalKey'  => $preapproval_key
    );
    $response = $this->_paypal_send( $create_packet, 'CancelPreapproval' );
    return $response;
  }

  public function preapproval( $payment_id, $amount, $reference_token, $starting_date=null, $ending_date=null ) {

    $params = array(
      'preapproval_token' => $reference_token,
    );
    $create_packet = array(
      'clientDetails'               => array( 'applicationId' => $this->options['app_id'], 'ipAddress' => $_SERVER['SERVER_ADDR'] ),
      'currencyCode'                => $this->options['currency'],
      'returnUrl'                   => $this->options['return_url'],
      'cancelUrl'                   => $this->options['cancel_url'],
      'ipnNotificationUrl'          => $this->get_notify_url( $payment_id ),
      'requestEnvelope'             => $this->get_envelope(),
      'startingDate'                => isset( $starting_date ) ? $starting_date : apply_filters( 'preapproval_start_date', date( 'c', time() ) ),
      'endingDate'                  => isset( $ending_date ) ? $ending_date : apply_filters( 'preapproval_end_date', date( 'c', time() + 365*86400 ) ),
      'maxAmountPerPayment'         => floatval( $amount ),
      'maxTotalAmountOfAllPayments' => floatval( $amount ),
      'maxNumberOfPayments'         => 1,
      'maxNumberOfPaymentsPerPeriod' => 1
    );
    $response = $this->_paypal_send( $create_packet, 'Preapproval' );
    return $response;
  }

  public function pay( $payment_id, $receivers, $reference_token ) {
    $params = array(
      'payment_token' => $reference_token,
    );
    $create_packet = array(
      'actionType'         => 'CREATE',
      'clientDetails'      => array( 'applicationId' => $this->options['app_id'], 'ipAddress' => $_SERVER['SERVER_ADDR'] ),
      'feesPayer'          => isset( $this->options['fee_payer'] ) ? $this->options['fee_payer'] : 'EACHRECEIVER',
      'currencyCode'       => $this->options['currency'],
      'receiverList'       => array( 'receiver' => $this->options['receivers'] ),
      'returnUrl'          => $this->options['return_url'],
      'cancelUrl'          => $this->options['cancel_url'],
      'ipnNotificationUrl' => $this->get_notify_url( $payment_id ),
      'requestEnvelope'    => $this->get_envelope()
    );
    $pay_response = $this->_paypal_send( $create_packet, 'Pay' );
    $responsecode = strtoupper( $pay_response['responseEnvelope']['ack'] );
    if ( ( $responsecode == 'SUCCESS' || $responsecode == 'SUCCESSWITHWARNING' ) ) {
      $set_response = $this->set_payment_options( $pay_response['payKey'] );
      $responsecode = strtoupper( $set_response['responseEnvelope']['ack'] );
      //if ( ( $responsecode == 'SUCCESS' || $responsecode == 'SUCCESSWITHWARNING' ) ) {
      //  $execute_response = $this->execute_payment( $pay_response['payKey'] );
      //  return $execute_response;
      //}
      //else {
        return $pay_response;
      //}
    }
    else {
      return $pay_response;
    }
  }

  public function execute_payment( $pay_key ) {
    $packet = array(
      'requestEnvelope' => $this->get_envelope(),
      'payKey' => $pay_key
    );
    return $this->_paypal_send( $packet, 'ExecutePayment' );
  }

  public function set_payment_options( $pay_key ) {
    $packet = array(
      'requestEnvelope' => $this->get_envelope(),
      'payKey' => $pay_key,
      'senderOptions' => array(
        'referrerCode' => 'WPeC_Cart_AP'
      )
    );
    return $this->_paypal_send( $packet, 'SetPaymentOptions' );
  }

  public function get_payment_details( $pay_key ) {
    $packet = array(
      'requestEnvelope' => $this->get_envelope(),
      'payKey' => $pay_key
    );
    return $this->_paypal_send( $packet, 'PaymentDetails' );
  }

  public function get_payment_options( $pay_key ) {
    $packet = array(
      'requestEnvelope' => $this->get_envelope(),
      'payKey' => $pay_key
    );

    return $this->_paypal_send( $packet, 'GetPaymentOptions' );
  }

  public function _send_url() {
    $url = self::LIVE_URL;
    if ( $this->options['test'] ) {
      $url = self::SANDBOX_URL;
    }
    return $url;
  }

  public function _paypal_send( $data, $call ) {
    //open connection
    $ch = curl_init();
    curl_setopt( $ch, CURLOPT_URL, $this->_send_url() . $call );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
    curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, FALSE );
    curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, FALSE );
    curl_setopt( $ch, CURLOPT_POST, TRUE );
    curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, $this->get_headers() );
    $response = json_decode( curl_exec( $ch ), true );
    curl_close( $ch );
    return $response;

  }

  public function divide_total( $adaptive_receivers, $total ) {
    $receivers = array();
    if ( ! is_array( $adaptive_receivers ) ) {
      $adaptive_receivers = explode( "\n", $adaptive_receivers );
    }
    $total_receivers = count( $adaptive_receivers );
    $new_total = 0;
    $cycle = 0;
    foreach( $adaptive_receivers as $key => $receiver ) {
      $cycle++;
      if ( ! is_array( $receiver ) ) {
        $receiver = explode( '|', $receiver );
      }
      $amount = round( $total / 100 * trim( $receiver[1] ), 2 );

      if ( isset( $this->options['payment_type'] ) && $this->options['payment_type'] == 'parallel') {
        $receivers[ $key ] = array(
          'email' => trim( $receiver[0] ),
          'amount' => $amount
        );
      }
      else {
        if ( $cycle == 1 ) {
          $receivers[ $key ] = array(
            'email' => trim( $receiver[0] ),
            'amount' => $total,
            'primary' => true
          );
        }
        else {
          $receivers[ $key ] = array(
            'email' => trim( $receiver[0] ),
            'amount' => $amount,
            'primary' => false
          );
        }
      }

      $new_total += $amount;
      if ( $cycle == $total_receivers ) {
        if ( $new_total > $total ) {
          $receivers[ $key ]['amount'] = $amount - ( $new_total - $total );
        }
        elseif ( $total > $new_total ) {
          $receivers[ $key ]['amount'] = $amount + ( $total - $new_total );
        }
      }
    }

    return $receivers;
  }

	public function authorize() {

	}

	public function capture() {

	}

	public function void() {

	}

	public function credit() {

	}

  public function set_options( $options ) {
    $this->options = array_merge( $this->options, $options );
    return $this;
  }

}