<?php

/**
 * Connect to the WordPress JSON API using WordPress APIs
 *
 * @author  Justin Sternberg <justin@webdevstudios.com>
 * @package WP_JSON_API_Connect
 * @version 0.1.0
 */
class WP_JSON_API_Connect {

	/**
	 * JSON description object from the json_url
	 *
	 * @var JSON object
	 */
	protected $json_desc      = null;

	/**
	 * Connect object arguments
	 *
	 * @var array
	 */
	protected $args           = array();

	/**
	 * Generated santized key based on json_url
	 *
	 * @var string
	 */
	protected $key            = '';

	/**
	 * Option key based on json_url
	 *
	 * @var string
	 */
	protected $option_key     = '';

	/**
	 * The URL being requested
	 *
	 * @var string
	 */
	protected $endpoint_url   = '';

	/**
	 * The OAuth object in the JSON description object
	 *
	 * @var object
	 */
	protected $auth_object    = null;

	/**
	 * Retrieved token for authorization URL
	 *
	 * @var array
	 */
	protected $token_response = null;

	/**
	 * Arguments for for URL being requested
	 *
	 * @var array
	 */
	protected $request_args   = false;

	/**
	 * Stored options containing token data for a URL
	 *
	 * @var array
	 */
	protected $options        = array();

	/**
	 * Initate our connect object
	 *
	 * @since 0.1.0
	 *
	 * @param array $args Arguments containing 'consumer_key', 'consumer_secret', and the 'json_url'
	 */
	public function __construct( $args = array() ) {
		$this->args = wp_parse_args( $args, array(
			'consumer_key'       => '',
			'consumer_secret'    => '',
			'json_url'           => '',
			'oauth_token_secret' => '',
		) );

		$this->key        = sanitize_title( $this->args['json_url'] );
		$this->option_key = 'wp_json_api_connect_' . $this->key;

		if (
			isset( $_REQUEST['oauth_authorize_url'], $_REQUEST['oauth_token'] )
			&& sanitize_title( $_REQUEST['oauth_authorize_url'] ) == $this->key
		) {
			$this->store_token_and_secret( $_REQUEST );
		}
	}

	/**
	 * Retrieve URL to request user authorization
	 *
	 * @since  0.1.0
	 *
	 * @param  array $callback_query_params Additional query paramaters
	 *
	 * @return string|false                 Authorization Request URL
	 */
	public function get_authorization_url( $callback_query_params = array() ) {
		if ( ! ( $request_authorize_url = $this->request_authorize_url() ) ) {
			return false;
		}

		if ( $this->get_url_access_token_data( 'oauth_token_secret' ) ) {
			return false;
		}

		$token = $this->get_token();
		$token_array = $this->parse_str( $token );

		$callback_query_params = array_merge( array(
			'oauth_authorize_url' => urlencode( $this->args['json_url'] ),
		), $callback_query_params );

		$query_args = array(
			'oauth_token' => $token_array['oauth_token'],
			'oauth_callback' => urlencode( add_query_arg( $callback_query_params, admin_url() ) ),
		);

		$request_url = add_query_arg( $query_args, esc_url( $request_authorize_url ) );

		return $request_url;
	}

	/**
	 * Retrieve required token for authorization url
	 *
	 * @since  0.1.0
	 *
	 * @return array Array of token data
	 */
	public function get_token() {
		if ( $this->token_response ) {
			return $this->token_response;
		}

		if ( ! ( $this->endpoint_url = $this->request_token_url() ) ) {
			return false;
		}

		if ( is_wp_error( $this->endpoint_url ) ) {
			return $this->endpoint_url;
		}

		$response = wp_remote_post( esc_url( $this->endpoint_url ), array( 'body' => $this->request_args() ) );

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			return new WP_Error( 'wp_json_api_request_token_error', sprintf( __( 'Could not retrive body from %s.', 'WP_JSON_API_Connect' ), $this->endpoint_url ) );
		}

		$this->token_response = $body && ( $json = $this->is_json( $body ) ) ? $json : $body;

