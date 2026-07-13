<?php

namespace bitbytebit\matomoproxy\services;

use bitbytebit\matomoproxy\models\Settings;
use bitbytebit\matomoproxy\Plugin;
use Craft;
use craft\web\Request;
use craft\web\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use yii\base\Component;

/**
 * Ports the tracking/serving logic of the official matomo-org/tracker-proxy
 * `proxy.php` into Craft-native request/response handling.
 *
 * @see https://github.com/matomo-org/tracker-proxy
 */
class Proxy extends Component
{
    /** Response headers copied through from Matomo's response, if present. */
    private const FORWARDED_RESPONSE_HEADERS = [
        'content-type',
        'access-control-allow-origin',
        'access-control-allow-methods',
        'set-cookie',
    ];

    /** Tracking parameters Matomo only honors for an authenticated request. */
    private const AUTH_PROTECTED_PARAMS = ['cdt', 'cdo', 'country', 'region', 'city', 'lat', 'long', 'cip'];

    public function serveTrackerJs(Request $request): Response
    {
        $settings = $this->settings();
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()->set('Vary', 'Accept-Encoding');

        $modifiedSince = $this->parseIfModifiedSince($request);
        $lastModifiedCutoff = time() - 86400;

        if ($modifiedSince !== null && $modifiedSince > $lastModifiedCutoff) {
            $response->setStatusCode(304);
            return $response;
        }

        $response->getHeaders()->set('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT');
        $response->getHeaders()->set('Content-Type', 'application/javascript; charset=UTF-8');

        try {
            $matomoResponse = $this->httpClient($settings)->get(
                $this->matomoBaseUrl($settings) . 'matomo.js',
                $this->commonRequestOptions($request, $settings)
            );
            $content = (string) $matomoResponse->getBody();
        } catch (GuzzleException) {
            $content = '/* there was an error loading matomo.js */';
        }

        $response->content = $content;

        return $response;
    }

