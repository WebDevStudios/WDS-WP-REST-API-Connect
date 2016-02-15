<?php
// Make sure you run `composer install`!
require_once 'vendor/autoload.php';

// include the library.
require_once( 'wds-wp-rest-api-connect.php' );

/**
 * Example WDS_WP_REST_API\OAuth1\Connect usage
 * To test it out, go to your site's WP dashboard:
 * YOURSITE-URL/wp-admin/?api-connect
 */
function wp_json_api_initiate_sample_connection() {
	if ( ! isset( $_GET['api-connect'] ) ) {
		return;
	}

	global $api_connect; // hold this in a global for demonstration purposes.

	// Output our errors/notices in the admin dashboard.
	add_action( 'all_admin_notices', 'wp_json_api_show_sample_connection_notices' );

	// Get the connect object
	$api_connect = new WDS_WP_REST_API\OAuth1\Connect();

	// Consumer credentials
	$client = array(
		// Library will 'discover' the API url
		'api_url'       => 'WP SITE URL TO CONNECT TO',
		// App credentials set up on the server
		'client_key'    => 'YOUR CLIENT KEY',
		'client_secret' => 'YOUR CLIENT SECRET',
		// Must match stored callback URL setup on server.
		'callback_uri'  => admin_url() . '?api-connect',
		// 'autoredirect_authoriziation' => false,
	);

	/*
	 * Initate the API connection.
	 *
	 * if the oauth connection is not yet authorized, (and autoredirect_authoriziation
	 * is not explicitly set to false) you will be auto-redirected to the other site to
	 * receive authorization.
	 */
	$discovery = $api_connect->init( $client );

	// If oauth discovery failed, the WP_Error object will explain why.
	if ( is_wp_error( $discovery ) ) {
		// Save this error to the library's error storage (to output as admin notice)
		return $api_connect->update_stored_error( $discovery );
	}

	/*
	 * if autoredirect_authoriziation IS set to false, you'll need to use the
	 * authorization URL to redirect the user to login for authorization.
	 */
	// $authorization_url = $api_connect->get_authorization_url();
	// if ( ! is_wp_error( $authorization_url ) ) {
	// 	wp_redirect( $authorization_url );
	// 	exit();
	// }

	// If you need to reset the stored connection data for any reason:
	// $api_connect->reset_connection();
}
add_action( 'admin_init', 'wp_json_api_initiate_sample_connection' );

function wp_json_api_show_sample_connection_notices() {
	global $api_connect;

	/*
	 * If something went wrong in the process, errors will be stored.
	 * We can fetch them this way.
	 */
	if ( $api_connect->get_stored_error() ) {

		$message = '<div id="message" class="error"><p><strong>Error Message:</strong> ' . $api_connect->get_stored_error_message() . '</p></div>';
		$message .= '<div id="message" class="error"><p><strong>Error request arguments:</strong></p><xmp>' . $api_connect->get_stored_error_request_args() . '</xmp></div>';
		$message .= '<div id="message" class="error"><p><strong>Error request response:</strong></p><xmp>' . $api_connect->get_stored_error_request_response() . '</xmp></div>';

		// Output message, and bail.
		return print( $message );
	}

	// Get the API Description object from the root API endpoint.
	// echo '<div id="message" class="updated">';
	// echo '<xmp>API Description endpoint: '. print_r( $api_connect->get_api_description(), true ) .'</xmp>';
	// echo '</div>';

	$post_id_to_view = 1;
	$response = $api_connect->auth_get_request( '/wp/v2/posts/'. $post_id_to_view );

	if ( is_wp_error( $response ) ) {

		echo '<div id="message" class="error">';
		echo wpautop( $response->get_error_message() );
		echo '</div>';

	} else {

		echo '<div id="message" class="updated">';
		echo '<p><strong>'. $response->title->rendered .' retrieved!</strong></p>';
		echo '<xmp>auth_get_request $response: '. print_r( $response, true ) .'</xmp>';
		echo '</div>';

	}

	/*
	 * The following will definitely update post 1!!! Do not uncomment unless
	 * you're ok with that data loss!
	 */

	// $post_id_to_update = 1;
	// $updated_data = array( 'title' => 'Hello REST API World!' );
	// $response = $api_connect->auth_post_request( '/wp/v2/posts/'. $post_id_to_update, $updated_data );

	// if ( is_wp_error( $response ) ) {

	// 	echo '<div id="message" class="error">';
	// 	echo wpautop( $response->get_error_message() );
	// 	echo '</div>';

	// } else {

	// 	echo '<div id="message" class="updated">';
	// 	echo '<p><strong>Post updated!</strong></p>';
	// 	echo '<xmp>auth_post_request $response: '. print_r( $response, true ) .'</xmp>';
	// 	echo '</div>';

	// }
}
