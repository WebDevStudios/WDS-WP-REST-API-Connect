WDS WP JSON API Connect
=========

A tool for connecting to the [JSON-based REST API for WordPress](https://github.com/WP-API/WP-API) via [OAuth 1.0a](https://github.com/WP-API/OAuth1).

To get started, you'll need to install both the [WP JSON API plugin](https://github.com/WP-API/WP-API) and the [OAuth plugin](https://github.com/WP-API/OAuth1).

Once installed and activated, you'll need to create a '[Consumer](https://github.com/WP-API/client-cli#step-1-creating-a-consumer)'.
When you have the Consumer key and secret, you'll create a new WP_JSON_API_Connect object by passing those credentials along with the JSON API URL:
```php
// Consumer credentials
$consumer = array(
	'consumer_key'    => 'YOUR CONSUMER KEY',
	'consumer_secret' => 'YOUR CONSUMER SECRET',
	'json_url'        => 'JSON API URL OF SITE',
);
$api = new WP_JSON_API_Connect( $consumer );
```

You can then use this object to retrieve the authentication request URL, or if you have been authenticated, make requests.

```php
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

	$post_id_to_view = 1;
	$response = $api->auth_get_request( 'posts/'. $post_id_to_view );

	if ( is_wp_error( $response ) ) {

		echo '<div id="message" class="error">';
		echo wpautop( $response->get_error_message() );
		echo '</div>';

	} else {

		echo '<div id="message" class="updated">';
		echo '<p><strong>'. $response['title'] .' retrieved!</strong></p>';
		echo '<xmp>auth_get_request $response: '. print_r( $response, true ) .'</xmp>';
		echo '</div>';

	}

	$post_id_to_update = 1;
	$updated_data = array( 'title' => 'Hello JSON World!' );
	$response = $api->auth_post_request( 'posts/'. $post_id_to_update, $updated_data );

	if ( is_wp_error( $response ) ) {

		echo '<div id="message" class="error">';
		echo wpautop( $response->get_error_message() );
		echo '</div>';

	} else {

		echo '<div id="message" class="updated">';
		echo '<p><strong>Post updated!</strong></p>';
		echo '<xmp>auth_post_request $response: '. print_r( $response, true ) .'</xmp>';
		echo '</div>';

	}

}
add_action( 'all_admin_notices', 'wp_json_api_connect_example_test' );
```
