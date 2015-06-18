<?php

if ( ! class_exists( 'WDS_WP_JSON_API_Connect' ) ) :
	
	/**
	 * Connect to the WordPress JSON API using WordPress APIs
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
	 * @package WDS_WP_JSON_API_Connect
	 * @version 0.1.2
	 */
	class WDS_WP_JSON_API_Connect {

		/**
		 * JSON description object from the json_url
		 *
		 * @var mixed
		 */
		protected $json_desc = null;

		/**
		 * Connect object arguments
		 *
		 * @var array
		 */
		protected $args = array();

		/**
		 * Generated santized key based on json_url
		 *
		 * @var string
		 */
		protected $key = '';

		/**
		 * Option key based on json_url
		 *
		 * @var string
		 */
		protected $option_key = '';

		/**
		 * The URL being requested
		 *
		 * @var string
		 */
		protected $endpoint_url = '';

		/**
		 * The OAuth object in the JSON description object
		 *
		 * @var object
		 */
		protected $auth_object = null;

		/**
		 * Retrieved token for authorization URL
		 *
		 * @var mixed
		 */
		protected $token_response = null;

		/**
		 * Arguments for for URL being requested
		 *
		 * @var array
		 */
		protected $request_args = false;

		/**
		 * Stored options containing token data for a URL
		 *
		 * @var array
		 */
		protected $options = array();

		/**
		 * Default request method
		 *
		 * @var string
		 */
		protected $method = null;

		/**
		 * HTTP response object
		 *
		 * @var object
		 */
		protected $response = null;

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

			$this->key        = md5( sanitize_title( $this->args['json_url'] ) );
			$this->option_key = 'apiconnect_' . $this->key;

			if ( isset( $_REQUEST['oauth_authorize_url'], $_REQUEST['oauth_token'] ) ) {
				if ( md5( sanitize_title( $_REQUEST['oauth_authorize_url'] ) ) == $this->key ) {
					$this->store_token_and_secret( $_REQUEST );
				}
			}
		}

		/**
		 * Retrieve URL to request user authorization
		 *
		 * @since  0.1.0
		 *
		 * @param  array $callback_query_params Additional query paramaters
		 * @param  array $return_url   URL to return to when authorized.
		 *
		 * @return mixed               Authorization Request URL or error
		 */
		public function get_authorization_url( $callback_query_params = array(), $return_url = '' ) {
			if ( ! ( $request_authorize_url = $this->request_authorize_url() ) ) {
				return false;
			}

			if ( $this->get_url_access_token_data( 'oauth_token_secret' ) ) {
				return false;
			}

			$token = $this->get_token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$token_array = is_string( $token ) ? $this->parse_str( $token ) : (array) $token;

			if ( ! isset( $token_array['oauth_token'] ) ) {
				return new WP_Error( 'wp_json_api_get_token_error', sprintf( __( 'There was an error retrieving the token from %s.', 'WDS_WP_JSON_API_Connect' ), $this->endpoint_url ), array( 'token' => $token, 'request_authorize_url' => $request_authorize_url, 'method' => $this->get_method() ) );
			}

			$callback_query_params = array_merge( array(
				'oauth_authorize_url' => urlencode( $this->args['json_url'] ),
			), $callback_query_params );

			// Build redirect URL
			$return_url = $return_url ? esc_url( $return_url ) : ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

			$query_args = array(
				'oauth_token' => $token_array['oauth_token'],
				'oauth_callback' => urlencode( add_query_arg( $callback_query_params, $return_url ) ),
			);

			$request_url = add_query_arg( $query_args, esc_url( $request_authorize_url ) );

			return $request_url;
		}

		/**
		 * Perform an authenticated POST request
		 *
		 * @since  0.1.0
		 *
		 * @param  string $path    Url endpoint path to resource
		 * @param  array  $data    Array of data to update resource
		 *
		 * @return object|WP_Error Updated object, or WP_Error
		 */
		public function auth_post_request( $path, $data ) {
			return $this->auth_request( $path, (array) $data, 'POST' );
		}

		/**
		 * Perform an authenticated GET request
		 *
		 * @since  0.1.0
		 *
		 * @param  string $path    Url endpoint path to resource
		 *
		 * @return object|WP_Error Updated object, or WP_Error
		 */
		public function auth_get_request( $path = '' ) {
			return $this->auth_request( $path );
		}

		/**
		 * Perform an authenticated request
		 *
		 * @since  0.1.0
		 *
		 * @param  string $path         Url endpoint path to resource
		 * @param  array  $request_args Array of data to update resource
		 * @param  string $method       Request method. Defaults to GET
		 *
		 * @return object|WP_Error      Updated object, or WP_Error
		 */
		public function auth_request( $path = '', $request_args = array(), $method = 'GET' ) {

			$this->set_method( $method );

			if ( ! ( $token_data = $this->get_url_access_token_data() ) ) {
				$url = $this->get_authorization_url();
				if ( is_wp_error( $url ) ) {
					return $url;
				}
				return new WP_Error( 'wp_json_api_missing_token_data', sprintf( __( 'Missing token data. Try <a href="%s">reauthenticating</a>.', 'WDS_WP_JSON_API_Connect' ), $url ), $url );
			}

			if ( ! $path ) {
				return $this->get_api_description();
			}

			$this->endpoint_url = $this->json_url( $path );
			$request_args       = array_merge( (array) $token_data, $request_args );
			$oauth_args         = $this->request_args( $request_args );

			$args = in_array( $this->get_method(), array( 'GET', 'HEAD', 'DELETE' ), true )
				? array(
					'headers' => array(
						'Authorization' => 'OAuth '. $this->authorize_header_string( $oauth_args ),
					)
				)
				: array( 'method' => $this->get_method(), 'body' => $oauth_args );

			$this->response = wp_remote_request( $this->endpoint_url, $args );
			$body           = wp_remote_retrieve_body( $this->response );

			return $this->get_json_if_json( $body );
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
		 * Gets the request URL from the JSON description object
		 *
		 * @since  0.1.0
		 *
		 * @return mixed Request URL or error
		 */
		function request_token_url() {
			return $this->retrieve_and_set_var_from_description( 'request_token_url', 'request' );
		}

		/**
		 * Gets the authorization URL from the JSON description object
		 *
		 * @since  0.1.0
		 *
		 * @return mixed Authorization URL or error
		 */
		function request_authorize_url() {
			return $this->retrieve_and_set_var_from_description( 'request_authorize_url', 'authorize' );
		}

		/**
		 * Gets the access URL from the JSON description object
		 *
		 * @since  0.1.0
		 *
		 * @return mixed Access URL or error
		 */
		function request_access_url() {
			return $this->retrieve_and_set_var_from_description( 'request_access_url', 'access' );
		}

		/**
		 * Retrieves the OAuth authentication object from the JSON description object
		 *
		 * @since  0.1.0
		 *
		 * @return mixed Authentication object
		 */
		function auth_object() {
			return $this->retrieve_and_set_var_from_description( 'auth_object' );
		}

		/**
		 * Builds request's 'OAuth' authentication arguments
		 *
		 * @since  0.1.0
		 *
		 * @param  array $args Optional additional arguments
		 *
		 * @return array       Request arguments array
		 */
		function request_args( $header_args = array() ) {
			// Set our oauth data
			$this->request_args = wp_parse_args( $header_args, array(
				'oauth_consumer_key'     => $this->args['consumer_key'],
				'oauth_signature_method' => 'HMAC-SHA1',
				'oauth_timestamp'        => time(),
				'oauth_version'          => $this->auth_object()->version,
			) );

			require_once( ABSPATH . WPINC . '/pluggable.php' );

			// create our nonce
			$this->request_args['oauth_nonce'] = wp_create_nonce( md5( serialize( array_merge( array( 'method' => $this->get_method() ), $this->request_args ) ) ) );

			// create our unique oauth signature
			$this->request_args['oauth_signature'] = $this->oauth_signature( $this->request_args );

			return $this->request_args;
		}

		/**
		 * Creates an oauth signature for the api call.
		 *
		 * @since  0.1.0
		 *
		 * @return string|WP_Error Unique Oauth signature or error
		 */
		function oauth_signature( $args ) {
			if ( isset( $args['oauth_signature'] ) ) {
				unset( $args['oauth_signature'] );
			}

			$args = $this->normalize_and_sort( $args, __( 'Signature', 'WDS_WP_JSON_API_Connect' ) );

			if ( is_wp_error( $args ) ) {
				return $args;
			}

			$query_string = $this->create_signature_string( $args );

			$string_to_sign = $this->get_method() . '&' . rawurlencode( $this->endpoint_url ) . '&' . $query_string;

			$this->args['oauth_token_secret'] = array_key_exists( 'oauth_token_secret', $args )
				? $args['oauth_token_secret']
				: $this->args['oauth_token_secret'];

			$composite_key = rawurlencode( $this->args['consumer_secret'] ) .'&'. rawurlencode( $this->args['oauth_token_secret'] );

			return base64_encode( hash_hmac( 'sha1', $string_to_sign, $composite_key, true ) );
		}

		/**
		 * Creates a signature string from all query parameters
		 *
		 * @since  0.1.1
		 * @param  array  $params Array of query parameters
		 * @return string         Signature string
		 */
		public function create_signature_string( $params ) {
			return implode( '%26', $this->join_with_equals_sign( $params ) ); // join with ampersand
		}

		/**
		 * Normalize array keys and values and then sort array
		 *
		 * @since  0.1.2
		 *
		 * @param  array  $args  Array to be normalized and sorted
		 * @param  string $label "Invalid X" Label for WP_Error message.
		 *
		 * @return array|WP_Error Modified array or error if failed to sort.
		 */
		public function normalize_and_sort( $args, $label ) {
			// normalize parameter key/values
			array_walk_recursive( $args, array( $this, 'normalize_parameters' ) );

			// sort parameters
			if ( ! uksort( $args, 'strcmp' ) ) {
				return new WP_Error( 'json_oauth1_failed_parameter_sort', sprintf( __( 'Invalid %s - failed to sort parameters', 'WDS_WP_JSON_API_Connect' ), $label ), array( 'status' => 401 ) );
			}

			return $args;
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
		 * Creates an array of urlencoded strings out of each array key/value pairs
		 *
		 * @since  0.1.1
		 * @param  array  $params       Array of parameters to convert.
		 * @param  array  $query_params Array to extend.
		 * @param  string $key          Optional Array key to append
		 * @return string               Array of urlencoded strings
		 */
		public function join_with_equals_sign( $params, $query_params = array(), $key = '' ) {
			foreach ( $params as $param_key => $param_value ) {
				if ( is_array( $param_value ) ) {
					$query_params = $this->join_with_equals_sign( $param_value, $query_params, $param_key );
				} else {
					if ( $key ) {
						$param_key = $key . '[' . $param_key . ']'; // Handle multi-dimensional array
					}
					$string = $param_key . '=' . $param_value; // join with equals sign
					$query_params[] = urlencode( $string );
				}
			}
			return $query_params;
		}

		/**
		 * Creates a string out of the header arguments array
		 * @since  0.1.0
		 * @param  array  $params  Header arguments array
		 * @return string|WP_Error Header arguments array in string format or error
		 */
		protected function authorize_header_string( $oauth ) {
			$header = '';

			$oauth = $this->normalize_and_sort( $oauth, __( 'Header String', 'WDS_WP_JSON_API_Connect' ) );

			if ( is_wp_error( $oauth ) ) {
				return $oauth;
			}

			$header .= implode( ', ', $this->join_with_equals_sign( $oauth ) );

			return $header;
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
				$desc = $this->get_api_description();
				if ( is_wp_error( $desc ) ) {
					return $desc;
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
		 * Retrieves the Description object
		 *
		 * @since  0.1.0
		 *
		 * @return object  Description object for json_url
		 */
		public function get_api_description() {
			if ( ! $this->json_desc ) {
				if ( ! $this->cache_api_description_for_json_url() ) {
					return $this->connection_failed_msg();
				}
			}
			return $this->json_desc;
		}

		/**
		 * Fetches and caches the Description object
		 *
		 * @since  0.1.0
		 *
		 * @return mixed  Description object for json_url
		 */
		public function cache_api_description_for_json_url() {
			$transient_id = 'apiconnect_desc_'. $this->key;

			if ( $this->json_desc = get_transient( $transient_id ) ) {
				return $this->json_desc;
			}

			$this->response = wp_remote_get( $this->args['json_url'] );
			$body = wp_remote_retrieve_body( $this->response );

			if ( ! $body || ( isset( $this->response['response']['code'] ) && 200 != $this->response['response']['code'] ) ) {
				$error_message = sprintf( __( 'Could not retrive body from URL: "%s"', 'WDS_WP_JSON_API_Connect' ), $this->args['json_url'] );

				delete_option( 'wp_json_api_connect_error' );
				add_option( 'wp_json_api_connect_error', array(
					'message'          => $error_message,
					'request_args'     => print_r( $this->args, true ),
					'request_response' => print_r( $this->response, true ),
				), '', 'no' );

				if ( defined( 'WP_DEBUG' ) ) {
					error_log( 'error: '. $error_message );
					error_log( 'request args: '. print_r( $this->args, true ) );
					error_log( 'request response: '. print_r( $this->response, true ) );
					// throw new Exception( $error_message );
				}
			}

			$this->json_desc = $this->is_json( $body );

			if ( $this->json_desc ) {
				set_transient( $transient_id, $this->json_desc, HOUR_IN_SECONDS );
			}

			return $this->json_desc;
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
		 * Retrieve required token for authorization url
		 *
		 * @since  0.1.0
		 *
		 * @return mixed Array of token data or error
		 */
		public function get_token() {
			if ( $this->token_response ) {
				return $this->token_response;
			}

			$this->set_method( 'POST' );

			if ( ! ( $this->endpoint_url = $this->request_token_url() ) ) {
				return new WP_Error( 'wp_json_api_request_token_error', __( 'Could not retrieve request token url from api description.', 'WDS_WP_JSON_API_Connect' ) );
			}

			if ( is_wp_error( $this->endpoint_url ) ) {
				return $this->endpoint_url;
			}

			$args     = array( 'body' => $this->request_args() );
			$this->response = wp_remote_post( esc_url( $this->endpoint_url ), $args );

			if ( is_wp_error( $this->response ) ) {
				return $this->response;
			}

			if ( ! isset( $this->response['response']['code'] ) || 200 != $this->response['response']['code'] ) {
				return new WP_Error( 'wp_json_api_request_token_error', sprintf( __( 'There was an error retrieving the token from %s.', 'WDS_WP_JSON_API_Connect' ), $this->endpoint_url ), array( 'response' => $this->response, 'request_args' => $args ) );
			}

			$body = wp_remote_retrieve_body( $this->response );
			if ( ! $body ) {
				return new WP_Error( 'wp_json_api_request_token_error', sprintf( __( 'Could not retrive body from %s.', 'WDS_WP_JSON_API_Connect' ), $this->endpoint_url ) );
			}

			$this->token_response = $this->get_json_if_json( $body );

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
			$this->set_method( 'POST' );

			if ( ! ( $this->endpoint_url = $this->request_access_url() ) ) {
				return false;
			}

			$args           = $this->request_args( $args );
			$this->response = wp_remote_post( esc_url( $this->endpoint_url ), array( 'body' => $args ) );
			$body           = wp_remote_retrieve_body( $this->response );

			if ( ! isset( $this->response['response']['code'] ) || 200 != $this->response['response']['code'] ) {
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
				'oauth_token' => '',
				'oauth_token_secret' => '',
				'oauth_verifier' => '',
			) );

			if ( ! empty( $tokens ) ) {
				$this->update_option( 'tokens', $tokens );
			}

			return $tokens;
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
		 * Get current request method
		 *
		 * @since  0.1.1
		 *
		 * @return string Request method
		 */
		public function get_method() {
			return $this->method ? $this->method : 'GET';
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
		 * Handles outputting a WP_Error for when the OAuth plugin is not active on the client site
		 *
		 * @since  0.1.0
		 *
		 * @return WP_Error object
		 */
		public function oauth_not_enabled_msg() {
			return new WP_Error( 'wp_json_api_oauth_not_enabled_error', __( "Could not locate OAuth information; are you sure it's enabled?", 'WDS_WP_JSON_API_Connect' ) );
		}

		/**
		 * Handles outputting a WP_Error for when their is a connection issue
		 *
		 * @since  0.1.0
		 *
		 * @return WP_Error object
		 */
		public function connection_failed_msg() {
			return new WP_Error( 'wp_json_api_connection_failed_error', __( 'There was a problem connecting to the API URL specified.', 'WDS_WP_JSON_API_Connect' ) );
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
				case 'json_desc':
				case 'args':
				case 'option_key':
				case 'response':
				case 'method':
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

endif;
