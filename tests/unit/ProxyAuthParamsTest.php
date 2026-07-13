<?php

namespace bitbytebit\matomoproxy\tests;

use bitbytebit\matomoproxy\services\Proxy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Proxy::class)]
final class ProxyAuthParamsTest extends TestCase
{
    private Proxy $proxy;

    protected function setUp(): void
    {
        $this->proxy = new Proxy();
    }

    public function testNoAuthParamsReturnsFalse(): void
    {
        self::assertFalse($this->proxy->clientProvidesAuthParams([]));
        self::assertFalse($this->proxy->clientProvidesAuthParams(['idsite' => '1', 'action_name' => 'Home']));
    }

    public function testNonEmptyStringTokenAuthReturnsTrue(): void
    {
        self::assertTrue($this->proxy->clientProvidesAuthParams(['token_auth' => 'abc123']));
    }

    public function testEmptyStringTokenAuthIsIgnored(): void
    {
        self::assertFalse($this->proxy->clientProvidesAuthParams(['token_auth' => '']));
    }

    public function testNonStringTokenAuthIsIgnored(): void
    {
        // Matomo itself only honors a string token_auth; an array value must not count as one.
        self::assertFalse($this->proxy->clientProvidesAuthParams(['token_auth' => ['nope']]));
    }

    #[DataProvider('overrideParamProvider')]
    public function testAuthProtectedParamPresenceReturnsTrue(string $param): void
    {
        // Presence alone counts, even with an empty/falsy value — array_key_exists, not isset/empty.
        self::assertTrue($this->proxy->clientProvidesAuthParams([$param => '']));
        self::assertTrue($this->proxy->clientProvidesAuthParams([$param => '0']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function overrideParamProvider(): array
    {
        return [
            'cdt' => ['cdt'],
            'cdo' => ['cdo'],
            'country' => ['country'],
            'region' => ['region'],
            'city' => ['city'],
            'lat' => ['lat'],
            'long' => ['long'],
            'cip' => ['cip'],
        ];
    }

    public function testBulkEntryAsArrayDelegatesToClientProvidesAuthParams(): void
    {
        self::assertTrue($this->proxy->bulkEntryProvidesAuthParams(['token_auth' => 'abc']));
        self::assertFalse($this->proxy->bulkEntryProvidesAuthParams(['idsite' => '1']));
    }

    public function testBulkEntryAsQueryStringIsParsed(): void
    {
        self::assertTrue($this->proxy->bulkEntryProvidesAuthParams('?idsite=1&token_auth=abc123'));
        self::assertFalse($this->proxy->bulkEntryProvidesAuthParams('?idsite=1&action_name=Home'));
    }

    public function testBulkEntryWithNoQueryStringReturnsFalse(): void
    {
        self::assertFalse($this->proxy->bulkEntryProvidesAuthParams('not-a-url-with-a-query'));
    }
}
