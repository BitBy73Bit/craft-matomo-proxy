<?php

namespace bitbytebit\matomoproxy\tests;

use bitbytebit\matomoproxy\services\Proxy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Proxy::class)]
final class ProxyBulkRequestTest extends TestCase
{
    private Proxy $proxy;
    private const VISIT_IP = '1.2.3.4';
    private const TOKEN_AUTH = 'secret-token';

    protected function setUp(): void
    {
        $this->proxy = new Proxy();
    }

    public function testCleanBatchGetsSingleTopLevelToken(): void
    {
        $rawBody = json_encode([
            'requests' => [
                '?idsite=1&action_name=Home',
                '?idsite=1&action_name=About',
            ],
        ]);

        $result = json_decode($this->proxy->injectVisitIpIntoBulkRequest($rawBody, self::VISIT_IP, self::TOKEN_AUTH), true);

        self::assertSame(self::TOKEN_AUTH, $result['token_auth']);

        foreach ($result['requests'] as $entry) {
            parse_str(ltrim($entry, '?'), $params);
            self::assertSame(self::VISIT_IP, $params['cip']);
            // Entries themselves get no per-entry token when a top-level one covers the whole batch.
            self::assertArrayNotHasKey('token_auth', $params);
        }
    }

    public function testMixedBatchFallsBackToPerEntryTokens(): void
    {
        $rawBody = json_encode([
            'requests' => [
                '?idsite=1&action_name=Home',
                '?idsite=1&action_name=Checkout&token_auth=client-entry-token&cip=9.9.9.9',
            ],
        ]);

        $result = json_decode($this->proxy->injectVisitIpIntoBulkRequest($rawBody, self::VISIT_IP, self::TOKEN_AUTH), true);

        // No batch-level token: it would wrongly authorize the offending entry's own overrides too.
        self::assertArrayNotHasKey('token_auth', $result);

        parse_str(ltrim($result['requests'][0], '?'), $cleanEntryParams);
        self::assertSame(self::VISIT_IP, $cleanEntryParams['cip']);
        self::assertSame(self::TOKEN_AUTH, $cleanEntryParams['token_auth']);

        // The offending entry is left completely untouched — client's own cip/token stand.
        self::assertSame(
            '?idsite=1&action_name=Checkout&token_auth=client-entry-token&cip=9.9.9.9',
            $result['requests'][1]
        );
    }

    public function testUrlSuppliedTokenIsRelocatedIntoBodyAndAuthorizesTheBatch(): void
    {
        $rawBody = json_encode([
            'requests' => ['?idsite=1&action_name=Home'],
        ]);

        $result = json_decode(
            $this->proxy->injectVisitIpIntoBulkRequest($rawBody, self::VISIT_IP, self::TOKEN_AUTH, 'url-client-token'),
            true
        );

        // The client's own token governs — the proxy must never lend its own token on top of it.
        self::assertSame('url-client-token', $result['token_auth']);

        parse_str(ltrim($result['requests'][0], '?'), $params);
        self::assertSame(self::VISIT_IP, $params['cip']);
        self::assertArrayNotHasKey('token_auth', $params);
    }

    public function testEntryProvidedAsArrayIsRewrittenInPlace(): void
    {
        $rawBody = json_encode([
            'requests' => [
                ['idsite' => '1', 'action_name' => 'Home'],
            ],
        ]);

        $result = json_decode($this->proxy->injectVisitIpIntoBulkRequest($rawBody, self::VISIT_IP, self::TOKEN_AUTH), true);

        self::assertSame(self::VISIT_IP, $result['requests'][0]['cip']);
    }

    public function testNonJsonBodyIsReturnedUnchanged(): void
    {
        $rawBody = 'this is not json';

        self::assertSame($rawBody, $this->proxy->injectVisitIpIntoBulkRequest($rawBody, self::VISIT_IP, self::TOKEN_AUTH));
    }

    public function testJsonWithoutRequestsKeyIsReturnedUnchanged(): void
    {
        $rawBody = json_encode(['foo' => 'bar']);

        self::assertSame($rawBody, $this->proxy->injectVisitIpIntoBulkRequest($rawBody, self::VISIT_IP, self::TOKEN_AUTH));
    }
}
