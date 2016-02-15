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
use WDS_WP_REST_API\OAuth1\WPSignature;

class WPServer extends Server {
	protected $baseUri;

	protected $authURLs = array();

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
	 * {@inheritDoc}
	 *
	 * @internal The current user endpoint gives a redirection, so we need to
	 *     override the HTTP call to avoid redirections.
	 */
	protected function fetchUserDetails(TokenCredentials $tokenCredentials, $force = true)
	{
	 	if (!$this->cachedUserDetailsResponse || $force) {
	 		$url = $this->urlUserDetails();

	 		$client = $this->createHttpClient();

	 		$headers = $this->getHeaders($tokenCredentials, 'GET', $url);

	 		try {
	 			$response = $client->get($url, $headers, array('allow_redirects' => false))->send();
	 		} catch (BadResponseException $e) {
	 			$response = $e->getResponse();
	 			$body = $response->getBody();
	 			$statusCode = $response->getStatusCode();

	 			throw new \Exception(
	 				"Received error [$body] with status code [$statusCode] when retrieving token credentials."
	 				);
	 		}

	 		switch ($this->responseType) {
	 			case 'json':
	 			$this->cachedUserDetailsResponse = $response->json();
	 			break;

	 			case 'xml':
	 			$this->cachedUserDetailsResponse = $response->xml();
	 			break;

	 			case 'string':
	 			parse_str($response->getBody(), $this->cachedUserDetailsResponse);
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
