<?php
/**
 * Plugin Name: Paid Memberships Pro - PDF Invoices
 * Description: Generates PDF Invoices for Paid Memberships Pro Orders.
 * Plugin URI: https://yoohooplugins.com/plugins/pmpro-pdf-invoices/
 * Author: Yoohoo Plugins
 * Author URI: https://yoohooplugins.com
 * Version: 1.1
 * License: GPL2 or later
 * Tested up to: 5.0
 * Requires PHP: 5.6
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pmpro-pdf-invoices
 * Domain Path: languages
 * Network: false
 *
 *
 * Paid Memberships Pro - PDF Invoices is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Paid Memberships Pro - PDF Invoices is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Paid Memberships Pro - PDF Invoices. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

defined( 'ABSPATH' ) or exit;

/**
 * Include update class for automatic updates.
 */
define( 'YOOHOO_STORE', 'https://yoohooplugins.com/edd-sl-api/' );
define( 'YH_PLUGIN_ID', 2117 );
define( 'PMPRO_PDF_VERSION', '1.0' );
define( 'PMPRO_PDF_DIR', dirname( __file__ ) );

define( 'PMPRO_PDF_LOGO_URL', 'PMPRO_PDF_LOGO_URL');

// Include the template editor page/functions
include PMPRO_PDF_DIR . '/includes/template-editor.php';

// Include license settings page.
include PMPRO_PDF_DIR . '/includes/general-settings.php';

function pmpropdf_settings_page() {
	add_options_page( 'Paid Memberships Pro PDF Invoice License Settings', 'PMPro PDF Invoice', 'manage_options', 'pmpro_pdf_invoices_license_key', 'pmpro_pdf_invoice_settings_page' );
}
add_action( 'admin_menu', 'pmpropdf_settings_page' );

/**
 * Class to handle automatic updates.
 */
if ( ! class_exists( 'PMPro_PDF_Invoice_Updater' ) ) {
	include( PMPRO_PDF_DIR . '/includes/class.pmpro-pdf-invoice-updater.php' );
}

$license_key = trim( get_option( 'pmpro_pdf_invoice_license_key' ) );

$edd_updater = new PMPro_PDF_Invoice_Updater( YOOHOO_STORE, __FILE__, array(
		'version' => PMPRO_PDF_VERSION,
		'license' => $license_key,
		'item_id' => YH_PLUGIN_ID,
		'author' => 'Yoohoo Plugins',
		'url' => home_url()
	)
);

use Dompdf\Dompdf;
include( PMPRO_PDF_DIR . '/includes/dompdf/autoload.inc.php' );

/**
 * Hook into the PMPro Email Attachements hook
 * Get the last order for the user
 * Store the PDF into the uploads directory
 * Return new attachments array to the PMPro email attachment hook
*/
function pmpropdf_attach_pdf_email( $attachments, $email ) {
	// Let's not send it to admins and only with checkout emails.
	if ( strpos( $email->template, "checkout_" ) !== false && strpos( $email->template, "admin" ) !== false ) {
		return $attachments;
	}

	// @Deprecated: Not reliable for all use cases
	// Get the user and their last order
	//$user = get_user_by( "email", $user_email );
	//$last_order = pmpropdf_get_last_order( $user->ID );

	$order_code = $email->data['order_code'];
	$last_order = pmpropdf_get_order_by_code($order_code);

	// Bail if order is empty / doesn't exist.
	// We do this early to avoid initializing the DomPDF library if it is unneeded
	if ( empty( $last_order[0] ) ) {
	 	return $attachments;
	}

	$order_data = $last_order[0];

	$path = pmpropdf_generate_pdf($order_data);
	if($path !== false){
		$attachments[] = $path;
	}

	return $attachments;

}
add_filter( 'pmpro_email_attachments', 'pmpropdf_attach_pdf_email', 10, 2 );

