<?php
namespace WDS_WP_REST_API;

use WordPress\Discovery\Site;
use Exception;
use Requests;
use Requests_Exception_HTTP;

class Discover {

	protected $root = '';
	protected $uri = '';
	protected $args = array();
	protected $legacy = false;

	/**
	 * Site object
	 *
	 * @var Site
	 */
	protected $site;

	/**
	 * Discover the WordPress API from a URI.
	 *
	 * @param string $uri URI to start the search from.
	 * @param bool $legacy Should we check for the legacy API too?
	 * @return Site|null Site data if available, null if not a WP site.
	 */
	public function __construct( $uri, $args = array(), $legacy = false ) {
		$this->uri = $uri;
		$this->args = $args;
		$this->legacy = $legacy;

		// Step 1: Find the API itself.
		$this->root = $this->discover_api_root( $uri, $args, $legacy );

		// Step 2: Ask the API for information.
		$this->site = $this->get_index_information();
	}

	/**
	 * Discover the API root from an address.
	 *
	 * @throws \Requests_Exception on HTTP error.
	 *
	 * @return string|null API root URL if found, null if no API is available.
	 */
	protected function discover_api_root() {
		$response = $this->request( $this->uri, 'head' );
		$links    = (array) $response['headers']['link'];

		// Find the correct link by relation
		foreach ( $links as $link ) {
			$attrs = $this->parse_link_header( $link );

			if ( empty( $attrs ) || empty( $attrs['rel'] ) ) {
				continue;
			}
			switch ( $attrs['rel'] ) {
				case 'https://api.w.org/':
					break;

				case 'https://github.com/WP-API/WP-API':
					// Only allow this if legacy mode is on.
					if ( $legacy ) {
						break;
					}

					// Fall-through.
				default:
					continue 2;
			}

			return $attrs['href'];
		}

		return null;
	}

	/**
	 * Parse a Link header into attributes.
	 *
	 * @param string $link Link header from the response.
	 * @return array Map of attribute key => attribute value, with link href in `href` key.
	 */
	protected function parse_link_header( $link ) {
		$parts = explode( ';', $link );
		$attrs = array(
			'href' => trim( array_shift( $parts ), '<>' ),
		);

		foreach ( $parts as $part ) {
			if ( ! strpos( $part, '=' ) ) {
				continue;
			}

			list( $key, $value ) = explode( '=', $part, 2 );
			$key = trim( $key );
			$value = trim( $value, '" ' );
			$attrs[ $key ] = $value;
		}

		return $attrs;
	}

	/**
	 * Get the index information from a site.
	 *
	 * @return Site Data from the index for the site.
	 */
	protected function get_index_information() {
		if ( empty( $this->root ) ) {
			return null;
		}

		$response = $this->request( $this->root );

		$index = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $index ) && json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception( json_last_error_msg(), json_last_error() );
		}

		return new Site( $index, $this->root );
	}

	/**
	 * Make a request using the WP http API
	 *
	 * @since  0.2.2
	 *
	 * @param  string  $uri          URI to make request to
	 * @param  string  $request_type Type of request. Defaults to 'get'
	 *
	 * @return mixed                 Return of the request.
	 */
	public function request( $uri, $request_type = 'get' ) {
		$http = _wp_http_get_object();
		$response = $http->{$request_type}( $uri, $this->args );

		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_code() . ': ' . $response->get_error_message() );
		}

		$code     = $response['response']['code'];
		$success  = $code >= 200 && $code < 300;

		if ( ! $success ) {
			// Use Requests error handling.
			$exception = Requests_Exception_HTTP::get_class( $code );
			throw new $exception( null, $this );
		}

		return $response;
	}

	/**
	 * Get the Site object
	 *
	 * @return Site
	 */
	public function site() {
		return $this->site;
	}

}
