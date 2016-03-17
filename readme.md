WDS WP REST API Connect (0.2.5) [![Scrutinizer Code Quality](http://img.shields.io/scrutinizer/g/WebDevStudios/WDS-WP-JSON-API-Connect.svg?style=flat)](https://scrutinizer-ci.com/g/WebDevStudios/WDS-WP-JSON-API-Connect/)
=========

A tool for connecting to the [REST API for WordPress](https://github.com/WP-API/WP-API) via [OAuth 1.0a](https://github.com/WP-API/OAuth1).

To get started, you'll need to install both the [WP REST API plugin](https://github.com/WP-API/WP-API) and the [OAuth plugin](https://github.com/WP-API/OAuth1).

To use this library, you will need to run `composer install` from the root of the library, and then include the main library file, `wds-wp-rest-api-connect.php` and the composer autoloader, `vendor/autoload.php` from your plugin/theme.

Once installed and activated, you'll need to create a '[Client Application](http://v2.wp-api.org/guide/authentication/#oauth-authentication)'.
When you have the Client key and secret, you'll create a new `WDS_WP_REST_API\OAuth1\Connect` object by passing those credentials along with the REST API URL and the registered callback URL:
```php
// Make sure you run `composer install`!
require_once 'vendor/autoload.php';

// include the library.
require_once( 'wds-wp-rest-api-connect.php' );

// Get the connect object
$api_connect = new WDS_WP_REST_API\OAuth1\Connect();

// Client credentials
$client = array(

	// Library will 'discover' the API url
	'api_url' => 'WP SITE URL TO CONNECT TO',

	// App credentials set up on the server
	'client_key' => 'YOUR CLIENT KEY',
	'client_secret' => 'YOUR CLIENT SECRET',

	// Must match stored callback URL setup on server.
	'callback_uri' => admin_url() . '?api-connect',
);

/*
 * Initate the API connection.
 *
 * if the oauth connection is not yet authorized, (and autoredirect_authoriziation
 * is not explicitly set to false) you will be auto-redirected to the other site to
 * receive authorization.
 */
$discovery = $api_connect->init( $client );

```

You can then use this object to retrieve the authentication request URL, or if you have been authenticated, make requests. To see a full example, view [the included example.php file](https://github.com/WebDevStudios/WDS-WP-REST-API-Connect/blob/master/example.php).

## Changelog

### 0.2.5
* Fix a typo from a variable which should be using an object property (for legacy mode).

### 0.2.4
* Fix broken logic in `Connect::auth_request()` where $response variable might not get properly set.

### 0.2.3
* Update example.php
* Make requests more consistent, and pass parameters through appropriate filters.
* Fixed a few missed exception handlers.

### 0.2.2
* Add set_headers method to be able to set headers for discovery.
* Use our own API Discovery library to use the WP http API, and to correctly pass any headers if they exist.

### 0.2.1
* Bug fix: Fix the order of checks in the reset_connection method to ensure the delete_stored_error method is always called.

### 0.2.0
* Complete rewrite. Breaks backwards-compatibility, but previous version will not work with the newest version of the [WordPress OAuth](https://github.com/WP-API/OAuth1) plugin. Please review the [WP-API Authentication documentation](http://v2.wp-api.org/guide/authentication/#oauth-authentication).

### 0.1.5
* Remove autoload from composer.json (for now).

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
