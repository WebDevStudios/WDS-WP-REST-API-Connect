<?php

if ( ! class_exists( 'WP_JSON_API_Connect' ) ) {
	require_once( 'WP_JSON_API_Connect.php' );
}

/**
 * Example WP_JSON_API_Connect usage
 */
function wp_json_api_connect_example_test() {

	// Consumer credentials
	$consumer = array(
		'consumer_key'    => 'YOUR CONSUMER KEY',
		'consumer_secret' => 'YOUR CONSUMER SECRET',
		'json_url'        => 'JSON API URL OF SITE',
	);

	$api = new WP_JSON_API_Connect( $consumer );

	$auth_url = $api->get_authorization_url( array( 'test_api' => $_GET['test_api'] ) );

	// Only returns URL if not yet authenticated
	if ( $auth_url ) {
		echo '<div id="message" class="updated">';
		echo '<p><a href="'. esc_url( $auth_url ) .'" class="button">Authorize Connection</a></p>';
		echo '</div>';

		// Do not proceed
		return;
	}

	$post_id_to_update = 1;
	$updated_data = array( 'title' => 'Hello JSON World!' );
	$response = $api->auth_request( 'posts/'. $post_id_to_update, $updated_data );

	if ( is_wp_error( $response ) ) {

		echo '<div id="message" class="error">';
		echo wpautop( $response->get_error_message() );
		echo '</div>';

	} else {

		echo '<div id="message" class="updated">';
		echo '<p><strong>Post updated!</strong></p>';
		echo '<xmp>$response: '. print_r( $response, true ) .'</xmp>';
		echo '</div>';

	}

}
add_action( 'all_admin_notices', 'wp_json_api_connect_example_test' );