    public function forwardTrackingHit(Request $request): Response
    {
        $settings = $this->settings();
        $tokenAuth = (string) $settings->getTokenAuth();
        $rawBody = $request->getRawBody();
        $queryParams = $request->getQueryParams();
        $bodyParams = $request->getBodyParams();

        $extraQueryParams = [];
        $forwardBody = $rawBody;

        if ($settings->httpIpForwardHeader === '') {
            $isBulk = $rawBody !== '' && (str_contains($rawBody, '"requests"') || str_contains($rawBody, "'requests'"));

            if ($isBulk) {
                $clientUrlToken = (isset($queryParams['token_auth']) && is_string($queryParams['token_auth']) && $queryParams['token_auth'] !== '')
                    ? $queryParams['token_auth']
                    : null;
                $forwardBody = $this->injectVisitIpIntoBulkRequest($rawBody, $this->getVisitIp($request), $tokenAuth, $clientUrlToken);
                unset($queryParams['token_auth']);
            } else {
                if (!isset($queryParams['cip']) && !isset($bodyParams['cip'])) {
                    $extraQueryParams['cip'] = $this->getVisitIp($request);
                }
                if (!$this->clientProvidesAuthParams($queryParams) && !$this->clientProvidesAuthParams($bodyParams)) {
                    unset($queryParams['token_auth']);
                    $extraQueryParams['token_auth'] = $tokenAuth;
                }
                if (!empty($bodyParams)) {
                    $forwardBody = http_build_query($bodyParams);
                }
            }
        }

        $url = $this->matomoBaseUrl($settings) . 'matomo.php?' . http_build_query(array_merge($extraQueryParams, $queryParams));

        $options = $this->commonRequestOptions($request, $settings);
        $isPost = $request->getIsPost();

        if ($isPost) {
            $options['body'] = $forwardBody;
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;

        try {
            $matomoResponse = $this->httpClient($settings)->request($isPost ? 'POST' : 'GET', $url, $options);
            $content = (string) $matomoResponse->getBody();
            $response->setStatusCode($matomoResponse->getStatusCode());
            $this->forwardResponseHeaders($response, $matomoResponse, $request);
        } catch (GuzzleException) {
            $content = '';
            $response->setStatusCode(502);
        }

        $response->content = $this->sanitizeContent($content, $this->matomoBaseUrl($settings), $this->proxyBaseUrl($request), $tokenAuth);

        return $response;
    }

    public function forwardHeatmapSessionRecordingConfig(Request $request): Response
    {
        $settings = $this->settings();
        $url = $this->matomoBaseUrl($settings) . 'plugins/HeatmapSessionRecording/configs.php?' . http_build_query($request->getQueryParams());

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;

        try {
            $matomoResponse = $this->httpClient($settings)->get($url, $this->commonRequestOptions($request, $settings));
            $content = (string) $matomoResponse->getBody();
            $response->setStatusCode($matomoResponse->getStatusCode());
            $this->forwardResponseHeaders($response, $matomoResponse, $request);
        } catch (GuzzleException) {
            $content = '';
            $response->setStatusCode(502);
        }

        $response->content = $this->sanitizeContent($content, $this->matomoBaseUrl($settings), $this->proxyBaseUrl($request), (string) $settings->getTokenAuth());

        return $response;
    }

    // -----------------------------------------------------------------
    // Pure logic, ported from proxy.php — framework-agnostic, unit-tested
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     */
    public function clientProvidesAuthParams(array $params): bool
    {
        if (isset($params['token_auth']) && is_string($params['token_auth']) && $params['token_auth'] !== '') {
            return true;
        }

        foreach (self::AUTH_PROTECTED_PARAMS as $param) {
            if (array_key_exists($param, $params)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function withProxyTracking(array $params, string $visitIp, string $tokenAuth, bool $includeProxyToken): array
    {
        $params['cip'] = $visitIp;

        if ($includeProxyToken) {
            $params['token_auth'] = $tokenAuth;
        }

        return $params;
    }

    /**
     * @param array<string, mixed>|string $entry
     */
    public function bulkEntryProvidesAuthParams(array|string $entry): bool
    {
        if (is_string($entry)) {
            $parsedUrl = @parse_url($entry);
            if (empty($parsedUrl['query'])) {
                return false;
            }

            $params = [];
            @parse_str($parsedUrl['query'], $params);

            return $this->clientProvidesAuthParams($params);
        }

        return $this->clientProvidesAuthParams($entry);
    }

    /**
     * @param array<string, mixed>|string $entry
     * @return array<string, mixed>|string
     */
    public function rewriteBulkEntry(array|string $entry, string $visitIp, string $tokenAuth, bool $includeProxyToken): array|string
    {
        if (is_string($entry)) {
            $parsedUrl = @parse_url($entry);
            if (empty($parsedUrl['query'])) {
                return $entry;
            }

            $params = [];
            @parse_str($parsedUrl['query'], $params);

            if ($this->clientProvidesAuthParams($params)) {
                return $entry;
            }

            return '?' . http_build_query($this->withProxyTracking($params, $visitIp, $tokenAuth, $includeProxyToken));
        }

        if ($this->clientProvidesAuthParams($entry)) {
            return $entry;
        }

        return $this->withProxyTracking($entry, $visitIp, $tokenAuth, $includeProxyToken);
    }

    public function injectVisitIpIntoBulkRequest(string $rawPostBody, string $visitIp, string $tokenAuth, ?string $clientUrlToken = null): string
    {
        $data = json_decode(str_replace(["\n", "\r"], '', trim($rawPostBody)), true);

        if (!is_array($data) || !isset($data['requests']) || !is_array($data['requests'])) {
            return $rawPostBody;
        }

        $clientHasBodyToken = isset($data['token_auth']) && is_string($data['token_auth']) && $data['token_auth'] !== '';
        $clientHasUrlToken = $clientUrlToken !== null;

        if ($clientHasUrlToken && !$clientHasBodyToken) {
            $data['token_auth'] = $clientUrlToken;
            $clientHasBodyToken = true;
        }

        $clientAuthenticates = $clientHasUrlToken || $clientHasBodyToken;

        $anyOffendingEntry = false;
        foreach ($data['requests'] as $entry) {
            if (is_array($entry) || is_string($entry)) {
                if ($this->bulkEntryProvidesAuthParams($entry)) {
                    $anyOffendingEntry = true;
                    break;
                }
            }
        }

        $useTopLevelProxyToken = !$clientAuthenticates && !$anyOffendingEntry;

        if ($useTopLevelProxyToken) {
            $data['token_auth'] = $tokenAuth;
        }

        $includeProxyTokenPerEntry = !$clientAuthenticates && !$useTopLevelProxyToken;

        foreach ($data['requests'] as $index => $entry) {
            if (is_array($entry) || is_string($entry)) {
                $data['requests'][$index] = $this->rewriteBulkEntry($entry, $visitIp, $tokenAuth, $includeProxyTokenPerEntry);
            }
        }

        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? $rawPostBody : $encoded;
    }

    public function sanitizeContent(string $content, string $matomoUrl, string $proxyUrl, string $tokenAuth): string
    {
        if ($tokenAuth !== '') {
            $tokenForms = array_unique([$tokenAuth, rawurlencode($tokenAuth), urlencode($tokenAuth)]);
            foreach ($tokenForms as $tokenForm) {
                $content = str_replace($tokenForm, '<token>', $content);
            }
        }

        $matomoHost = parse_url($matomoUrl, PHP_URL_HOST);
        $proxyHost = parse_url($proxyUrl, PHP_URL_HOST);

        $content = str_replace($matomoUrl, $proxyUrl, $content);

        if ($matomoHost !== null && $proxyHost !== null) {
            $content = str_replace($matomoHost, $proxyHost, $content);
        }

        return $content;
    }

    /**
     * @param string[] $allowlist
     */
    public function cookieNameIsAllowed(string $name, array $allowlist): bool
    {
        foreach ($allowlist as $entry) {
            $entry = trim($entry);
            if ($entry === '' || $entry === '*') {
                continue;
            }

            if (str_ends_with($entry, '*')) {
                $prefix = substr($entry, 0, -1);
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            } elseif ($name === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $allowlist
     */
    public function filterCookieHeader(string $cookieHeaderValue, array $allowlist): string
    {
        $keptPairs = [];

        foreach (explode(';', $cookieHeaderValue) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $parts = explode('=', $segment, 2);
            $name = trim($parts[0]);
            $value = isset($parts[1]) ? trim($parts[1]) : '';

            if ($this->cookieNameIsAllowed($name, $allowlist)) {
                $keptPairs[] = "$name=$value";
            }
        }

        return implode('; ', $keptPairs);
    }

    public function getVisitIp(Request $request): string
    {
        $headers = $request->getHeaders();

        foreach (['X-Forwarded-For', 'Client-IP', 'CF-Connecting-IP'] as $headerName) {
            $value = $headers->get($headerName);
            if ($value !== null && filter_var($value, FILTER_VALIDATE_IP) !== false) {
                return $value;
            }
        }

        return (string) ($request->getRemoteIP() ?? '');
    }

    // -----------------------------------------------------------------
    // HTTP plumbing
    // -----------------------------------------------------------------

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    private function httpClient(Settings $settings): Client
    {
        return Craft::createGuzzleClient([
            'timeout' => $settings->timeout,
            'connect_timeout' => $settings->timeout,
            'http_errors' => false,
        ]);
    }

    private function matomoBaseUrl(Settings $settings): string
    {
        return rtrim((string) $settings->getMatomoUrl(), '/') . '/';
    }

    private function proxyBaseUrl(Request $request): string
    {
        return rtrim($request->getHostInfo(), '/') . '/';
    }

    /**
     * @return array<string, mixed>
     */
    private function commonRequestOptions(Request $request, Settings $settings): array
    {
        $headers = [];

        $acceptLanguage = (string) $request->getHeaders()->get('Accept-Language', '');
        $headers['Accept-Language'] = str_replace(["\n", "\t", "\r"], '', $acceptLanguage);

        if ($request->getHeaders()->get('X-Do-Not-Track') === '1') {
            $headers['X-Do-Not-Track'] = '1';
        }

        $dnt = $request->getHeaders()->get('DNT');
        if ($dnt !== null && str_starts_with($dnt, '1')) {
            $headers['DNT'] = '1';
        }

        $cookieHeader = (string) $request->getHeaders()->get('Cookie', '');
        if ($cookieHeader !== '') {
            $cookieHeader = $this->filterCookieHeader($cookieHeader, $settings->getCookieAllowlist());
            if ($cookieHeader !== '') {
                $headers['Cookie'] = $cookieHeader;
            }
        }

        if ($settings->httpIpForwardHeader !== '') {
            $headers[$settings->httpIpForwardHeader] = $this->getVisitIp($request);
        }

        return [
            'headers' => $headers,
            'timeout' => $settings->timeout,
            'connect_timeout' => $settings->timeout,
            'http_errors' => false,
        ];
    }

    private function forwardResponseHeaders(Response $response, ResponseInterface $matomoResponse, Request $request): void
    {
        foreach (self::FORWARDED_RESPONSE_HEADERS as $name) {
            foreach ($matomoResponse->getHeader($name) as $value) {
                if (strtolower($name) === 'set-cookie' && !$request->getIsSecureConnection()) {
                    $value = str_ireplace('secure;', '', $value);
                }

                $response->getHeaders()->add($name, $value);
            }
        }
    }

    private function parseIfModifiedSince(Request $request): ?int
    {
        $header = $request->getHeaders()->get('If-Modified-Since');
        if ($header === null) {
            return null;
        }

        $semicolon = strpos($header, ';');
        if ($semicolon !== false) {
            $header = substr($header, 0, $semicolon);
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : $timestamp;
    }
}
