<?php

class WP_JSON_API_Connect {

	protected $json        = false;
	protected $args        = array();
	protected $auth_object = false;

	public function __construct( $args = array() ) {
		$this->args = wp_parse_args( $args, array(
			'client_key'    => '',
			'client_secret' => '',
			'json_url'      => '',
		) );

		$this->args['client_token_secret'] = '';
	}

	public function request_token() {
		if ( ! ( $request_token_url = $this->request_token_url() ) ) {
			return false;
		}

		$response = wp_remote_post( esc_url( $request_token_url ), $this->header_args_ouath() );

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			return new WP_Error( 'wp_json_api_request_token_error', sprintf( __( "Could not retrive body from %s.", 'WP_JSON_API_Connect' ), $request_token_url ) );
		}

		return $body && ( $json = $this->is_json( $body ) ) ? $json : $body;
	}

	/**
	 * Builds request's 'OAuth' authentication arguments
	 * @since  1.0.0
	 * @param  array $args Optional additional arguments
	 * @return array       Request arguments array
	 */
	protected function header_args_ouath( $header_args = array() ) {

		// Set our oauth data
		$oauth = wp_parse_args( $header_args, array(
			'oauth_consumer_key'     => $this->args['client_key'],
			'oauth_nonce'            => time(),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => time(),
			'oauth_version'          => $this->auth_object()->version,
		) );

		// create our unique oauth signature
		$oauth['oauth_signature'] = $this->oauth_signature( $oauth );

		return array( 'body' => $oauth, );
	}

	/**
	 * Creates an oauth signature for the api call.
	 * @since  1.0.0
	 * @param  array  $params Header arguments array
	 * @return string         Unique Oauth signature
	 */
	protected function oauth_signature( $params ) {

		$concatenated_params = $this->sort_and_concatenate_array( $params );

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

	function request_token_url() {
		return $this->set_and_retrieve_var( 'request_token_url', 'request' );
	}

	function auth_object() {
		return $this->set_and_retrieve_var( 'auth_object' );
	}

	public function set_and_retrieve_var( $var, $route = false ) {
		if ( isset( $this->{$var} ) && $this->{$var} ) {
			return $this->{$var};
		}
		if ( ! $this->json ) {
			$this->cache_api_description_for_json_url();
		}
		if ( empty( $this->json->authentication ) || empty( $this->json->authentication->oauth1 ) ) {
			throw new Exception( "Could not locate OAuth information; are you sure it's enabled?" );
		}

		if ( $route && empty( $this->json->authentication->oauth1->{$route} ) ) {
			throw new Exception( "Could not locate OAuth information; are you sure it's enabled?" );
		}

		$this->{$var} = $route ? $this->json->authentication->oauth1->{$route} : $this->json->authentication->oauth1;

		return $this->{$var};
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
