<?php

namespace bitbytebit\matomoproxy\web\twig;

use bitbytebit\matomoproxy\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Registers `matomoProxyTrackingCode()`, a global Twig function that outputs the
 * Matomo tracker `<script>`/`<noscript>` block wired up to this plugin's
 * currently configured base path — so templates never need to hand-build or
 * hardcode the proxy URL themselves.
 */
class Extension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('matomoProxyTrackingCode', [$this, 'trackingCode'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array{cookieDomain?: string, doNotTrack?: bool, requireCookieConsent?: bool} $options
     */
    public function trackingCode(int|string $siteId, array $options = []): Markup
    {
        $settings = Plugin::getInstance()->getSettings();
        $basePath = '/' . trim($settings->basePath, '/') . '/';

        return new Markup(
            $this->buildTrackingCode(
                $basePath,
                $siteId,
                $options,
                $settings->includeHeatmapSessionRecording,
                $settings->heatmaps,
                $settings->sessionRecordings
            ),
            'utf-8'
        );
    }

    /**
     * Pure HTML-building logic, kept framework-agnostic so it can be unit tested
     * without a bootstrapped Craft app.
     *
     * @param array{cookieDomain?: string, doNotTrack?: bool, requireCookieConsent?: bool} $options
     * @param array<int, array{id?: string, sampleRate?: string}> $heatmaps
     * @param array<int, array{id?: string, sampleRate?: string, minTime?: string, keystrokes?: bool, activity?: bool}> $sessionRecordings
     */
    public function buildTrackingCode(
        string $basePath,
        int|string $siteId,
        array $options = [],
        bool $manageHeatmapSessionRecording = true,
        array $heatmaps = [],
        array $sessionRecordings = [],
    ): string {
        $requireCookieConsent = $options['requireCookieConsent'] ?? true;
        $doNotTrack = $options['doNotTrack'] ?? true;
        $cookieDomain = $options['cookieDomain'] ?? null;

        $pushes = [];

        if ($requireCookieConsent) {
            // Must be pushed before other tracking calls — stops Matomo setting any
            // cookie until the site's own consent flow calls rememberCookieConsentGiven.
            $pushes[] = "  _paq.push(['requireCookieConsent']);";
        }

        if ($manageHeatmapSessionRecording) {
            // Registers heatmap/session-recording configs directly, which also marks Matomo's
            // internal config state as already-received. Matomo's own tracker JS would otherwise
            // auto-fetch these over HTTP from a URL it derives by string-matching "matomo.php"/
            // "piwik.php" onto the configured tracker URL — a match that can never succeed here,
            // since this plugin deliberately avoids literal .php-suffixed routes (they get blocked
            // by nginx-style hosting before Craft ever sees them). See the README.
            $config = [
                'heatmaps' => $this->formatHeatmapConfigs($heatmaps),
                'sessions' => $this->formatSessionRecordingConfigs($sessionRecordings),
            ];
            $pushes[] = '  _paq.push(["HeatmapSessionRecording.addConfig", ' . json_encode($config, JSON_UNESCAPED_SLASHES) . ']);';
        }

        if ($cookieDomain !== null) {
            $pushes[] = '  _paq.push(["setCookieDomain", ' . json_encode($cookieDomain, JSON_UNESCAPED_SLASHES) . ']);';
        }

        if ($doNotTrack) {
            $pushes[] = '  _paq.push(["setDoNotTrack", true]);';
        }

        $pushes[] = "  _paq.push(['trackPageView']);";
        $pushes[] = "  _paq.push(['enableLinkTracking']);";

        $siteIdJs = json_encode((string) $siteId, JSON_UNESCAPED_SLASHES);
        $basePathJs = json_encode($basePath, JSON_UNESCAPED_SLASHES);
        $noscriptSrc = htmlspecialchars(
            $basePath . 'hit?idsite=' . rawurlencode((string) $siteId) . '&rec=1',
            ENT_QUOTES,
            'UTF-8'
        );

        $script = "<script>\n"
            . "  var _paq = window._paq = window._paq || [];\n"
            . implode("\n", $pushes) . "\n"
            . "  (function() {\n"
            . "    var u = {$basePathJs};\n"
            . "    _paq.push(['setTrackerUrl', u + 'hit']);\n"
            . "    _paq.push(['setSiteId', {$siteIdJs}]);\n"
            . "    var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];\n"
            . "    g.async = true;\n"
            . "    g.src = u + 'js';\n"
            . "    s.parentNode.insertBefore(g, s);\n"
            . "  })();\n"
            . "</script>\n";

        $noscript = '<noscript><p><img referrerpolicy="no-referrer-when-downgrade" src="'
            . $noscriptSrc
            . '" style="border:0;" alt="" /></p></noscript>';

        return $script . $noscript;
    }

    /**
     * @param array<int, array{id?: string, sampleRate?: string}> $heatmaps
     * @return array<int, array{id: int, sample_rate: string}>
     */
    private function formatHeatmapConfigs(array $heatmaps): array
    {
        $formatted = [];

        foreach ($heatmaps as $heatmap) {
            if (!isset($heatmap['id']) || $heatmap['id'] === '') {
                continue;
            }

            $formatted[] = [
                'id' => (int) $heatmap['id'],
                'sample_rate' => (string) ($heatmap['sampleRate'] ?? '100'),
            ];
        }

        return $formatted;
    }

    /**
     * @param array<int, array{id?: string, sampleRate?: string, minTime?: string, keystrokes?: bool, activity?: bool}> $sessionRecordings
     * @return array<int, array{id: int, sample_rate: string, min_time: int, keystrokes: bool, activity: bool}>
     */
    private function formatSessionRecordingConfigs(array $sessionRecordings): array
    {
        $formatted = [];

        foreach ($sessionRecordings as $session) {
            if (!isset($session['id']) || $session['id'] === '') {
                continue;
            }

            $formatted[] = [
                'id' => (int) $session['id'],
                'sample_rate' => (string) ($session['sampleRate'] ?? '100'),
                'min_time' => (int) ($session['minTime'] ?? 0),
                'keystrokes' => (bool) ($session['keystrokes'] ?? false),
                'activity' => (bool) ($session['activity'] ?? false),
            ];
        }

        return $formatted;
    }
}