		return $this->token_response;
	}

	/**
	 * Stores data retrieved by the authorization step
	 *
	 * @since  0.1.0
	 *
	 * @param  array $args Additional request arguments
	 *
	 * @return array|false Array of updated token data or false
	 */
	public function store_token_and_secret( $args ) {
		if ( ! ( $this->endpoint_url = $this->request_access_url() ) ) {
			return false;
		}

		$args     = $this->request_args( $args );
		$response = wp_remote_post( esc_url( $this->endpoint_url ), array( 'body' => $args ) );
		$body     = wp_remote_retrieve_body( $response );

		if ( ! isset( $response['response']['code'] ) || 200 != $response['response']['code'] ) {
			return;
		}

		$token_array = array_merge( $args, $this->parse_str( $body ) );

		if ( isset( $token_array['oauth_token'], $token_array['oauth_verifier'], $token_array['oauth_token_secret'] ) ) {

			$this->update_url_access_tokens( $token_array );
		}

		return $token_array;
	}

	/**
	 * Update option store for oauth tokens for this url
	 *
	 * @since  0.1.0
	 *
	 * @param  array $args Arguments to check against
	 *
	 * @return array       Oauth tokens
	 */
	public function update_url_access_tokens( $args ) {
		$tokens = array_intersect_key( $args, array(
			'oauth_token',
			'oauth_token_secret',
			'oauth_verifier',
		) );

		if ( ! empty( $tokens ) ) {
			$this->update_option( 'tokens', $tokens );
		}

		return $tokens;
	}

	/**
	 * Perform an authenticated request
	 *
	 * @since  0.1.0
	 *
	 * @param  string $path    Url endpoint path to resource
	 * @param  array  $data    Array of data to update resource
	 *
	 * @return object|WP_Error Updated object, or WP_Error
	 */
	public function auth_request( $path, $data ) {

		if ( ! ( $token_data = $this->get_url_access_token_data() ) ) {
			return new WP_Error( 'wp_json_api_missing_token_data', sprintf( __( 'Missing token data. Try <a href="%s">reauthenticating</a>.', 'WP_JSON_API_Connect' 	), $this->get_authorization_url() ) );
		}

		$this->endpoint_url   = $this->json_url( $path );
		echo '<xmp>$this->endpoint_url: '. print_r( $this->endpoint_url, true ) .'</xmp>';
		$request_args         = (array) $token_data;
		$request_args['data'] = (array) $data;
		$oauth_args           = $this->request_args( $request_args );
		$response             = wp_remote_post( $this->endpoint_url, array( 'body' => $oauth_args ) );
		$body                 = wp_remote_retrieve_body( $response );

		return $body && ( $json = $this->is_json( $body ) ) ? $json : $body;
	}

	/**
	 * Builds request's 'OAuth' authentication arguments
	 *
	 * @since  1.0.0
	 *
	 * @param  array $args Optional additional arguments
	 *
	 * @return array       Request arguments array
	 */
	function request_args( $header_args = array() ) {
		// Set our oauth data
		$this->request_args = wp_parse_args( $header_args, array(
			'oauth_consumer_key'     => $this->args['consumer_key'],
			'oauth_nonce'            => time(),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => time(),
			'oauth_version'          => $this->auth_object()->version,
		) );

		// create our unique oauth signature
		$this->request_args['oauth_signature'] = $this->oauth_signature();
		// echo '<xmp>oauth_signature: '. print_r( $this->request_args['oauth_signature'], true ) .'</xmp>';

		return $this->request_args;
	}

	/**
	 * Creates an oauth signature for the api call.
	 *
	 * @since  1.0.0
	 *
	 * @return string Unique Oauth signature
	 */
	function oauth_signature() {
		if ( isset( $this->request_args['oauth_signature'] ) ) {
			unset( $this->request_args['oauth_signature'] );
		}

		$query_string = $this->create_signature_string( $this->request_args );

		$string_to_sign = 'POST&'. rawurlencode( $this->endpoint_url ) .'&'. $query_string;

		$this->args['oauth_token_secret'] = array_key_exists( 'oauth_token_secret', $this->request_args )
			? $this->request_args['oauth_token_secret']
			: $this->args['oauth_token_secret'];

		$composite_key = rawurlencode( $this->args['consumer_secret'] ) .'&'. rawurlencode( $this->args['oauth_token_secret'] );

		return base64_encode( hash_hmac( 'sha1', $string_to_sign, $composite_key, true ) );
	}

	/**
	 * Creates a signature string from all query parameters
	 *
	 * @since  0.1.0
	 *
	 * @param  array  $params Array of query parameters
	 *
	 * @return string         Signature string
	 */
	public function create_signature_string( $params ) {
		ksort( $params );
		// normalize parameter key/values
		array_walk_recursive( $params, array( $this, 'normalize_parameters' ) );
		// join with ampersand
		return implode( '%26', $this->sringify_params( $params ) );
	}

	/**
	 * Normalize each parameter by assuming each parameter may have already been encoded, so attempt to decode, and then
	 * re-encode according to RFC 3986
	 *
	 * @since 0.1.0
	 *
	 * @see   rawurlencode()
	 * @param string $key
	 * @param string $value
	 */
	protected function normalize_parameters( &$key, &$value ) {
		$key   = rawurlencode( rawurldecode( $key ) );
		$value = rawurlencode( rawurldecode( $value ) );
	}

	/**
	 * Creates a urlencoded string out of an array of query parameters
	 *
	 * @since  0.1.0
	 *
	 * @param  array  $params Array of parameters to convert.
	 * @param  string $key    Optional Array key to append
	 *
	 * @return string         Urlencoded string
	 */
	public function sringify_params( $params, $key = '' ) {

		if ( ! isset( $this->query_params ) ) {
			$this->query_params = array();
		}

		foreach ( $params as $param_key => $param_value ) {
			if ( is_array( $param_value ) ) {
				// Recursive
				$this->sringify_params( $param_value, $param_key );
			} else {
				if ( $key ) {
					// Handles arrays of data
					$param_key = $key . '[' . $param_key . ']';
				}
				// join with equals sign
				$string = $param_key . '=' . $param_value;
				$this->query_params[] = urlencode( $string );
			}
		}
		return $this->query_params;
	}

	/**
	 * Get stored token data from option for json_url
	 *
	 * @since  0.1.0
	 *
	 * @param  string  $param Get a specific token key value
	 *
	 * @return mixed|false    Value of token or false
	 */
	public function get_url_access_token_data( $param = '' ) {
		$tokens = $this->get_option( 'tokens' );
		if ( ! empty( $tokens ) && $param ) {
			return array_key_exists( $param, $tokens )
				? $tokens[ $param ]
				: false;
		}
		return $tokens;
	}

	/**
	 * Gets the request URL from the JSON description object
	 *
	 * @since  0.1.0
	 *
	 * @return string  Request URL
	 */
	function request_token_url() {
		return $this->retrieve_and_set_var_from_description( 'request_token_url', 'request' );
	}

	/**
	 * Gets the authorization URL from the JSON description object
	 *
	 * @since  0.1.0
	 *
	 * @return string  Authorization URL
	 */
	function request_authorize_url() {
		return $this->retrieve_and_set_var_from_description( 'request_authorize_url', 'authorize' );
	}

	/**
	 * Gets the access URL from the JSON description object
	 *
	 * @since  0.1.0
	 *
	 * @return string  Access URL
	 */
	function request_access_url() {
		return $this->retrieve_and_set_var_from_description( 'request_access_url', 'access' );
	}

	/**
	 * Retrieves the OAuth authentication object from the JSON description object
	 *
	 * @since  0.1.0
	 *
	 * @return object  Authentication object
	 */
	function auth_object() {
		return $this->retrieve_and_set_var_from_description( 'auth_object' );
	}

	/**
	 * Retrieves a key from the JSON description object and sets a class property
	 *
	 * @since  0.1.0
	 *
	 * @param  string  $var   Description key to retrieve
	 * @param  sring   $route Authentication object to retrieve
	 *
	 * @return mixed          Value requested
	 */
	public function retrieve_and_set_var_from_description( $var, $route = '' ) {
		if ( isset( $this->{$var} ) && $this->{$var} ) {
			return $this->{$var};
		}
		if ( ! $this->json_desc ) {
			if ( ! $this->cache_api_description_for_json_url() ) {
				return $this->connection_failed_msg();
			}
		}

		if ( empty( $this->json_desc->authentication ) || empty( $this->json_desc->authentication->oauth1 ) ) {
			return $this->oauth_not_enabled_msg();
		}

		if ( $route && empty( $this->json_desc->authentication->oauth1->{$route} ) ) {
			return $this->oauth_not_enabled_msg();
		}

		$this->{$var} = $route ? $this->json_desc->authentication->oauth1->{$route} : $this->json_desc->authentication->oauth1;

		return $this->{$var};
	}

	/**
	 * Handles outputting a WP_Error for when the OAuth plugin is not active on the client site
	 *
	 * @since  0.1.0
	 *
	 * @return WP_Error object
	 */
	public function oauth_not_enabled_msg() {
		return new WP_Error( 'wp_json_api_oauth_not_enabled_error', __( "Could not locate OAuth information; are you sure it's enabled?", 'WP_JSON_API_Connect' ) );
	}

	/**
	 * Handles outputting a WP_Error for when their is a connection issue
	 *
	 * @since  0.1.0
	 *
	 * @return WP_Error object
	 */
	public function connection_failed_msg() {
		return new WP_Error( 'wp_json_api_connection_failed_error', __( 'There was a problem connecting to the API URL specified.', 'WP_JSON_API_Connect' ) );
	}

	/**
	 * Retrieves and caches the Description object
	 *
	 * @since  0.1.0
	 *
	 * @return object  Description object for json_url
	 */
	public function cache_api_description_for_json_url() {
		$transient_id = 'wp_json_api_connect_api_description_'. $this->key;

		if ( $this->json_desc = get_transient( $transient_id ) ) {
			return $this->json_desc;
		}

		$response = wp_remote_get( $this->args['json_url'] );
		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			throw new Exception( "Could not retrive body from {$this->args['json_url']}." );
		}

		$this->json_desc = $body && ( $json = $this->is_json( $body ) ) ? $json : false;

		if ( $this->json_desc ) {
			set_transient( $transient_id, $this->json_desc, HOUR_IN_SECONDS );
		}

		return $this->json_desc;
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
		return is_string( $string ) && ( $json = json_decode( $string ) ) && ( is_object( $json ) || is_array( $json ) )
			? $json
			: false;
	}

	/**
	 * Get the json_url and append included path
	 *
	 * @since  0.1.0
	 *
	 * @param  string  $path Option path to append
	 *
	 * @return string        JSON request URL
	 */
	public function json_url( $path = '' ) {
		// Make sure we only have a path
		$path = str_ireplace( $this->args['json_url'], '', $path );
		$path = ltrim( $path, '/' );
		return $path ? trailingslashit( $this->args['json_url'] ) . $path : $this->args['json_url'];
	}

	/**
	 * Parses a string into an array
	 *
	 * @since  0.1.0
	 *
	 * @param  string  $string String to parse
	 *
	 * @return array           Parsed array
	 */
	public function parse_str( $string ) {
		$array = array();
		parse_str( $string, $array );
		return (array) $array;
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
	 * @return mixex           Value of option requested
	 */
	public function get_option( $option, $key = '', $force = false ) {

		if ( empty( $this->options ) || $force ) {
			$this->options = get_option( $this->option_key, array() );
		}

		if ( 'all' == $option ) {
			return $this->options;
		}

		if ( ! array_key_exists( $option, $this->options ) || ! $this->options[ $option ] ) {
			return false;
		}

		if ( ! $key ) {
			return $this->options[ $option ];
		}

		return array_key_exists( $key, $this->options[ $option ] ) ? $this->options[ $option ][ $key ] : false;
	}

	/**
	 * Update the options array
	 *
	 * @since  0.1.0
	 *
	 * @param  string  $option Option array key
	 * @param  mixed   $value  Value to be updated
	 * @param  string  $key    Key for secondary array
	 */
	public function update_option( $option, $value, $key = '' ) {
		$this->get_option( 'all' );

		if ( $key ) {
			$this->options[ $option ][ $key ] = $value;
		} else {
			$this->options[ $option ] = $value;
		}

		$this->do_update();
	}

	/**
	 * Peform the option saving
	 *
	 * @since  0.1.0
	 *
	 * @return bool  Whether option was properly updated
	 */
	public function do_update() {
		if ( get_option( $this->option_key ) ) {
			$updated = update_option( $this->option_key, $this->options );
		} else {
			$updated = add_option( $this->option_key, $this->options, '', 'no' );
		}

		return $updated;
	}

	/**
	 * Delete an option or specific array value
	 *
	 * @since  0.1.0
	 *
	 * @param  string  $option Option array key
	 * @param  string  $key    Key for secondary array
	 *
	 * @return bool            Whether option was deleted
	 */
	public function delete_option( $option, $key = '' ) {
		$this->get_option( 'all' );
		if ( $key ) {
			if ( array_key_exists( $option, $this->options ) && array_key_exists( $key, $this->options[ $option ] ) ) {
				unset( $this->options[ $option ][ $key ] );
				return $this->do_update();
			}
			return false;
		}
		if ( array_key_exists( $option, $this->options ) ) {
			unset( $this->options[ $option ] );
			return $this->do_update();
		}
		return false;
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
		switch( $field ) {
			case 'json':
			case 'args':
			case 'option_key':
				return $this->{$field};
			case 'json_url':
			case 'consumer_key':
			case 'consumer_secret':
				return $this->args[ $field ];
			default:
				throw new Exception( 'Invalid property: ' . $field );
		}
	}

}
