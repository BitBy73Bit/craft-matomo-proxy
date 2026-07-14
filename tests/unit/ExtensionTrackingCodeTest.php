<?php

namespace bitbytebit\matomoproxy\tests;

use bitbytebit\matomoproxy\web\twig\Extension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Extension::class)]
final class ExtensionTrackingCodeTest extends TestCase
{
    private Extension $extension;

    protected function setUp(): void
    {
        $this->extension = new Extension();
    }

    public function testDefaultOptionsProduceExpectedPushes(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 10);

        self::assertStringContainsString("_paq.push(['requireCookieConsent']);", $html);
        self::assertStringContainsString('_paq.push(["setDoNotTrack", true]);', $html);
        self::assertStringContainsString("_paq.push(['trackPageView']);", $html);
        self::assertStringContainsString("_paq.push(['enableLinkTracking']);", $html);
        self::assertStringNotContainsString('setCookieDomain', $html);
    }

    public function testCookieDomainOptionIsIncludedAndJsonEncoded(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 10, ['cookieDomain' => '*.example.com']);

        self::assertStringContainsString('_paq.push(["setCookieDomain", "*.example.com"]);', $html);
    }

    public function testRequireCookieConsentCanBeDisabled(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 10, ['requireCookieConsent' => false]);

        self::assertStringNotContainsString('requireCookieConsent', $html);
    }

    public function testDoNotTrackCanBeDisabled(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 10, ['doNotTrack' => false]);

        self::assertStringNotContainsString('setDoNotTrack', $html);
    }

    public function testBasePathAndSiteIdAreUsedForTrackerUrlAndSiteId(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 42);

        self::assertStringContainsString('var u = "/insights/";', $html);
        self::assertStringContainsString("_paq.push(['setSiteId', \"42\"]);", $html);
    }

    public function testNoscriptFallbackUsesBasePathAndSiteId(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 42);

        self::assertStringContainsString(
            '<noscript><p><img referrerpolicy="no-referrer-when-downgrade" src="/insights/hit?idsite=42&amp;rec=1" style="border:0;" alt="" /></p></noscript>',
            $html
        );
    }

    public function testSiteIdIsJsonEncodedToPreventScriptInjection(): void
    {
        // A siteId containing a quote must not be able to break out of the JS string literal.
        $html = $this->extension->buildTrackingCode('/insights/', '1"];alert(1);//');

        self::assertStringNotContainsString('alert(1)\']', $html);
        self::assertStringContainsString(json_encode('1"];alert(1);//', JSON_UNESCAPED_SLASHES), $html);
    }

    public function testCookieDomainIsHtmlAndJsSafeWhenContainingQuotes(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 10, ['cookieDomain' => '*.example.com"];alert(1);//']);

        self::assertStringContainsString(
            json_encode('*.example.com"];alert(1);//', JSON_UNESCAPED_SLASHES),
            $html
        );
    }

    public function testHeatmapSessionRecordingAddConfigIsEmittedByDefault(): void
    {
        // Emitted even with nothing configured — an empty addConfig() call still marks Matomo's
        // config state as already-received, which is what suppresses its broken auto-fetch.
        $html = $this->extension->buildTrackingCode('/insights/', 10);

        self::assertStringContainsString('_paq.push(["HeatmapSessionRecording.addConfig"', $html);
        self::assertStringContainsString('"heatmaps":[]', $html);
        self::assertStringContainsString('"sessions":[]', $html);
    }

    public function testHeatmapSessionRecordingCanBeDisabled(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 10, [], false);

        self::assertStringNotContainsString('HeatmapSessionRecording', $html);
    }

    public function testHeatmapConfigIsFormattedForAddConfig(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 10, [], true, [
            ['id' => '5', 'sampleRate' => '50'],
        ]);

        self::assertStringContainsString('"heatmaps":[{"id":5,"sample_rate":"50"}]', $html);
    }

    public function testHeatmapSampleRateDefaultsTo100WhenOmitted(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 10, [], true, [
            ['id' => '5'],
        ]);

        self::assertStringContainsString('"sample_rate":"100"', $html);
    }

    public function testHeatmapRowsWithoutAnIdAreSkipped(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 10, [], true, [
            ['id' => ''],
            ['sampleRate' => '50'],
        ]);

        self::assertStringContainsString('"heatmaps":[]', $html);
    }

    public function testSessionRecordingConfigIsFormattedForAddConfig(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 10, [], true, [], [
            ['id' => '6', 'sampleRate' => '75', 'minTime' => '30', 'keystrokes' => true, 'activity' => false],
        ]);

        self::assertStringContainsString(
            '"sessions":[{"id":6,"sample_rate":"75","min_time":30,"keystrokes":true,"activity":false}]',
            $html
        );
    }

    public function testSessionRecordingDefaultsWhenFieldsOmitted(): void
    {
        $html = $this->extension->buildTrackingCode('/insights/', 10, [], true, [], [
            ['id' => '6'],
        ]);

        self::assertStringContainsString(
            '"sessions":[{"id":6,"sample_rate":"100","min_time":0,"keystrokes":false,"activity":false}]',
            $html
        );
    }
}
