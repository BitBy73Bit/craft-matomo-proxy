<?php

namespace bitbytebit\matomoproxy\tests;

use bitbytebit\matomoproxy\services\Proxy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Proxy::class)]
final class ProxySanitizeContentTest extends TestCase
{
    private Proxy $proxy;

    protected function setUp(): void
    {
        $this->proxy = new Proxy();
    }

    public function testTokenIsScrubbedInRawForm(): void
    {
        $content = 'error for token_auth=abc123secret in request';

        $result = $this->proxy->sanitizeContent($content, 'https://matomo.example.org/', 'https://mysite.example.com/', 'abc123secret');

        self::assertStringNotContainsString('abc123secret', $result);
        self::assertStringContainsString('<token>', $result);
    }

    public function testTokenIsScrubbedInBothEncodedForms(): void
    {
        // rawurlencode() and urlencode() diverge on spaces ('%20' vs '+'), so a token containing one
        // exercises both forms distinctly.
        $token = 'abc def';
        $content = "raw:{$token} rawurlencoded:" . rawurlencode($token) . ' urlencoded:' . urlencode($token);

        $result = $this->proxy->sanitizeContent($content, 'https://matomo.example.org/', 'https://mysite.example.com/', $token);

        self::assertStringNotContainsString($token, $result);
        self::assertStringNotContainsString(rawurlencode($token), $result);
        self::assertStringNotContainsString(urlencode($token), $result);
    }

    public function testEmptyTokenAuthScrubsNothing(): void
    {
        $content = 'perfectly normal content with no token';

        $result = $this->proxy->sanitizeContent($content, 'https://matomo.example.org/', 'https://mysite.example.com/', '');

        self::assertSame($content, $result);
    }

    public function testMatomoHostIsRewrittenToProxyHost(): void
    {
        $content = 'See https://matomo.example.org/index.php for details. Host: matomo.example.org.';

        $result = $this->proxy->sanitizeContent($content, 'https://matomo.example.org/', 'https://mysite.example.com/', '');

        self::assertStringNotContainsString('matomo.example.org', $result);
        self::assertStringContainsString('mysite.example.com', $result);
    }

    public function testMatomoUrlIsRewrittenToProxyUrl(): void
    {
        $content = 'tracker url: https://matomo.example.org/matomo.js';

        $result = $this->proxy->sanitizeContent($content, 'https://matomo.example.org/', 'https://mysite.example.com/', '');

        self::assertStringContainsString('https://mysite.example.com/matomo.js', $result);
    }
}
