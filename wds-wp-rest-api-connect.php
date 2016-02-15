<?php

namespace WDS_WP_REST_API\OAuth1;

use Exception;
use WP_Error;
use WDS_WP_REST_API\Storage\Store_Interface;
use WDS_WP_REST_API\Storage\Transient_Interface;
use WDS_WP_REST_API\OAuth1\WPServer;

if ( ! class_exists( 'WDS_WP_REST_API\OAuth1\Connect' ) ) :

	/**
	 * Connect to the WordPress REST API over OAuth1
	 *
	 * API Documentation
	 * https://github.com/WP-API/WP-API/tree/master/docs
	 *
	 * OAuth Authentication API
	 * https://github.com/WP-API/OAuth1/blob/master/docs/spec.md
	 *
	 * The OAuth 1.0 Protocol
	 * http://tools.ietf.org/html/rfc5849
	 *
	 * @author  Justin Sternberg <justin@webdevstudios.com>
	 * @package Connect
	 * @version 0.2.0
	 */
	class Connect {

		/**
		 * OAuth1 Client
		 *
		 * @var WPServer
		 */
		protected $server;

		/**
		 * Options Store
		 *
		 * @var Store_Interface
		 */
		protected $store;

		/**
		 * Transients Store
		 *
		 * @var Transient_Interface
		 */
		protected $transient;

		protected $key                 = '';
		protected $client_key        = '';
		protected $client_secret     = '';
		protected $api_url             = '';
		protected $headers             = '';
		protected $callback_uri        = '';
		protected $access_token        = '';
		protected $access_token_secret = '';
		protected $endpoint_url        = '';

		protected $is_authorizing = null;
		protected $autoredirect_authoriziation = true;
		protected $method  = 'GET';

		public $json_desc = '';
		public $response  = '';

		/**
		 * Connect object constructor.
		 *
		 * @since 0.2.0
		 *
		 * @param array $storage_classes (optional) override the storage classes.
		 */
		public function __construct( $storage_classes = array() ) {
			$storage_classes = wp_parse_args( $storage_classes, array(
				'options_class' => 'WDS_WP_REST_API\Storage\Options',
				'transients_class' => 'WDS_WP_REST_API\Storage\Transients',
			) );

			$this->instantiate_storage_objects(
				new $storage_classes['options_class'](),
				new $storage_classes['options_class']( false ),
				new $storage_classes['transients_class']()
			);
		}

		/**
		 * Instantiates the storage objects for the options and transients.
		 *
		 * @since  0.2.0
		 *
		 * @param  Store_Interface     $store       Option storage
		 * @param  Store_Interface     $error_store Error option storage
		 * @param  Transient_Interface $transient   Transient storage
		 */
		protected function instantiate_storage_objects(
			Store_Interface $store,
			Store_Interface $error_store,
			Transient_Interface $transient
		) {
			$this->store = $store;
			$this->error_store = $error_store;
			$this->error_store->set_key( 'wp_rest_api_connect_error' );

			$this->transient = $transient;
			$this->transient->set_key( 'apiconectdesc_'. md5( serialize( $this ) ) );
		}

		/**
		 * Initate our connect object
		 *
		 * @since 0.1.0
		 *
		 * @param array $args Arguments containing 'client_key', 'client_secret', 'api_url',
		 *                    'headers', 'callback_uri', 'autoredirect_authoriziation'
		 */
		public function init( $args ) {
			foreach ( wp_parse_args( $args, array(
				'client_key'                => '',
				'client_secret'             => '',
				'api_url'                     => '',
				'headers'                     => '',
				'callback_uri'                => '',
				'autoredirect_authoriziation' => true,
			) ) as $key => $arg ) {
				$this->{$key} = $arg;
			}

			if ( $this->key() ) {
				$this->set_object_properties();
			}

			// If we haven't done API discovery yet, do that now.
			$discovered = ! $this->discovered() ? $this->do_discovery() : true;

			// If discovery failed, we cannot proceed.
			if ( is_wp_error( $discovered ) ) {
				return $discovered;
			}

			// If autoredirect is requested, and we are not yet authorized,
			// redirect to the other site to get authorization.
			$error = $this->maybe_redirect_to_authorization();

			// If authorization failed, we cannot proceed.
			if ( is_wp_error( $error ) ) {
				return $error;
			}

			// Ok, initiation is complete and successful.
			return true;
		}

		/**
		 * Get the options from the DB and set the object properties.
		 *
		 * @since 0.2.0
		 */
		public function set_object_properties() {
			foreach ( $this->get_option() as $property => $value ) {
				if ( property_exists( $this, $property ) ) {
					$this->{$property} = $value;
				}
			}

			$creds = $this->get_option( 'token_credentials' );

			if ( is_object( $creds ) ) {
				$this->access_token = $creds->getIdentifier();
				$this->access_token_secret = $creds->getSecret();
			}
		}

		/**
		 * Do the API discovery.
		 *
		 * @since  0.2.0
		 *
		 * @param  string $url The URL to discover.
		 *
		 * @return string|WP_Error The API endpoint URL or WP_Error.
		 */
		public function do_discovery( $url = '' ) {
			$url = esc_url_raw( $url ? $url : $this->api_url );

			try {
				$site = \WordPress\Discovery\discover( $url );
			}
			catch ( Exception $e ) {
				$msg = sprintf( __( 'There is a problem with the provided api_url parameter. %s.' ), htmlspecialchars( $e->getMessage() ) );
				return $this->update_stored_error( $msg );
			}

			if ( empty( $site ) ) {
				$msg = sprintf( __( "Couldn't find the API at <code>%s</code>." ), htmlspecialchars( $url ) );
				$error = new WP_Error( 'wp_rest_api_rest_api_not_found', $msg, $this->args() );
				return $this->update_stored_error( $error );
			}

			if ( ! $site->supportsAuthentication( 'oauth1' ) ) {
				$error = new WP_Error( 'wp_rest_api_oauth_not_enabled_error', __( "Site doesn't appear to support OAuth 1.0a authentication.", 'wds-wp-rest-api-connect' ), $this->args() );
				return $this->update_stored_error( $error );
			}

			$this->set_api_url( $site->getIndexURL() );
			$this->update_option( 'api_url', $this->api_url, false );
			$this->update_option( 'auth_urls', $site->getAuthenticationData( 'oauth1' ) );

			return $this->api_url;
		}

		/**
		 * Get the authorization (login) URL for the server.
		 *
		 * @since  0.2.0
		 *
		 * @return string|WP_Error Authorization URL or WP_Error.
		 */
		public function get_authorization_url() {
			$this->set_object_properties();

			if ( ! $this->get_option( 'auth_urls' ) ) {
				return new WP_Error( 'wp_rest_api_discovery_incomplete', sprintf( __( 'Please call %s.', 'wds-wp-rest-api-connect' ), __CLASS__ . '::do_discovery()' ), $this->args() );
			}

			$server = $this->get_server();
			// First part of OAuth 1.0 authentication is retrieving temporary credentials.
			// These identify you as a client to the server.
			try {
				$temp_credentials = $server->getTemporaryCredentials();
			} catch ( Exception $e ) {
				return $this->update_stored_error( $e->getMessage() );
			}

			$this->update_option( 'temp_credentials', $temp_credentials );

			return $server->getAuthorizationUrl( $temp_credentials );
		}

		/**
		 * Check if the authorization callback has been initiated.
		 *
		 * @since  0.2.0
		 *
		 * @return boolean
		 */
		public function is_authorizing() {
			if ( null !== $this->is_authorizing ) {
				return $this->is_authorizing;
			}

			$this->is_authorizing = false;

			if ( isset(
				$_REQUEST['step'],
				$_REQUEST['auth_key'],
				$_REQUEST['auth_nonce'],
				$_REQUEST['oauth_token'],
				$_REQUEST['oauth_verifier']
			) && 'authorize' === $_REQUEST['step'] ) {

				$nonce_check = wp_verify_nonce( $_REQUEST['auth_nonce'], md5( __FILE__ ) );
				$key_check = $_REQUEST['auth_key'] === $this->key();

				if ( $key_check && $nonce_check ) {
					$this->do_authorization( $_REQUEST['oauth_token'], $_REQUEST['oauth_verifier'] );
					$this->is_authorizing = true;
				}
			}

			return $this->is_authorizing;
		}

		/**
		 * If autoredirect is enabled, and we are not yet authorized,
		 * redirect to the server to get authorization.
		 *
		 * @since  0.2.0
		 *
		 * @return bool|WP_Error  WP_Error is an issue, else redirects.
		 */
		public function maybe_redirect_to_authorization() {
			if (
				$this->autoredirect_authoriziation
				&& ! $this->is_authorizing()
				&& ! $this->connected()
			) {
				return $this->redirect_to_login();
			}

			return true;
		}

		/**
		 * Do the redirect to the authorization (login) URL.
		 *
		 * @since  0.2.0
		 *
		 * @return mixed  WP_Error if authorization URL lookup fails.
		 */
		public function redirect_to_login() {
			if ( ! $this->client_key ) {
				return new WP_Error( 'wp_rest_api_missing_client_data', __( 'Missing client key.', 'wds-wp-rest-api-connect' ), $this->args() );
			}

			$url = $this->get_authorization_url();
			if ( is_wp_error( $url ) ) {
				return $url;
			}

			// Second part of OAuth 1.0 authentication is to redirect the
			// resource owner to the login screen on the server.
			wp_redirect( $url );
			exit();
		}

		/**
		 * Swap temporary credentials for permanent authorized credentials.
		 *
		 * @since  0.2.0
		 *
		 * @param  string  $oauth_token
		 * @param  string  $oauth_verifier
		 *
		 * @return mixed   WP_Error if failure, else redirect to callback_uri.
		 */
		public function do_authorization( $oauth_token, $oauth_verifier ) {
			$server = $this->get_server();

			// Retrieve the temporary credentials from step 2
			$temp_credentials = $this->get_option( 'temp_credentials' );

			if ( ! $temp_credentials ) {
				$msg = __( "Couldn't find the API temporary credentials." );
				$error = new WP_Error( 'wp_rest_api_missing_temp_credentials', $msg, $this->args() );
				return $this->update_stored_error( $error );
			}

			/*
			 * Third and final part to OAuth 1.0 authentication is to retrieve token
			 * credentials (formally known as access tokens in earlier OAuth 1.0
			 * specs).
			 */
			$this->update_option(
				'token_credentials',
				$server->getTokenCredentials( $temp_credentials, $oauth_token, $oauth_verifier ),
				false
			);

			// Now, we'll store the token credentials and discard the
			// temporary ones - they're irrelevant at this stage.
			$this->delete_option( 'temp_credentials' );

			wp_redirect( $this->callback_uri );
			exit();
		}

		/**
		 * Get's authorized user. Useful for testing authenticated connection.
		 *
		 * @since  0.2.0
		 *
		 * @return mixed  User object or WP_Error object.
		 */
		public function get_user() {
			if ( ! $this->access_token ) {
				$error = new WP_Error( 'wp_rest_api_not_authorized', __( 'Authorization has not yet been granted.', 'wds-wp-rest-api-connect' ) , $this->args() );
				return $this->update_stored_error( $error );
			}

			try {
				$user = $this->get_server()->getUserDetails( $this->get_option( 'token_credentials' ) );
			} catch ( Exception $e ) {
				return new WP_Error( 'wp_rest_api_no_user_found', $e->getMessage(), $this->args() );
			}
			return $user;
		}

		/**
		 * Get WPServer object
		 *
		 * @since  0.2.0
		 *
		 * @return WPServer
		 */
		function get_server() {
			if ( ! empty( $this->server ) ) {
				return $this->server;
			}

			date_default_timezone_set('UTC');

			$this->set_callback_uri( $this->callback_uri ? $this->callback_uri : $this->get_requested_url() );

			$callback_uri = add_query_arg( array(
				'step' => 'authorize',
				'auth_key' => $this->key(),
				'auth_nonce' => wp_create_nonce( md5( __FILE__ ) ),
			), $this->callback_uri );

			$this->server = new WPServer( array(
				'identifier'   => $this->client_key,
				'secret'       => $this->client_secret,
				'api_root'     => $this->api_url,
				'auth_urls'    => $this->get_option( 'auth_urls' ),
				'callback_uri' => $callback_uri,
			) );

			return $this->server;
		}

		/**
		 * Get the current URL
		 *
		 * @since  0.2.0
		 *
		 * @return string current URL
		 */
		public function get_requested_url() {
			$scheme = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] ) ? 'https' : 'http';
			$here = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
				// Strip the query string
				$here = str_replace( '?' . $_SERVER['QUERY_STRING'], '', $here );
			}

			return $here;
		}

		/**
		 * Get the stored-data key
		 *
		 * @since  0.2.0
		 *
		 * @return string
		 */
		public function key() {
			try {
				return $this->store->get_key();
			} catch ( Exception $e ) {
				if ( $this->api_url ) {
					return $this->store->set_key( 'apiconnect_' . md5( sanitize_text_field( $this->api_url ) ) );
				}
			}

			return '';
		}

		/**
		 * Tests whether connection has been created.
		 *
		 * @since  0.2.0
		 *
		 * @return bool
		 */
		public function connected() {
			return (bool) $this->get_option( 'token_credentials' );
		}

		/**
		 * Tests whether API discovery has been completed.
		 *
		 * @since  0.2.0
		 *
		 * @return bool
		 */
		public function discovered() {
			return (bool) $this->get_option( 'auth_urls' );
		}

		/**
		 * Get current object data for debugging.
		 *
		 * @since  0.2.0
		 *
		 * @return array
		 */
		public function args() {
			return array(
				'key'                 => $this->key(),
				'client_key'          => $this->client_key,
				'client_secret'       => $this->client_secret,
				'api_url'             => $this->api_url,
				'headers'             => $this->headers,
				'auth_urls'           => $this->get_option( 'auth_urls' ),
				'callback_uri'        => $this->callback_uri,
				'access_token'        => $this->access_token,
				'access_token_secret' => $this->access_token_secret,
				'endpoint_url'        => $this->endpoint_url,
			);
		}

		/**
		 * Sets the client_key object property
		 *
		 * @since 0.2.0
		 *
		 * @param string  $value Value to set
		 */
		public function set_client_key( $value ) {
			$this->client_key = $value;
			return $this->consume;
		}

		/**
		 * Sets the client_secret object property
		 *
		 * @since 0.2.0
		 *
		 * @param string  $value Value to set
		 */
		public function set_client_secret( $value ) {
			$this->client_secret = $value;
			return $this->consume;
		}

		/**
		 * Sets the api_url object property
		 *
		 * @since 0.2.0
		 *
		 * @param string  $value Value to set
		 */
		public function set_api_url( $value ) {
			$this->api_url = $value;
			return $this->api_url;
		}

		/**
		 * Sets the callback_uri object property
		 *
		 * @since 0.2.0
		 *
		 * @param string  $value Value to set
		 */
		public function set_callback_uri( $value ) {
			$this->callback_uri = $value;
			return $this->callback_uri;
		}

		/**
		 * Sets the endpoint_url object property
		 *
		 * @since 0.2.0
		 *
		 * @param string  $value Value to set
		 */
		public function set_endpoint_url( $value ) {
			$this->endpoint_url = $value;
			return $this->endpoin;
		}

		/**
		 * Set request method
		 *
		 * @since 0.1.1
		 *
		 * @param string $method Request method
		 *
		 * @return string        New request method
		 */
		public function set_method( $method ) {
			return $this->method = $method;
		}

		/**
		 * Perform an authenticated GET request
		 *
		 * @since  0.1.0
		 *
		 * @param  string $path    Url endpoint path to resource
		 * @param  array  $data    Array of data to send in request.
		 *
		 * @return object|WP_Error Updated object, or WP_Error
		 */
		public function auth_get_request( $path, $data = array() ) {
			return $this->auth_request( $path, (array) $data, 'GET' );
		}

		/**
		 * Perform an authenticated POST request
		 *
		 * @since  0.1.0
		 *
		 * @param  string $path    Url endpoint path to resource
		 * @param  array  $data    Array of data to send in request.
		 *
		 * @return object|WP_Error Updated object, or WP_Error
		 */
		public function auth_post_request( $path, $data = array() ) {
			return $this->auth_request( $path, (array) $data, 'POST' );
		}

		/**
		 * Perform an authenticated HEAD request
		 *
		 * @since  0.1.0
		 *
		 * @param  string $path    Url endpoint path to resource
		 * @param  array  $data    Array of data to send in request.
		 *
		 * @return object|WP_Error Updated object, or WP_Error
		 */
		public function auth_head_request( $path, $data = array() ) {
			return $this->auth_request( $path, (array) $data, 'HEAD' );
		}

		/**
		 * Perform an authenticated DELETE request
		 *
		 * @since  0.1.0
		 *
		 * @param  string $path    Url endpoint path to resource
		 *
		 * @return object|WP_Error Updated object, or WP_Error
		 */
		public function auth_delete_request( $path, $data = array() ) {
			return $this->auth_request( $path, (array) $data, 'DELETE' );
		}

		/**
		 * Perform an authenticated request
		 *
		 * @since  0.1.0
		 *
		 * @param  string $path         Url endpoint path to resource
		 * @param  array  $request_args Array of data to send in request.
		 * @param  string $method       Request method. Defaults to GET
		 *
		 * @return object|WP_Error      Updated object, or WP_Error
		 */
		public function auth_request( $path, $request_args = array(), $method = 'GET' ) {
			$this->set_method( $method );

			if ( ! $this->client_key ) {
				return new WP_Error( 'wp_rest_api_missing_client_data', __( 'Missing client key.', 'wds-wp-rest-api-connect' ), $this->args() );
			}

			if ( ! $this->access_token || ! $this->get_option( 'token_credentials' ) ) {
				return new WP_Error( 'wp_rest_api_not_authorized', __( 'Authorization has not yet been granted.', 'wds-wp-rest-api-connect' ) , $this->args() );
			}

			$this->endpoint_url = $this->api_url( $path );

			$creds   = $this->get_option( 'token_credentials' );
			$server  = $this->get_server();
			$http    = $server->createHttpClient();
			$headers = $server->getHeaders( $creds, $method, $this->endpoint_url, $request_args );

			try {

				$request = $http->createRequest( $method, $this->endpoint_url, $headers, $request_args );
				$response = $request->send();

				$this->response_code = $response->getStatusCode();

				$headers = array();
				foreach ( $response->getHeaders() as $header ) {
					$headers[ strtolower( $header->getName() ) ] = (string) $header;
				}

				$this->response = array(
					'headers' => $headers,
					'body'    => $response->getBody( true ),
					'response' => array(
						'code'    => $this->response_code,
						'message' => $response->getReasonPhrase(),
					),
				);

			} catch ( Exception $e ) {
				$response = $e->getResponse();
				$body = $response->getBody( true );
				$this->response_code = $response->getStatusCode();

				$this->response = new WP_Error( 'wp_rest_api_response_error',
					"Received error [$body] with status code [$this->response_code] when making request."
				);

				return $this->response;
			}

			return $this->get_json_if_json( wp_remote_retrieve_body( $this->response ) );
		}

		/**
		 * Get the api_url and append included path
		 *
		 * @since  0.1.0
		 *
		 * @param  string  $path Option path to append
		 *
		 * @return string        REST request URL
		 */
		public function api_url( $path = '' ) {
			// Make sure we only have a path
			$path = str_ireplace( $this->api_url, '', $path );
			$path = ltrim( $path, '/' );
			return $path ? trailingslashit( $this->api_url ) . $path : $this->api_url;
		}

		/**
		 * Gets the request URL from the JSON description object
		 *
		 * @since  0.1.0
		 *
		 * @return mixed Request URL or error
		 */
		function request_token_url() {
			return $this->get_server()->urlTemporaryCredentials();
		}

		/**
		 * Gets the authorization base URL from the JSON description object
		 *
		 * @since  0.1.0
		 *
		 * @return mixed Authorization URL or error
		 */
		function request_authorize_url() {
			return $this->get_server()->urlAuthorization();
		}

		/**
		 * Gets the access URL from the JSON description object
		 *
		 * @since  0.1.0
		 *
		 * @return mixed Access URL or error
		 */
		function request_access_url() {
			return $this->get_server()->urlTokenCredentials();
		}

		/**
		 * Retrieves the API Description object
		 *
		 * @since  0.1.0
		 *
		 * @return object  Description object for api_url
		 */
		public function get_api_description() {
			if ( ! $this->client_key ) {
				return new WP_Error( 'wp_rest_api_missing_client_data', __( 'Missing client key.', 'wds-wp-rest-api-connect' ), $this->args() );
			}

			if ( ! $this->json_desc ) {
				if ( ! $this->cache_api_description_for_api_url() ) {
					return new WP_Error( 'wp_rest_api_connection_failed_error', __( 'There was a problem connecting to the API URL specified.', 'wds-wp-rest-api-connect' ), $this->args() );
				}
			}
			return $this->json_desc;
		}

		/**
		 * Fetches and caches the API Description object
		 *
		 * @since  0.1.0
		 *
		 * @return mixed  Description object for api_url
		 */
		public function cache_api_description_for_api_url() {
			if ( ! isset( $_GET['delete-trans'] ) && $this->json_desc = $this->transient->get() ) {
				return $this->json_desc;
			}

			$this->response = wp_remote_get( $this->api_url, array(
				'headers' => $this->headers,
			) );

			$body  = wp_remote_retrieve_body( $this->response );
			$error = false;

			if ( ! $body || ( isset( $this->response['response']['code'] ) && 200 != $this->response['response']['code'] ) ) {
				$error = sprintf( __( 'Could not retrive body from URL: "%s"', 'wds-wp-rest-api-connect' ), $this->api_url );

				$this->update_stored_error( $error );

				if ( defined( 'WP_DEBUG' ) ) {
					error_log( 'error: '. $error );
					error_log( 'request args: '. print_r( $this->args(), true ) );
					error_log( 'request response: '. print_r( $this->response, true ) );
				}
			}

			$this->json_desc = $this->is_json( $body );

			if ( ! $error && $this->json_desc ) {
				$this->transient->set( $this->json_desc );
			}

			return $this->json_desc;
		}

		/**
		 * Retrieve stored option
		 *
		 * @since  0.1.0
		 *
		 * @param  string  $option Option array key
		 * @param  string  $key    Key for secondary array
		 * @param  boolean $force  Force a new call to get_option
		 *
		 * @return mixed           Value of option requested
		 */
		public function get_option( $option = 'all' ) {
			if ( $this->key() ) {
				try {
					return $this->store->get( $option );
				} catch ( Exception $e ) {
				}
			}
			return array();
		}

		/**
		 * Update the options array
		 *
		 * @since  0.1.0
		 *
		 * @param  string  $option Option array key
		 * @param  mixed   $value  Value to be updated
		 * @param  boolean $set    Whether to set the updated value in the DB.
		 *
		 * @return                 Original $value if successful
		 */
		public function update_option( $option, $value, $set = true ) {
			try {
				return $this->store->update( $value, $option, '', $set );
			} catch ( Exception $e ) {
				return false;
			}
		}

		/**
		 * Handles deleting the stored data for a connection
		 *
		 * @since  0.1.3
		 *
		 * @return bool  Result of delete_option
		 */
		public function delete_option( $option = '' ) {
			try {
				$this->transient->delete();
				return $this->store->delete( $option );
			} catch ( Exception $e ) {
				return false;
			}
		}

		/**
		 * Fetches the wp_rest_api_connect_error message.
		 *
		 * @since  0.1.3
		 *
		 * @return string Stored error message value.
		 */
		public function get_stored_error_message() {
			$errors = $this->get_stored_error();
			return isset( $errors['message'] ) ? $errors['message'] : '';
		}

		/**
		 * Fetches the wp_rest_api_connect_error request_args.
		 *
		 * @since  0.1.3
		 *
		 * @return string Stored error request_args value.
		 */
		public function get_stored_error_request_args() {
			$errors = $this->get_stored_error();
			return isset( $errors['request_args'] ) ? $errors['request_args'] : '';
		}

		/**
		 * Fetches the wp_rest_api_connect_error request_response.
		 *
		 * @since  0.1.3
		 *
		 * @return string Stored error request_response value.
		 */
		public function get_stored_error_request_response() {
			$errors = $this->get_stored_error();
			return isset( $errors['request_response'] ) ? $errors['request_response'] : '';
		}

		/**
		 * Fetches the wp_rest_api_connect_error option value.
		 *
		 * @since  0.1.3
		 *
		 * @return mixed  wp_rest_api_connect_error option value.
		 */
		public function get_stored_error() {
			return $this->error_store->get();
		}

		/**
		 * Updates/replaces the wp_rest_api_connect_error option.
		 *
		 * @since  0.1.3
		 *
		 * @param  string  $error Error message
		 *
		 * @return void
		 */
		public function update_stored_error( $error = '' ) {
			if ( '' !== $error ) {
				$this->error_store->set( array(
					'message'          => is_wp_error( $error ) ? $error->get_error_message() : $error,
					'request_args'     => print_r( $this->args(), true ),
					'request_response' => print_r( $this->response, true ),
				) );

				return ! is_wp_error( $error )
					? new WP_Error( 'wp_rest_api_connect_error', $error, $this->args() )
					: $error;
			} else {
				$this->delete_stored_error();
			}

			return true;
		}

		/**
		 * Fetches the wp_rest_api_connect_error option value
		 *
		 * @since  0.1.3
		 *
		 * @return mixed  wp_rest_api_connect_error option value
		 */
		public function delete_stored_error() {
			return $this->error_store->delete();
		}

		/**
		 * Deletes all stored data for this connection.
		 *
		 * @since  0.2.0
		 */
		public function reset_connection() {
			$deleted = $this->delete_option();
			return $deleted && $this->delete_stored_error();
		}

		/**
		 * Determines if a string is JSON, and if so, decodes it and returns it. Else returns unchanged body object.
		 *
		 * @since  0.1.2
		 *
		 * @param  string $body   HTTP retrieved body
		 *
		 * @return mixed  Decoded JSON object or unchanged body
		 */
		function get_json_if_json( $body ) {
			$json = $body ? $this->is_json( $body ) : false;
			return $body && $json ? $json : $body;
		}

		/**
		 * Determines if a string is JSON, and if so, decodes it.
		 *
		 * @since  0.1.0
		 *
		 * @param  string $string String to check if is JSON
		 *
		 * @return boolean|array  Decoded JSON object or false
		 */
		function is_json( $string ) {
			$json = is_string( $string ) ? json_decode( $string ) : false;
			return $json && ( is_object( $json ) || is_array( $json ) )
				? $json
				: false;
		}

		/**
		 * Magic getter for our object.
		 *
		 * @param string $field
		 *
		 * @throws Exception Throws an exception if the field is invalid.
		 *
		 * @return mixed
		 */
		public function __get( $field ) {
			switch ( $field ) {
				case 'key':
				case 'client_key':
				case 'client_secret':
				case 'api_url':
				case 'headers':
				case 'callback_uri':
				case 'access_token':
				case 'access_token_secret':
				case 'endpoint_url':
				case 'method':
					return $this->{$field};
				case 'token_credentials':
				case 'auth_urls':
					return $this->get_option( 'auth_urls' );
				default:
					throw new Exception( 'Invalid property: ' . $field );
			}
		}

	}

endif;
