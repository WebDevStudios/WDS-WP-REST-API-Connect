<?php
/**
 * Based on the WordPress provider for the OAuth 1.0a client library
 * by the League of Extraordinary Packages.
 * https://github.com/WP-API/example-client/blob/master/lib/WordPress.php
 * https://github.com/WP-API/example-client/issues/6
 *
 * We are using our own SignatureInterface to account for multi-dimensional arrays. See:
 * https://github.com/thephpleague/oauth1-client/pull/61
 *
 * If the above-mentioned WordPress provider is bundled to its own composer package,
 * AND the above pull request is accepted, we will not need these two classes.
 */

namespace WDS_WP_REST_API\OAuth1;

use League\OAuth1\Client\Server\Server;
use League\OAuth1\Client\Server\User;
use League\OAuth1\Client\Credentials\TokenCredentials;
use League\OAuth1\Client\Credentials\TemporaryCredentials;
use WDS_WP_REST_API\OAuth1\WPSignature;

class WPServer extends Server {
	protected $baseUri;

	protected $authURLs = array();

	/**
	 * Guzzle\Http\Message\Response
	 *
	 * @var Guzzle\Http\Message\Response
	 */
	public $response;

	/**
	 * If a request was made, the response code will be stored here.
	 *
	 * @var string
	 */
	public $response_code;

	/**
	 * {@inheritDoc}
	 */
	public function __construct($clientCredentials, SignatureInterface $signature = null)
	{

		// Pass through an array or client credentials, we don't care
		if (is_array($clientCredentials)) {
			$this->parseConfigurationArray($clientCredentials);
			$clientCredentials = $this->createClientCredentials($clientCredentials);
		} elseif (!$clientCredentials instanceof ClientCredentialsInterface) {
			throw new \InvalidArgumentException('Client credentials must be an array or valid object.');
		}

		// Our signature object handles multi-dimensional arrays.
		$signature = $signature ? $signature : new WPSignature( $clientCredentials );
		parent::__construct($clientCredentials, $signature);
	}

	/**
	 * {@inheritDoc}
	 */
	public function urlTemporaryCredentials()
	{
		return $this->authURLs->request;
	}

	/**
	 * {@inheritDoc}
	 */
	public function urlAuthorization()
	{
		return $this->authURLs->authorize;
	}

	/**
	 * {@inheritDoc}
	 */
	public function urlTokenCredentials()
	{
		return $this->authURLs->access;
	}

	/**
	 * {@inheritDoc}
	 */
	public function urlUserDetails()
	{
		return rtrim( $this->baseUri, '/' ) . '/wp/v2/users/me?context=edit';
	}

	/**
	 * Redirect the client to the authorization URL.
	 *
	 * @param TemporaryCredentials|string $temporaryIdentifier
	 */
	public function authorize($temporaryIdentifier)
	{
		$url = $this->getAuthorizationUrl($temporaryIdentifier);

		wp_redirect( $url );
		exit();
	}

	/**
	 * Perform a request (via GuzzleClient)
	 * @todo   convert to WP http API
	 *
	 * @since  0.2.3
	 *
	 * @param  string           $uri   URI to request
	 * @param  TokenCredentials $creds Request method. Defaults to GET
	 * @param  array            $args  Array of data to send in request.
	 *
	 * @return array                   Array of response data, or WP_Error
	 */
	public function request( $uri, TokenCredentials $creds, $args = array() ) {
		$args = wp_parse_args( $args, array(
			'request_args' => array(),
			'options'      => array(),
			'method'       => 'GET',
		) );

		$headers = $this->getHeaders( $creds, $args['method'], $uri, $args['request_args'] );

		$this->get_response( $uri, array(
			'method'       => $args['method'],
			'headers'      => $headers,
			'request_args' => $args['request_args'],
			'options'      => $args['options'],
		) );


		$this->response_code = $this->response->getStatusCode();

		$headers = array();
		foreach ( $this->response->getHeaders() as $header ) {
			$headers[ strtolower( $header->getName() ) ] = (string) $header;
		}

		$request_response = array(
			'headers'  => $headers,
			'body'     => $this->response->getBody( true ),
			'response' => array(
				'code'    => $this->response_code,
				'message' => $this->response->getReasonPhrase(),
			),
		);

		return $request_response;
	}

	/**
	 * Perform a GuzzleClient request, and get the response.
	 *
	 * @since  0.2.3
	 *
	 * @param  string $uri   URI to request
	 * @param  array  $args  Array of data to send in request.
	 *
	 * @return Guzzle\Http\Message\Response
	 */
	public function get_response( $uri, $args ) {

		$args = wp_parse_args( $args, array(
			'method'       => 'GET',
			'headers'      => false,
			'request_args' => array(),
			'options'      => array(),
		) );

		$headers = apply_filters( 'wp_rest_api_request_headers', $args['headers'], $uri );
		$options = apply_filters( 'wp_rest_api_request_options', $args['options'], $uri );
		$request_args = apply_filters( 'wp_rest_api_request_request_args', $args['request_args'], $uri );

		$this->response = $this->createHttpClient()
			->createRequest( $args['method'], $uri, $headers, $request_args, $options )
			->send();

		return $this->response;
	}

