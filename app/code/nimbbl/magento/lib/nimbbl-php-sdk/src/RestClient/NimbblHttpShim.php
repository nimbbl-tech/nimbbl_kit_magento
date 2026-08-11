<?php

namespace Nimbbl\Api\RestClient;

/**
 * HTTP response value object returned by NimbblHttpShim.
 *
 * Provides the same property interface as the Requests_Response object
 * from rmccue/requests so Request.php can use either without changes:
 *   $response->status_code  (int)
 *   $response->body         (string — raw, undecoded)
 *   $response->headers      (array, lower-case keys)
 */
class NimbblHttpResponse
{
    /** @var int */
    public $status_code;

    /** @var string */
    public $body;

    /** @var array<string,string> Lower-cased header names → values */
    public $headers;

    /**
     * @param int    $statusCode
     * @param string $body
     * @param array  $headers
     */
    public function __construct(int $statusCode, string $body, array $headers = [])
    {
        $this->status_code = $statusCode;
        $this->body        = $body;
        $this->headers     = $headers;
    }
}

/**
 * cURL-based HTTP adapter bundled with the Magento plugin.
 *
 * Replaces the rmccue/requests library dependency so the Nimbbl PHP SDK
 * works without Composer. Only the two static methods actually called by
 * Request.php are implemented:
 *
 *   NimbblHttpShim::request($url, $headers, $body, $method, $options)
 *   NimbblHttpShim::post($url, $headers, $body, $options)
 *
 * The class is aliased as `Requests` inside the bundled Request.php so the
 * rest of the SDK call-sites are completely unchanged.
 */
class NimbblHttpShim
{
    /**
     * Fire an HTTP request via cURL.
     *
     * @param  string          $url
     * @param  array           $headers  Associative: ['Header-Name' => 'value']
     * @param  string|null     $body     Raw request body (JSON string); null for GET/DELETE
     * @param  string          $method   HTTP verb, e.g. 'GET', 'POST'
     * @param  array           $options  Supported key: 'timeout' (int, seconds, default 30)
     * @return NimbblHttpResponse
     */
    public static function request(
        string $url,
        array $headers = [],
        $body = null,
        string $method = 'GET',
        array $options = []
    ): NimbblHttpResponse {
        $timeout = (int) ($options['timeout'] ?? 30);

        // Convert associative headers to curl's "Name: Value" format
        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HEADER         => true,  // include response headers in output
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null && $body !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $raw        = (string) curl_exec($curl);
        $httpCode   = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $errno      = curl_errno($curl);
        $errstr     = curl_error($curl);
        curl_close($curl);

        if ($errno !== 0) {
            throw new \RuntimeException(
                'Nimbbl cURL error (' . $errno . '): ' . $errstr . ' [' . strtoupper($method) . ' ' . $url . ']'
            );
        }

        $rawHeaders   = substr($raw, 0, $headerSize);
        $responseBody = (string) substr($raw, $headerSize);
        $parsedHeaders = self::parseHeaders($rawHeaders);

        return new NimbblHttpResponse($httpCode, $responseBody, $parsedHeaders);
    }

    /**
     * Convenience POST wrapper matching Requests::post() call signature.
     *
     * @param  string $url
     * @param  array  $headers
     * @param  string $body
     * @param  array  $options
     * @return NimbblHttpResponse
     */
    public static function post(
        string $url,
        array $headers = [],
        string $body = '',
        array $options = []
    ): NimbblHttpResponse {
        return self::request($url, $headers, $body, 'POST', $options);
    }

    /**
     * Parse raw HTTP response headers into an associative array.
     * All header names are lower-cased so both 'x-request-id' and
     * 'X-Request-Id' lookups in Request.php resolve without issue.
     *
     * @param  string $rawHeaders
     * @return array<string,string>
     */
    private static function parseHeaders(string $rawHeaders): array
    {
        $parsed = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }
            $name          = strtolower(trim(substr($line, 0, $colonPos)));
            $value         = trim(substr($line, $colonPos + 1));
            $parsed[$name] = $value;
        }
        return $parsed;
    }
}
