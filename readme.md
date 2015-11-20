WDS WP REST API Connect [![Scrutinizer Code Quality](http://img.shields.io/scrutinizer/g/WebDevStudios/WDS-WP-JSON-API-Connect.svg?style=flat)](https://scrutinizer-ci.com/g/WebDevStudios/WDS-WP-JSON-API-Connect/)
=========

A tool for connecting to the [JSON-based REST API for WordPress](https://github.com/WP-API/WP-API) via [OAuth 1.0a](https://github.com/WP-API/OAuth1).

To get started, you'll need to install both the [WP REST API plugin](https://github.com/WP-API/WP-API) and the [OAuth plugin](https://github.com/WP-API/OAuth1).

Once installed and activated, you'll need to create a '[Consumer](https://github.com/WP-API/client-cli#step-1-creating-a-consumer)'.
When you have the Consumer key and secret, you'll create a new WDS_WP_REST_API_Connect object by passing those credentials along with the REST API URL:
```php
// Consumer credentials
$consumer = array(
	'consumer_key'    => 'YOUR CONSUMER KEY',
	'consumer_secret' => 'YOUR CONSUMER SECRET',
	'json_url'        => 'REST API URL OF SITE',
);
$api = new WDS_WP_REST_API_Connect( $consumer );
```

You can then use this object to retrieve the authentication request URL, or if you have been authenticated, make requests.

```php
<?php

require_once( 'wds-wp-rest-api-connect.php' );

/**
 * Example WDS_WP_REST_API_Connect usage
 */
function wp_json_api_connect_example_test() {

	// Consumer credentials
	$consumer = array(
		'consumer_key'    => 'YOUR CONSUMER KEY',
		'consumer_secret' => 'YOUR CONSUMER SECRET',
		'json_url'        => 'REST API URL OF SITE',
	);

	$api = new WDS_WP_REST_API_Connect( $consumer );

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
	$updated_data = array( 'title' => 'Hello REST API World!' );
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

## Changelog

### 0.1.4
* Add utility methods for dealing with errors.
* Convert post body to a query string before wp_remote_request does because http_build_query drops empty values and oauth signatures end up not matching.

### 0.1.3
* Fix some docs, and "clever" code, and fix an incorrect variable name.
* Add class_exists check so we don't break sites w/ multiple instantiations.
* Store response code as object property.
* Account for `WP_Error` from responses before creating response code.
* Accept a headers array, and pass those headers to all requests.
* Option/transient key should be a hash of all the args, not just the url.
* Do not cache api description if request is a failure (or being requested not to).
* Make headers and key/option_key accessible as read-only properties.
* Change from JSON to REST to match WordPress core plugin.
* Move error option to its own method.

### 0.1.2
* Better checks for failed requests.
* Set the http method for each call so that the OAuth signature matches.
* Create a redirect URL based on current url or allow one to be passed in.
* Keep response as an accessible class property, and add get/set request methods. Also properly handle GET request authorization header.
* Some cleanup based on scrutinizer feedback.

### 0.1.1
* Rename file

### 0.1.0
* Release