<?php

namespace bitbytebit\matomoproxy\tests;

use bitbytebit\matomoproxy\services\Proxy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Proxy::class)]
final class ProxyCookieAllowlistTest extends TestCase
{
    private Proxy $proxy;

    protected function setUp(): void
    {
        $this->proxy = new Proxy();
    }

    public function testExactNameMatch(): void
    {
        self::assertTrue($this->proxy->cookieNameIsAllowed('matomo_ignore', ['matomo_ignore']));
        self::assertFalse($this->proxy->cookieNameIsAllowed('matomo_ignore_other', ['matomo_ignore']));
    }

    public function testPrefixMatch(): void
    {
        self::assertTrue($this->proxy->cookieNameIsAllowed('_pk_id.1.1fff', ['_pk_id*']));
        self::assertTrue($this->proxy->cookieNameIsAllowed('_pk_id', ['_pk_id*']));
        self::assertFalse($this->proxy->cookieNameIsAllowed('other_cookie', ['_pk_id*']));
    }

    public function testMatchingIsCaseSensitive(): void
    {
        self::assertFalse($this->proxy->cookieNameIsAllowed('Matomo_Ignore', ['matomo_ignore']));
    }

    public function testBareWildcardEntryIsNoOp(): void
    {
        // A bare '*' or empty entry must not silently allow everything through.
        self::assertFalse($this->proxy->cookieNameIsAllowed('anything', ['*']));
        self::assertFalse($this->proxy->cookieNameIsAllowed('anything', ['']));
    }

    public function testFilterCookieHeaderKeepsOnlyAllowedCookies(): void
    {
        $header = '_pk_id.1.1fff=abc123; session_id=secret; mtm_consent=1; other=value';
        $allowlist = ['_pk_id*', 'mtm_consent'];

        $result = $this->proxy->filterCookieHeader($header, $allowlist);

        self::assertSame('_pk_id.1.1fff=abc123; mtm_consent=1', $result);
    }

    public function testFilterCookieHeaderReturnsEmptyStringWhenNothingMatches(): void
    {
        self::assertSame('', $this->proxy->filterCookieHeader('foo=bar; baz=qux', ['matomo_ignore']));
    }

    public function testFilterCookieHeaderHandlesEmptyInput(): void
    {
        self::assertSame('', $this->proxy->filterCookieHeader('', ['matomo_ignore']));
    }
}
