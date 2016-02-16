<?php
namespace WDS_WP_REST_API\Discover;

/**
 * Site data from an API index.
 */
class Site {
	/**
	 * Data from the API index.
	 *
	 * @var stdClass
	 */
	protected $data;

	/**
	 * API index URL.
	 *
	 * @var string
	 */
	protected $index;

	/**
	 * Constructor.
	 *
	 * @param stdClass $data Data from the API index.
	 * @param string $index API index URL.
	 */
	public function __construct( $data, $index ) {
		$this->data = $data;
		$this->index = $index;
	}

	/**
	 * Get the name of a site.
	 *
	 * @return string
	 */
	public function getName() {
		return $this->data->name;
	}

	/**
	 * Get the description for a site.
	 *
	 * @return string
	 */
	public function getDescription() {
		return $this->data->description;
	}

	/**
	 * Get the URL for a site.
	 *
	 * @return string
	 */
	public function getURL() {
		return $this->data->url;
	}

	/**
	 * Get the index URL for the API.
	 *
	 * @return string
	 */
	public function getIndexURL() {
		return $this->index;
	}

	/**
	 * Get namespaces supported by the site.
	 *
	 * @return string[] List of namespaces supported by the site.
	 */
	public function getSupportedNamespaces() {
		if ( empty( $this->data->namespaces ) || ! is_array( $this->data->namespaces ) ) {
			return array();
		}

		return $this->data->namespaces;
	}

	/**
	 * Does the site support a namespace?
	 *
	 * @param string $namespace Namespace to check.
	 * @return bool True if supported by the site, false otherwise.
	 */
	public function supportsNamespace( $namespace ) {
		return in_array( $namespace, $this->getSupportedNamespaces() );
	}

	/**
	 * Get features supported by the site.
	 *
	 * @return array Map of authentication method => method-specific data.
	 */
	public function getSupportedAuthentication() {
		if ( empty( $this->data->authentication ) || empty( $this->data->authentication ) ) {
			return array();
		}

		return (array) $this->data->authentication;
	}

	/**
	 * Does the site support an authentication method?
	 *
	 * @param string $method Authentication method to check.
	 * @return bool True if supported by the site, false otherwise.
	 */
	public function supportsAuthentication( $method ) {
		return array_key_exists( $method, $this->getSupportedAuthentication() );
	}

	/**
	 * Get method-specific data for the given authentication method.
	 *
	 * @param string $method Authentication method to get data for.
	 * @return mixed Method-specific data if available, null if not supported.
	 */
	public function getAuthenticationData( $method ) {
		if ( ! $this->supportsAuthentication( $method ) ) {
			return null;
		}

		$authentication = $this->getSupportedAuthentication();
		return $authentication[ $method ];
	}
}
