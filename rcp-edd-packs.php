<?php
/**
 * Plugin Name: Restrict Content Pro - Download Packs for Easy Digital Downloads
 * Description: Allow members to download a certain number of items based on their subscription level.
 * Version: 1.0
 * Author: Restrict Content Pro Team
 * Text Domain: rcp-edd-packs
 */


/**
 * Loads the plugin textdomain.
 */
function rcp_edd_packs_textdomain() {
	load_plugin_textdomain( 'rcp-edd-packs', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'rcp_edd_packs_textdomain' );


/**
 * Adds the plugin settings form fields to the subscription level form.
 */
function rcp_edd_packs_level_fields( $level ) {

	if ( ! function_exists( 'EDD' ) ) {
		return;
	}

	global $rcp_levels_db;

	if ( empty( $level->id ) ) {
		$allowed = 0;
	} else {
		$existing = $rcp_levels_db->get_meta( $level->id, 'edd_downloads_allowed', true );
		$allowed  = ! empty( $existing ) ? $existing : 0;
	}
	?>

	<tr class="form-field">
		<th scope="row" valign="top">
			<label for="rcp-edd-downloads-allowed"><?php printf( __( '%s Allowed', 'rcp-edd-packs' ), edd_get_label_plural() ); ?></label>
		</th>
		<td>
			<input type="number" min="0" step="1" id="rcp-edd-downloads-allowed" name="rcp-edd-downloads-allowed" value="<?php echo absint( $allowed ); ?>" style="width: 60px;"/>
			<p class="description"><?php printf( __( 'The number of %s allowed each subscription period.', 'rcp-edd-packs' ), strtolower( edd_get_label_plural() ) ); ?></p>
		</td>
	</tr>

	<?php
	wp_nonce_field( 'rcp_edd_downloads_allowed_nonce', 'rcp_edd_downloads_allowed_nonce' );
}
add_action( 'rcp_add_subscription_form', 'rcp_edd_packs_level_fields' );
add_action( 'rcp_edit_subscription_form', 'rcp_edd_packs_level_fields' );



/**
 * Saves the subscription level limit settings.
 */
function rcp_edd_packs_save_level_limits( $level_id = 0, $args = array() ) {

	if ( ! function_exists( 'EDD' ) ) {
		return;
	}

	global $rcp_levels_db;

	if ( empty( $_POST['rcp_edd_downloads_allowed_nonce'] ) || ! wp_verify_nonce( $_POST['rcp_edd_downloads_allowed_nonce'], 'rcp_edd_downloads_allowed_nonce' ) ) {
		return;
	}

	if ( empty( $_POST['rcp-edd-downloads-allowed'] ) ) {
		$rcp_levels_db->delete_meta( $level_id, 'edd_downloads_allowed' );
		return;
	}

	$rcp_levels_db->update_meta( $level_id, 'edd_downloads_allowed', absint( $_POST['rcp-edd-downloads-allowed'] ) );
}
add_action( 'rcp_add_subscription', 'rcp_edd_packs_save_level_limits', 10, 2 );
add_action( 'rcp_edit_subscription_level', 'rcp_edd_packs_save_level_limits', 10, 2 );


/**
 * Determines if the member is at the product submission limit.
 */
function rcp_edd_packs_member_at_limit( $user_id = 0 ) {

	if ( ! function_exists( 'rcp_get_subscription_id' ) ) {
		return;
	}

	global $rcp_levels_db;

	if ( empty( $user_id ) ) {
		$user_id = wp_get_current_user()->ID;
	}

	$limit = false;

	$sub_id = rcp_get_subscription_id( $user_id );

	if ( $sub_id ) {
		$max = (int) $rcp_levels_db->get_meta( $sub_id, 'edd_downloads_allowed', true );
		$current = (int) get_user_meta( $user_id, 'rcp_edd_packs_current_download_count', true );
		if ( $max >= 1 && $current >= $max ) {
			$limit = true;
		}
	}

	return $limit;
}


/**
 * Resets a vendor's product submission count when making a new payment.
 */
function rcp_edd_packs_reset_limit( $payment_id, $args = array(), $amount ) {

	if ( ! empty( $args['user_id'] ) ) {
		delete_user_meta( $args['user_id'], 'rcp_edd_packs_current_download_count' );
	}
}
add_action( 'rcp_insert_payment', 'rcp_edd_packs_reset_limit', 10, 3 );


/**
 * Determines if a user has a membership that allows downloads.
 */
function rcp_edd_packs_user_has_pack_membership( $user_id ) {

	if ( empty( $user_id ) ) {
		$user_id = wp_get_current_user()->ID;
	}

	global $rcp_levels_db;

	$sub_id = rcp_get_subscription_id( $user_id );

	if ( $sub_id ) {
		$max = (int) $rcp_levels_db->get_meta( $sub_id, 'edd_downloads_allowed', true );
		if ( ! empty( $max ) && $max > 0 ) {
			return true;
		}
	}

	return false;
}


function rcp_edd_packs_download_button( $purchase_form, $args ) {

	if ( ! is_user_logged_in() ) {
		return $purchase_form;
	}

	// @todo support bundles
	if ( edd_is_bundled_product( $args['download_id'] ) ) {
		return $purchase_form;
	}

	if ( edd_has_variable_prices( $args['download_id'] ) ) {
		return $purchase_form;
	}

	$user = wp_get_current_user();

	if ( ! rcp_edd_packs_user_has_pack_membership( $user->ID ) ) {
		return $purchase_form;
	}

	if ( rcp_edd_packs_member_at_limit( $user->ID ) && ! edd_has_user_purchased( $user->ID, $args['download_id'] ) ) {
		return $purchase_form;
	}

	global $edd_displayed_form_ids;

	$download = new EDD_Download( $args['download_id'] );

	if ( isset( $edd_displayed_form_ids[ $download->ID ] ) ) {
		$edd_displayed_form_ids[ $download->ID ]++;
	} else {
		$edd_displayed_form_ids[ $download->ID ] = 1;
	}
?>
	<script type="text/javascript">
		(function($) {
			$(document).ready(function() {
				$('.rcp-edd-download-pack-request').on('click', function(e) {
					e.preventDefault();
					var item = $(this).parent().find("input[name='rcp-edd-download-pack-request']").val();
					var data = {
						action: 'rcp-edd-download-pack-request',
						security: $('#rcp-edd-download-pack-nonce').val(),
						item: item
					}

					$.ajax({
						data: data,
						type: "POST",
						dataType: "json",
						url: edd_scripts.ajaxurl,
						success: function (response) {
console.log(response);
							if ( response.file && response.file.length > 0 ) {
								window.location.replace(response.file);
							}
// @todo if no file, change button to show something
						},
						error: function (response) {
							console.log('error ' + response);
						}
					});
				});
			});
		})(jQuery);
	</script>

<?php
	$form_id = ! empty( $args['form_id'] ) ? $args['form_id'] : 'edd_purchase_' . $download->ID;
	ob_start();
?>
	<form id="<?php echo $form_id; ?>" class="edd_download_purchase_form edd_purchase_<?php echo absint( $download->ID ); ?>" method="post">
		<input type="hidden" name="download_id" value="<?php echo esc_attr( $download->ID ); ?>">
		<input type="hidden" name="rcp-edd-download-pack-request" value="<?php echo esc_attr( $download->ID ); ?>">
		<input type="hidden" id="rcp-edd-download-pack-nonce" name="rcp-edd-download-pack-nonce" value="<?php echo wp_create_nonce( 'rcp-edd-download-pack-nonce' ); ?>">
		<input type="submit" class="rcp-edd-download-pack-request" value="<?php esc_html_e( 'Download', 'rcp-edd-packs' ); ?>">
	</form>
<?php
	return ob_get_clean();

}
add_filter( 'edd_purchase_download_form', 'rcp_edd_packs_download_button', 10, 2 );


function rcp_edd_packs_process_ajax_download() {

	global $rcp_levels_db;

	check_ajax_referer( 'rcp-edd-download-pack-nonce', 'security' );

	if ( ! is_user_logged_in() ) {
		wp_die(-1);
	}

	$user = wp_get_current_user();

	if ( ! rcp_edd_packs_user_has_pack_membership( $user->ID ) ) {
		wp_die(-1);
	}

	if ( empty( $_POST['item'] ) ) {
		wp_die(-1);
	} else {
		$item = absint( $_POST['item'] );
	}

	if ( edd_has_user_purchased( $user->ID, $item ) ) {

		$payments = new EDD_Payments_Query( array(
			'number'   => 1,
			'status'   => 'publish',
			'user'     => $user->ID,
			'download' => $item
		) );

		$payment      = $payments->get_payments();

		$payment_meta = edd_get_payment_meta( $payment[0]->ID );

		$files        = edd_get_download_files( $payment_meta['cart_details'][0]['id'] );

		if ( ! empty( $files ) ) {
			$file_keys = array_keys( $files );
			$url       = edd_get_download_file_url( $payment_meta['key'], $payment_meta['user_info']['email'], $file_keys[0], $payment_meta['cart_details'][0]['id'] );
		}

	} else {

		remove_action( 'edd_complete_purchase', 'edd_trigger_purchase_receipt', 999 );

		$sub_id = rcp_get_subscription_id( $user_id );

		if ( ! $sub_id ) {
			wp_die( __( 'You do not have a membership.', 'rcp-edd-packs' ) );
		}

		$max = (int) $rcp_levels_db->get_meta( $sub_id, 'edd_downloads_allowed', true );

		if ( empty( $max ) ) {
			wp_die( __( 'You must have a valid membership.', 'rcp-edd-packs' ) );
		}

		$current = get_user_meta( $user->ID, 'rcp_edd_packs_current_download_count', true );

		if ( $current >= $max ) {
			wp_die( __( 'You have reached the limit defined by your membership.', 'rcp-edd-packs' ) );
		}

		$payment = new EDD_Payment();
		$payment->add_download( $item, array( 'item_price' => 0.00 ) );
		$payment->email      = $user->user_email;
		$payment->first_name = $user->first_name;
		$payment->last_name  = $user->last_name;
		$payment->user_id    = $user->ID;
		// @todo is this user_info array necessary?
		$payment->user_info  = array(
			'first_name' => $user->first_name,
			'last_name'  => $user->last_name,
			'email'      => $user->user_email,
			'id'         => $user->ID
		);
		$payment->gateway = 'manual';
		$payment->status  = 'pending';
		$payment->save();
		$payment->status  = 'complete';
		$payment->save();

		edd_insert_payment_note( $payment->ID, __( 'Downloaded with RCP membership', 'rcp-edd-packs' ) );

		$payment_meta = edd_get_payment_meta( $payment->ID );
		$files        = edd_get_download_files( $item );
		$file_keys    = array_keys( $files );
		$url          = edd_get_download_file_url( $payment_meta['key'], $user->user_email, $file_keys[0], $item );

		$current++;
		update_user_meta( $user->ID, 'rcp_edd_packs_current_download_count', $current );
	}

	wp_send_json( array(
		'files' => $files,
		'file'  => $url
	) );

}
add_action( 'wp_ajax_rcp-edd-download-pack-request', 'rcp_edd_packs_process_ajax_download' );
add_action( 'wp_ajax_nopriv_rcp-edd-download-pack-request', 'rcp_edd_packs_process_ajax_download' );
