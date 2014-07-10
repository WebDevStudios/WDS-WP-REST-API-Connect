<?php

class WP_JSON_API_Connect {

	protected $json           = false;
	protected $args           = array();
	protected $auth_object    = false;
	protected $token_response = false;
	protected $request_args   = false;
	protected $tokens         = array();

	public function __construct( $args = array() ) {
		$this->args = wp_parse_args( $args, array(
			'client_key'    => '',
			'client_secret' => '',
			'json_url'      => '',
		) );

		$this->args['client_token_secret'] = '';

		if ( isset( $_REQUEST['oauth_authorize_url'], $_REQUEST['oauth_token'] ) ) {
			$this->maybe_store_oauth_access_token();
		}
	}

	public function maybe_store_oauth_access_token() {
		$exists = $this->get_url_access_token( $_REQUEST['oauth_authorize_url'] );
		if ( ! $exists ) {
			$this->update_url_access_tokens( $url_hash, $_REQUEST['oauth_token'] );
		}
	}

	public function get_url_access_token( $key = '' ) {
		$key = sanitize_title( $key );
		$tokens = $this->get_url_access_tokens();
		if ( ! empty( $tokens ) ) {
			return $key && array_key_exists( $key, $tokens )
				? $tokens[ $key ]
				: false;
		}
	}

	public function get_url_access_tokens() {
		if ( ! empty( $this->tokens ) ) {
			return $this->tokens;
		}
		$this->tokens = get_option( 'wp_json_api_connect_url_tokens' );
		return ! empty( $this->tokens ) ? $this->tokens : false;
	}

	public function update_url_access_tokens( $key, $token ) {
		$this->tokens[ $key ] = $token;

		if ( $this->get_url_access_tokens() ) {
			update_option( 'wp_json_api_connect_url_tokens', $this->tokens );
		} else {
			add_option( 'wp_json_api_connect_url_tokens', $this->tokens, '', 'no' );
		}
	}

	public function get_authorize_url( $callback_query_args = array() ) {
		if ( ! ( $request_authorize_url = $this->request_authorize_url() ) ) {
			return false;
		}

		$token = $this->get_cached_token();
		$token_array = array();
		parse_str( $token, $token_array );

		$callback_query_args = array_merge( array(
			'oauth_authorize_url' => urlencode( $this->args['json_url'] ),
		), $callback_query_args );

		$query_args = array(
			'oauth_token' => $token_array['oauth_token'],
			'oauth_callback' => urlencode( add_query_arg( $callback_query_args, admin_url() ) ),
		);

		$url = add_query_arg( $query_args, esc_url( $request_authorize_url ) );

		return $url;
	}

	public function get_cached_token() {
		if ( $this->token_response ) {
			return $this->token_response;
		}
		$transient_id = 'wp_json_api_connect_token_response';

		if ( $this->token_response = get_transient( $transient_id ) ) {
			return $this->token_response;
		}

		$this->request_token();

		if ( $this->token_response && ! is_wp_error( $this->token_response ) ) {
			set_transient( $transient_id, $this->token_response, DAY_IN_SECONDS );
		}

		return $this->token_response;
	}

	public function request_token() {
		if ( ! ( $request_token_url = $this->request_token_url() ) ) {
			return false;
		}

		if ( is_wp_error( $request_token_url ) ) {
			return $request_token_url;
		}

		$response = wp_remote_post( esc_url( $request_token_url ), array( 'body' => $this->request_args() ) );

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			return new WP_Error( 'wp_json_api_request_token_error', sprintf( __( "Could not retrive body from %s.", 'WP_JSON_API_Connect' ), $request_token_url ) );
		}