	/**
	 * Gets temporary credentials by performing a request to
	 * the server.
	 *
	 * @since  0.2.3
	 *
	 * @return TemporaryCredentials
	 */
	public function getTemporaryCredentials()
	{
		$uri = $this->urlTemporaryCredentials();

		try {
			$this->get_response( $uri, array(
				'method'  => 'POST',
				'headers' => $this->buildHttpClientHeaders( array(
					'Authorization' => $this->temporaryCredentialsProtocolHeader( $uri ),
				) ),
			) );
		} catch ( \Exception $e ) {
			if ( $e instanceof BadResponseException ) {
				return $this->handleTemporaryCredentialsBadResponse( $e );
			} else {
				return $this->handleTemporaryCredentialsFail( $e );
			}
			return $this->handleTemporaryCredentialsBadResponse( $e );
		}

		return $this->createTemporaryCredentials( $this->response->getBody() );
	}

	/**
	 * Handle a failed response coming back when getting temporary credentials.
	 *
	 * @since  0.2.3
	 *
	 * @param Exception $e
	 *
	 * @throws CredentialsException
	 */
	public function handleTemporaryCredentialsFail( $e ) {
		$response = $e->getResponse();
		if ( 500 === $response->getStatusCode() ) {
			$body = __( 'It is possible the Callback URL is invalid. Please check.', 'wds-wp-rest-api-connect' );
			$response->setBody( $body );
		}

		return $this->handleTemporaryCredentialsBadResponse( $e );
	}

	/**
	 * Retrieves token credentials by passing in the temporary credentials,
	 * the temporary credentials identifier as passed back by the server
	 * and finally the verifier code.
	 *
	 * @since  0.2.3
	 *
	 * @param TemporaryCredentials $temporaryCredentials
	 * @param string               $temporaryIdentifier
	 * @param string               $verifier
	 *
	 * @return TokenCredentials
	 */
	public function getTokenCredentials(TemporaryCredentials $temporaryCredentials, $temporaryIdentifier, $verifier)
	{
		if ($temporaryIdentifier !== $temporaryCredentials->getIdentifier()) {
			throw new \InvalidArgumentException(
				'Temporary identifier passed back by server does not match that of stored temporary credentials.
				Potential man-in-the-middle.'
				);
		}

		$uri            = $this->urlTokenCredentials();
		$bodyParameters = array( 'oauth_verifier' => $verifier );
		$headers        = $this->getHeaders( $temporaryCredentials, 'POST', $uri, $bodyParameters );

		try {
			$this->get_response( $uri, array(
				'method'  => 'POST',
				'headers' => $headers,
				'request_args' => $bodyParameters,
			) );
		} catch ( \Exception $e ) {
			return $this->handleTokenCredentialsBadResponse($e);
		}

		return $this->createTokenCredentials( $this->response->getBody() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @internal The current user endpoint gives a redirection, so we need to
	 *     override the HTTP call to avoid redirections.
	 */
	protected function fetchUserDetails(TokenCredentials $tokenCredentials, $force = true)
	{
		if (!$this->cachedUserDetailsResponse || $force) {

			try {
				$this->request( $this->urlUserDetails(), $tokenCredentials, array(
					'options' => array( 'allow_redirects' => false ),
				) );
			} catch (BadResponseException $e) {
				throw new \Exception( $e->getMessage() );
			}

			switch ($this->responseType) {
				case 'json':
					$this->cachedUserDetailsResponse = $this->response->json();
					break;

				case 'xml':
					$this->cachedUserDetailsResponse = $this->response->xml();
					break;

				case 'string':
					parse_str($this->response->getBody(), $this->cachedUserDetailsResponse);
					break;

				default:
					throw new \InvalidArgumentException("Invalid response type [{$this->responseType}].");
			}
		}

		return $this->cachedUserDetailsResponse;
	}

	/**
	 * {@inheritDoc}
	 */
	public function userDetails($data, TokenCredentials $tokenCredentials)
	{
		$user = new User();

		$user->uid = $data['id'];
		$user->nickname = $data['slug'];
		$user->name = $data['name'];
		$user->firstName = $data['first_name'];
		$user->lastName = $data['last_name'];
		$user->email = $data['email'];
		$user->description = $data['description'];
		$user->imageUrl = $data['avatar_urls']['96'];
		$user->urls['permalink'] = $data['link'];
		if ( ! empty( $data['url'] ) ) {
			$user->urls['website'] = $data['url'];
		}

		$used = array('id', 'slug', 'name', 'first_name', 'last_name', 'email', 'avatar_urls', 'link', 'url');

		// Save all extra data
		$user->extra = array_diff_key($data, array_flip($used));

		return $user;
	}

	/**
	 * {@inheritDoc}
	 */
	public function userUid($data, TokenCredentials $tokenCredentials)
	{
		return $data['id'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function userEmail($data, TokenCredentials $tokenCredentials)
	{
		return $data['email'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function userScreenName($data, TokenCredentials $tokenCredentials)
	{
		return $data['slug'];
	}

	/**
	 * Parse configuration array to set attributes.
	 *
	 * @param array $configuration
	 * @throws Exception
	 */
	private function parseConfigurationArray(array $configuration = array())
	{
		if (!isset($configuration['api_root'])) {
			throw new Exception('Missing WordPress API index URL');
		}
		$this->baseUri = $configuration['api_root'];

		if (!isset($configuration['auth_urls'])) {
			throw new Exception('Missing authorization URLs from API index');
		}
		$this->authURLs = $configuration['auth_urls'];
	}
}
