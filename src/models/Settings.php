<?php

namespace bitbytebit\matomoproxy\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Matomo Tracker Proxy settings.
 *
 * `matomoUrl` and `tokenAuth` accept either a literal value or an environment
 * variable reference (e.g. `$MATOMO_TOKEN_AUTH`) — resolved via the
 * `getMatomoUrl()`/`getTokenAuth()` accessors, Craft's standard pattern for
 * settings that may hold a secret.
 */
class Settings extends Model
{
    /** The real Matomo instance URL, e.g. `https://your-account.matomo.cloud/`. Never exposed to visitors. */
    public ?string $matomoUrl = null;

    /** The `token_auth` for a Matomo user with write/admin access, used to authorize visitor-IP tracking. */
    public ?string $tokenAuth = null;

    /** Site-relative path this plugin's routes are served under, e.g. `matomo-proxy` for `/matomo-proxy/hit`. */
    public string $basePath = 'matomo-proxy';

    /** Seconds to wait for the Matomo server to respond before giving up. */
    public int $timeout = 5;

    /**
     * HTTP header to forward the visitor IP in (e.g. `X-Forwarded-For`) instead of the default
     * `cip` tracking parameter. Requires Matomo's trusted-proxy config to be set up for this header;
     * see the README. Leave blank to use the default `cip` + token_auth method.
     */
    public string $httpIpForwardHeader = '';

    /**
     * Cookie names forwarded to Matomo, one per line. Entries ending in `*` match by prefix (needed
     * for Matomo's per-site/per-domain id/session/referrer/custom-variable cookies). `matomo_ignore`
     * must stay in this list or opted-out visitors get silently re-tracked.
     */
    public string $cookieAllowlist = "_pk_id*\n_pk_ses*\n_pk_ref*\n_pk_cvar*\n_pk_hsr*\nmtm_consent\nmtm_consent_removed\nmatomo_ignore";

    /** Whether to also proxy the Heatmap & Session Recording plugin's `configs.php` endpoint. */
    public bool $includeHeatmapSessionRecording = true;

    /**
     * Heatmap configs to register directly via `HeatmapSessionRecording.addConfig`, bypassing
     * Matomo's automatic HTTP config fetch entirely — that fetch derives its URL from the tracker
     * URL in a way that's incompatible with this plugin's routing (see the README). Find these
     * values in Matomo under Administration -> Websites -> Heatmap & Session Recording.
     *
     * @var array<int, array{id: string, sampleRate: string}>
     */
    public array $heatmaps = [];

    /**
     * Session recording configs, registered the same way as {@see $heatmaps}.
     *
     * @var array<int, array{id: string, sampleRate: string, minTime: string, keystrokes: bool, activity: bool}>
     */
    public array $sessionRecordings = [];

    public function getMatomoUrl(): ?string
    {
        return App::parseEnv($this->matomoUrl);
    }

    public function getTokenAuth(): ?string
    {
        return App::parseEnv($this->tokenAuth);
    }

    /**
     * @return string[] Non-empty, trimmed cookie-allowlist entries.
     */
    public function getCookieAllowlist(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $this->cookieAllowlist) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn(string $line) => $line !== ''));
    }

    protected function defineRules(): array
    {
        return [
            [['matomoUrl', 'tokenAuth', 'basePath'], 'required'],
            [['timeout'], 'integer', 'min' => 1],
            [['basePath'], 'trim', 'chars' => '/'],
        ];
    }
}