		$this->token_response = $body && ( $json = $this->is_json( $body ) ) ? $json : $body;
		return $this->token_response;
	}

	/**
	 * Builds request's 'OAuth' authentication arguments
	 * @since  1.0.0
	 * @param  array $args Optional additional arguments
	 * @return array       Request arguments array
	 */
	protected function request_args( $header_args = array() ) {
		if ( $this->request_args ) {
			return $this->request_args;
		}
		// Set our oauth data
		$this->request_args = wp_parse_args( $header_args, array(
			'oauth_consumer_key'     => $this->args['client_key'],
			'oauth_nonce'            => time(),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => time(),
			'oauth_version'          => $this->auth_object()->version,
		) );

		// create our unique oauth signature
		$this->request_args['oauth_signature'] = $this->oauth_signature();

		return $this->request_args;
	}

	/**
	 * Creates an oauth signature for the api call.
	 * @since  1.0.0
	 * @return string Unique Oauth signature
	 */
	protected function oauth_signature() {
		if ( isset( $this->request_args['oauth_signature'] ) ) {
			unset( $this->request_args['oauth_signature'] );
		}

		$concatenated_params = $this->sort_and_concatenate_array( $this->request_args );

		$base = 'POST&'. rawurlencode( $this->request_token_url() ) .'&'. rawurlencode( implode( '&', $concatenated_params ) );

		$composite_key = rawurlencode( $this->args['client_secret'] ) .'&'. rawurlencode( $this->args['client_token_secret'] );

		return base64_encode( hash_hmac( 'sha1', $base, $composite_key, true ) );
	}

	function sort_and_concatenate_array( $array ) {
		$concatenated = array();
		ksort( $array );
		foreach( $array as $key => $value ){
			$concatenated[] = $key .'='. $value;
		}
		return $concatenated;
	}

	/**
	 * Creates a string out of the oauth request_args array
	 * @since  1.0.0
	 * @return string Header arguments array in string format
	 */
	function authorize_header() {
		$oauth = $this->request_args();
		$header = '';
		$values = array();
		ksort( $oauth );
		foreach( $oauth as $key => $value ) {
			$values[] = $key .'="'. rawurlencode( $value ) .'"';
		}

		$header .= implode( ', ', $values );

		return $header;
	}

	function request_token_url() {
		return $this->set_and_retrieve_var( 'request_token_url', 'request' );
	}

	function request_authorize_url() {
		return $this->set_and_retrieve_var( 'request_authorize_url', 'authorize' );
	}

	function auth_object() {
		return $this->set_and_retrieve_var( 'auth_object' );
	}

	public function set_and_retrieve_var( $var, $route = false ) {
		if ( isset( $this->{$var} ) && $this->{$var} ) {
			return $this->{$var};
		}
		if ( ! $this->json ) {
			if ( ! $this->cache_api_description_for_json_url() ) {
				return $this->connection_failed_msg();
			}
		}

		if ( empty( $this->json->authentication ) || empty( $this->json->authentication->oauth1 ) ) {
			return $this->oauth_not_enabled_msg();
		}

		if ( $route && empty( $this->json->authentication->oauth1->{$route} ) ) {
			return $this->oauth_not_enabled_msg();
		}

		$this->{$var} = $route ? $this->json->authentication->oauth1->{$route} : $this->json->authentication->oauth1;

		return $this->{$var};
	}

	public function oauth_not_enabled_msg() {
		return new WP_Error( 'wp_json_api_oauth_not_enabled_error', __( "Could not locate OAuth information; are you sure it's enabled?", 'WP_JSON_API_Connect' ) );
	}

	public function connection_failed_msg() {
		return new WP_Error( 'wp_json_api_connection_failed_error', __( "There was a problem connecting to the API URL specified.", 'WP_JSON_API_Connect' ) );
	}

	public function cache_api_description_for_json_url() {
		$transient_id = 'wp_json_api_connect_api_description';

		if ( $this->json = get_transient( $transient_id ) ) {
			return $this->json;
		}

		$response = wp_remote_get( $this->args['json_url'] );
		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			throw new Exception( "Could not retrive body from {$this->args['json_url']}." );
		}

		$this->json = $body && ( $json = $this->is_json( $body ) ) ? $json : false;

		if ( $this->json ) {
			set_transient( $transient_id, $this->json, HOUR_IN_SECONDS );
		}

		return $this->json;
	}

	function is_json($string) {
		return is_string( $string ) && ( $json = json_decode( $string ) ) && ( is_object( $json ) || is_array( $json ) )
			? $json
			: false;
	}

	public function json_url( $path = '' ) {
		return $path ? trailingslashit( $this->args['json_url'] ) . $path : $this->args['json_url'];
	}

	public function __get( $field ) {
		switch( $field ) {
			case 'json':
			case 'args':
				return $this->{$field};
			case 'json_url':
			case 'client_key':
			case 'client_secret':
				return $this->args[ $field ];
			default:
				throw new Exception( 'Invalid property: ' . $field );
		}
	}
}
