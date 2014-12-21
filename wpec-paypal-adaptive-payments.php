<?php
/**
 * Plugin Name: WP eCommerce PayPal Adaptive Payments Gateway Plugin
 * Plugin URI: https://wpecommerce.org/
 * Description: PayPal Adaptive Payments plugin for WP eCommerce
 * Version: 1.0
 * Author: WP eCommerce
 * Author URI: https://wpecommerce.org
**/

add_filter( 'wpsc_init', 'wpsc_pap_register_dir' );

function wpsc_pap_register_dir() {

	wpsc_register_payment_gateway_dir( dirname(__FILE__) . '/gateways' );
}

add_filter( 'manage_dashboard_page_wpsc-purchase-logs_columns', 'pap_purchase_log_columns' );

function pap_purchase_log_columns( $columns ) {
	$columns['ppap'] = __( 'Pending Preapproval', 'wpsc' );
	return $columns;
}

add_filter( 'wpsc_manage_purchase_logs_custom_column' , 'pap_manage_purchase_logs_custom_column', 10, 3 );

function pap_manage_purchase_logs_custom_column( $default, $column_name, $item ) {
	require_once( 'gateways/paypal-adaptive-payments.php' );
	$paypal_adaptive = new WPSC_Payment_Gateway_Paypal_Adaptive_Payments();
	if ( $column_name == 'ppap' && wpsc_get_purchase_meta( $item->id, '_wpsc_pap_preapproval_key', true ) && ! wpsc_get_purchase_meta( $item->id, '_wpsc_pap_preapproval_paid', true ) && ! wpsc_get_purchase_meta( $item->id, '_wpsc_pap_preapproval_cancelled', true ) ) {
		$process_preapproval_params = array(
			'page' => 'wpsc-purchase-logs',
			'id' => $item->id,
			'action' => 'process_preapproval',
		);
		$cancel_preapproval_params = array(
			'page' => 'wpsc-purchase-logs',
			'id' => $item->id,
			'action' => 'cancel_preapproval',
		);
		?>
		<a href="<?php echo esc_url( add_query_arg( $process_preapproval_params ) ); ?>">
			<input type="button" value="Process Preapproval" class="button-secondary button" />
		</a>
		<a href="<?php echo esc_url( add_query_arg( $cancel_preapproval_params ) ); ?>">
			<input type="button" value="Cancel Preapproval" class="button-secondary button" />
		</a>
		<?php
	}
	if ( wpsc_get_purchase_meta( $item->id, '_wpsc_pap_preapproval_paid', true ) ) {
		?>
		<strong><?php _e( 'PAID', 'wpsc' ); ?></strong>
		<?php
	}
	if ( wpsc_get_purchase_meta( $item->id, '_wpsc_pap_preapproval_cancelled', true ) ) {
		?>
		<strong><?php _e( 'CANCELLED', 'wpsc' ); ?></strong>
		<?php
	}
}

add_action( 'wpsc_purchlogitem_metabox_end', 'pap_item_details_section');

function pap_item_details_section( $item_id ) {
	require_once( 'gateways/paypal-adaptive-payments.php' );
	$paypal_adaptive = new WPSC_Payment_Gateway_Paypal_Adaptive_Payments();
	$preapproval_key = wpsc_get_purchase_meta( $item_id, '_wpsc_pap_preapproval_key', true );
	$preapproval_details = $paypal_adaptive->get_gateway()->get_preapproval_details( $preapproval_key );
	if ( $preapproval_key ):
		$process_preapproval_params = array(
			'page' => 'wpsc-purchase-logs',
			'id' => $item_id,
			'c' => 'item_details',
			'action' => 'process_preapproval',
		);
		$cancel_preapproval_params = array(
			'page' => 'wpsc-purchase-logs',
			'id' => $item_id,
			'c' => 'item_details',
			'action' => 'cancel_preapproval',
		);
		?>
	<div id='wpsc_purchlogitems_links'>
		<h3><?php esc_html_e( 'Preapproval Details', 'wpsc' ); ?></h3>
		<h4><?php _e( 'Preapproval Key:', 'wpsc' ); ?> <?php echo $preapproval_key; ?></h4>
		<h4><?php _e('Sender Email:', 'epap'); ?> <?php echo isset( $preapproval_details['senderEmail'] ) ? $preapproval_details['senderEmail'] : __('Sender Email is Missing', 'wpsc'); ?></h4>
		<?php if ( ! wpsc_get_purchase_meta( $item_id, '_wpsc_pap_preapproval_paid', true ) && ! wpsc_get_purchase_meta( $item_id, '_wpsc_pap_preapproval_cancelled', true ) ) : ?>
			<a href="<?php echo esc_url( add_query_arg( $process_preapproval_params ) ); ?>">
				<input type="button" value="Process Preapproval" class="button-secondary button" />
			</a>
			<a href="<?php echo esc_url( add_query_arg( $cancel_preapproval_params ) ); ?>">
				<input type="button" value="Cancel Preapproval" class="button-secondary button" />
			</a>
		<?php elseif ( wpsc_get_purchase_meta( $item_id, '_wpsc_pap_preapproval_cancelled', true ) ): ?>
			<h4><?php _e( 'Preapproval Status: CANCELLED', 'wpsc' ); ?></h4>
		<?php elseif ( wpsc_get_purchase_meta( $item_id, '_wpsc_pap_preapproval_paid', true ) ) : ?>
			<h4><?php _e( 'Preapproval Status: PAID', 'wpsc' ); ?></h4>
		<?php endif; ?>
	</div>
	<?php endif;
}

add_action( 'init', 'wpsc_pap_process_preapproval' );

function wpsc_pap_process_preapproval() {
	if ( isset( $_GET['process_preapproval'] ) ) {
		add_action( 'admin_notices', 'wpsc_pap_process_preapproval_message' );
	}
	if ( isset( $_GET['cancel_preapproval'] ) ) {
		add_action( 'admin_notices', 'wpsc_pap_cancel_preapproval_message' );
	}
	require_once( 'gateways/paypal-adaptive-payments.php' );
	$paypal_adaptive = new WPSC_Payment_Gateway_Paypal_Adaptive_Payments();
	$paypal_adaptive->wpsc_pap_process_preapproval();
}

function wpsc_pap_process_preapproval_message() {
	$message = $_GET['process_preapproval'];
	?>
		<div id="message" class="<?php echo $message == 'success' ? 'updated' : 'error'; ?> fade">
			<p>
				<?php if ( $message == 'success' ) : ?>
					<strong><?php _e( 'The Preapproval was successfully processed.', 'wpsc' ); ?></strong>
				<?php elseif ( $message == 'error' ): ?>
					<strong><?php _e( 'The Preapproval failed to process.', 'wpsc' ); ?></strong>
				<?php endif; ?>
			</p>
		</div>

	<?php
}

function wpsc_pap_cancel_preapproval_message() {
	$message = $_GET['cancel_preapproval'];
	?>
		<div id="message" class="<?php echo $message == 'success' ? 'updated' : 'error'; ?> fade">
			<p>
				<?php if ( $message == 'success' ) : ?>
					<strong><?php _e( 'The Preapproval was successfully cancelled.', 'wpsc' ); ?></strong>
				<?php elseif ( $message == 'error' ): ?>
					<strong><?php _e( 'The Preapproval failed to cancel.', 'wpsc' ); ?></strong>
				<?php endif; ?>
			</p>
		</div>

	<?php
}