/**
 * Handles storage of PDF Invoice
 * Modular design allows it to be used in the primary pmpro_email_attachments_hook
 * As well as the batch processing tool
*/
function pmpropdf_generate_pdf($order_data){
	$user = get_user_by('ID', $order_data->user_id);

	$dompdf = new Dompdf( array( 'enable_remote' => true ) );

	$custom_dir = get_stylesheet_directory() . "/pmpro-pdf-invoices/order.html";
	if ( file_exists( $custom_dir ) ) {
		$body = file_get_contents( $custom_dir );
	} else {
		$body = file_get_contents( PMPRO_PDF_DIR . '/templates/order.html' );
	}

	// Build the string for billing data.
	if ( ! empty( $order_data->billing_name ) ) {
		$billing_details = "<p><strong>" . __( 'Billing Details', 'pmpro-pdf-invoices' ) . "</strong></p>";
		$billing_details .= "<p>" . $order_data->billing_name . "<br/>";
		$billing_details .=  $order_data->billing_street . "<br/>";
		$billing_details .= $order_data->billing_city . "<br/>";
		$billing_details .= $order_data->billing_state . "<br/>";
		$billing_details .= $order_data->billing_country . "<br/>";
		$billing_details .= $order_data->billing_phone . "</p>";
	} else {
		$billing_details = '';
	}

	$date = new DateTime( $order_data->timestamp );
	$date = $date->format( "Y-m-d" );

	$payment_method = !empty( $order_data->gateway ) ? $order_data->gateway : __( 'N/A', 'pmpro-pdf-invoices');

	$user_level_name = 'Unknown';
	if(function_exists('pmpro_getMembershipLevelForUser')){
		$user_level = pmpro_getMembershipLevelForUser($order_data->user_id);
		$user_level_name = $user_level->name;
	}

	$logo_url = get_option(PMPRO_PDF_LOGO_URL, '');
	$logo_image = !empty($logo_url) ? "<img style='max-width:300px;' src='$logo_url' />" : '';

	// Items to replace.
	$replace = array(
		"{{invoice_code}}",
		"{{user_email}}",
		'{{membership_level}}',
		'{{billing_address}}',
		"{{payment_method}}",
		"{{total}}",
		"{{site}}",
		"{{site_url}}",
		"{{subtotal}}",
		"{{tax}}",
		"{{ID}}",
		"{{invoice_date}}",
		"{{logo_image}}"
	);
	// Values to replace them with.
	$values = array(
		$order_data->code,
		$user->data->user_email,
		$user_level_name,
		$billing_details,
		$payment_method,
		pmpro_formatPrice($order_data->total),
		get_bloginfo( 'sitename' ),
		esc_url( get_site_url() ),
		pmpro_formatPrice( $order_data->subtotal ),
		pmpro_formatPrice($order_data->tax),
		$order_data->membership_id,
		$date,
		$logo_image
	);

	// Setup PDF Structure
	$body = str_replace( $replace, $values, $body );

	//Additional replacements - Developer hook to add custom variable parse
	//Should use key-value pair array (assoc)
	$custom_replacements = apply_filters('pmpro_pdf_invoice_custom_variable_hook', array());
	if(count($custom_replacements) > 0){
		foreach ($custom_replacements as $key => $value) {
			$body = str_replace($key, $value, $body);
		}
	}

	$dompdf->loadHtml( $body );
	$dompdf->render();
	$output = $dompdf->output();

	// Let's write this file to a directory now.

	$invoice_dir = pmpropdf_get_invoice_directory_or_url();
	$invoice_name = pmpropdf_generate_invoice_name($order_data->code);
	$path = $invoice_dir . $invoice_name;
	try{
		file_put_contents( $path, $output );
	} catch (Exception $ex){
		return false;
	}
	return $path;
}

// look at changing this soon.
function pmpropdf_admin_column_header( $order_id ) {
	echo '<td>' . __( 'PDF', 'pmpro-pdf-invoices' ) . '</td>';
}
add_action( 'pmpro_orders_extra_cols_header', 'pmpropdf_admin_column_header' );

