<?php
/**
 * Based on League\OAuth1\Client\Signature\HmacSha1Signature from the
 * OAuth 1.0a client library by the League of Extraordinary Packages.
 * https://github.com/thephpleague/oauth1-client/blob/master/src/Client/Signature/HmacSha1Signature.php
 *
 * We are using our own version to replace the baseString
 * method and account for multi-dimensional arrays. See:
 * https://github.com/thephpleague/oauth1-client/pull/61
 *
 * If the above pull request is accepted, we will not need this class.
 */

namespace WDS_WP_REST_API\OAuth1;

use League\OAuth1\Client\Signature;
use League\OAuth1\Client\Signature\SignatureInterface;
use League\OAuth1\Client\Signature\HmacSha1Signature;
use Guzzle\Http\Url;

class WPSignature extends HmacSha1Signature implements SignatureInterface {

    /**
     * Generate a base string for a HMAC-SHA1 signature
     * based on the given a url, method, and any parameters.
     *
     * @param Url    $url
     * @param string $method
     * @param array  $parameters
     *
     * @return string
     */
    protected function baseString(Url $url, $method = 'POST', array $parameters = array())
    {
        $baseString = rawurlencode($method).'&';

        $schemeHostPath = Url::buildUrl(array(
           'scheme' => $url->getScheme(),
           'host' => $url->getHost(),
           'path' => $url->getPath(),
        ));

        $baseString .= rawurlencode($schemeHostPath).'&';

        $data = array();
        parse_str($url->getQuery(), $query);
        $data = array_merge($query, $parameters);

        // normalize data key/values
        array_walk_recursive($data, function (&$key, &$value) {
            $key   = rawurlencode(rawurldecode($key));
            $value = rawurlencode(rawurldecode($value));
        });
        ksort($data);

        $baseString .= $this->queryStringFromData($data);

        return $baseString;
    }

    /**
     * Creates an array of urlencoded strings out of each array key/value pair
     * Handles multi-demensional arrays recursively.
     *
     * @param  array  $data        Array of parameters to convert.
     * @param  array  $queryParams Array to extend. False by default.
     * @param  string $prevKey     Optional Array key to append
     *
     * @return string              urlencoded string version of data
     */
    protected function queryStringFromData($data, $queryParams = false, $prevKey = '')
    {
        if ($initial = (false === $queryParams)) {
            $queryParams = array();
        }

        foreach ($data as $key => $value) {
            /*
             * If https://github.com/WP-API/OAuth1/pull/122/files is accepted,
             * Then this should be used.
             */
            // if ($prevKey) {
            //     $key = $prevKey.'['.$key.']'; // Handle multi-dimensional array
            // }

            if (is_array($value)) {
                $queryParams = $this->queryStringFromData($value, $queryParams, $key);
            } else {
                /*
                 * If https://github.com/WP-API/OAuth1/pull/122/files is accepted,
                 * Then this should be removed.
                 */
                if ($prevKey) {
                    $key = $prevKey.'['.$key.']'; // Handle multi-dimensional array
                }

                $queryParams[] = rawurlencode($key.'='.$value); // join with equals sign
            }
        }

        if ($initial) {
            return implode('%26', $queryParams); // join with ampersand
        }

        return $queryParams;
    }

}