function pmpropdf_admin_column_body( $order ) {

	$download_url = pmpropdf_get_invoice_directory_or_url(true) . pmpropdf_generate_invoice_name($order->code);

	if ( file_exists( pmpropdf_get_invoice_directory_or_url() . pmpropdf_generate_invoice_name($order->code) ) ){
	echo '<td><a href="' . esc_url( $download_url ). '" target="_blank">' . __( 'Download PDF', 'pmpro-pdf-invoices' ) .'</a></td>';
	} else {
		echo '<td> - </td>';
	}

}
add_action( 'pmpro_orders_extra_cols_body', 'pmpropdf_admin_column_body' );

/**
 * Helper function to get member order when class not available.
 * Revisit this at a later stage.
 */
function pmpropdf_get_last_order( $user_id ) {
	global $wpdb;

	$user_id = intval( $user_id );

	$order = $wpdb->get_results("SELECT * FROM $wpdb->pmpro_membership_orders WHERE user_id = " . esc_sql( $user_id ) . " AND status NOT IN('cancelled') ORDER BY timestamp DESC LIMIT 1");

	return $order;
}

/**
 * Get specific order by its order ID
 * Proxy of: pmpropdf_get_last_order
 */
function pmpropdf_get_order_by_code( $order_code ) {
	global $wpdb;
	$order = $wpdb->get_results("SELECT * FROM $wpdb->pmpro_membership_orders WHERE code = '" . esc_sql( $order_code ) . "' LIMIT 1");

	return $order;
}

/**
 * Returns the invoice storage directory
 * Creates it if it does no exist
*/
function pmpropdf_get_invoice_directory_or_url($url = false){
	$upload_dir = wp_upload_dir();
	$invoice_dir = ($url ? $upload_dir['baseurl'] : $upload_dir['basedir'] ) . '/pmpro-invoices/';

	if($url == false){
		if ( !file_exists( $invoice_dir ) ) {
			mkdir( $invoice_dir, 0777, true );
		}
	}

	return $invoice_dir;
}

/**
 * Generates an invoice name from an order code
*/
function pmpropdf_generate_invoice_name($order_code){
	return "INV" . $order_code . ".pdf";
}

/**
 * Get batch of orders
 * Return the ordders for loop processing
*/
function pmpropdf_get_order_batch($batch_size = 100, $batch_no = 0){
	global $wpdb;

	$offset = $batch_no * $batch_size;
	$batch_sql = "SELECT * FROM $wpdb->pmpro_membership_orders ORDER BY timestamp ASC LIMIT $batch_size OFFSET $offset";
	$batch = $wpdb->get_results($batch_sql);

	return $batch;
}

/**
 * Process a batch of orders
 * Check if the current order has a PDF generated
 * Generate one if this is not the case
 * Skip it if we have this invoice already created
*/
function pmpropdf_process_batch($batch_size = 100, $batch_no = 0){
	$invoice_dir = pmpropdf_get_invoice_directory_or_url();

	$output_array = array(
		'skipped' => 0,
		'created' => 0,
		'batch_no' => $batch_no,
		'batch_count' => 0
	);

	$batch = pmpropdf_get_order_batch($batch_size, $batch_no);
	foreach ($batch as $order_data) {
		$invoice_name = pmpropdf_generate_invoice_name($order_data->code);

		if(file_exists($invoice_dir . $invoice_name)){
			$output_array['skipped'] += 1;
		} else {
			$path = pmpropdf_generate_pdf($order_data);
			$output_array['created'] += 1;
		}
	}

	$output_array['batch_count'] = count($batch);

	return $output_array;
}

/**
 * AJAX Loop
*/
function pmpropdf_batch_processor() {
	if(defined('DOING_AJAX') && DOING_AJAX){
		$batch_size = !empty($_POST['batch_size']) ? intval($_POST['batch_size']) : 100;
		$batch_no = !empty($_POST['batch_no']) ? intval($_POST['batch_no']) : 0;
		$batch_output = pmpropdf_process_batch($batch_size, $batch_no);

		echo json_encode($batch_output);
	}
	die();
}
add_action( 'wp_ajax_pmpropdf_batch_processor', 'pmpropdf_batch_processor' );