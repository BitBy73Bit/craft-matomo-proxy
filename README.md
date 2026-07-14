# Matomo Tracker Proxy for Craft CMS

Proxies [Matomo](https://matomo.org/) tracking requests through your own domain, so the real Matomo server URL is never exposed to visitors, in your page source, or to search engines.

This is a Craft CMS port of the official [`matomo-org/tracker-proxy`](https://github.com/matomo-org/tracker-proxy) PHP scripts — same tracking/bulk-request/cookie-forwarding behaviour, wired up as a native Craft plugin with CP settings, environment-variable-aware config, and a test suite.

## Why not just drop the upstream files in?

The upstream project's convention is literal filenames (`matomo.php`, `piwik.php`) matching Matomo's default embed snippet. Many nginx configs for PHP apps — including Craft's own common hosting template — 404 any `.php`-suffixed request that doesn't correspond to a real file, *before* the application ever sees it. This plugin instead registers ordinary Craft routes at a path you choose, which works identically on any host (Apache, nginx, IIS) with zero server config changes, and doubles as better disguise for what the routes actually do.

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later
- A Matomo instance (self-hosted or Matomo Cloud) reachable via outbound HTTPS from your Craft server

## Installation

```sh
composer require bitbytebit/craft-matomo-proxy
```

Then install the plugin in the Craft control panel (**Settings → Plugins**), or via the CLI:

```sh
php craft plugin/install matomo-proxy
```

## Matomo-side setup

1. Log in to your Matomo instance as a Super User.
2. Create a new user — for example, login `proxy-tracker`.
3. Give that user **write** or **admin** permission on every site you want tracked through the proxy (this is required for the proxy to authorize the visitor's real IP address on the tracking request — without it, Matomo would only ever see the proxy server's own IP).
4. Log in as that user and [create an auth token](https://matomo.org/faq/general/faq_114/) — this is the `token_auth` value used below.

## Plugin settings

Configure via **Settings → Plugins → Matomo Tracker Proxy** in the control panel, or by returning an array from `config/matomo-proxy.php` in your Craft project (this takes precedence over the CP-saved settings, and is the recommended approach for anything you want version-controlled):

```php
<?php

use craft\helpers\App;

return [
    'matomoUrl' => App::env('MATOMO_URL'),
    'tokenAuth' => App::env('MATOMO_TOKEN_AUTH'),
    'basePath' => 'matomo-proxy',
];
```

| Setting | Default | Description |
|---|---|---|
| `matomoUrl` | — (required) | Your real Matomo instance URL, ending in a slash. Never exposed to visitors. |
| `tokenAuth` | — (required) | The `token_auth` for the user created above. **Recommended:** set this to an environment variable reference (e.g. `$MATOMO_TOKEN_AUTH`) rather than a literal value, in either the CP field or your `config/matomo-proxy.php`, so the secret itself is never written to project config. |
| `basePath` | `matomo-proxy` | Site-relative path this plugin's routes are served under. Rename it to something unrelated-looking if you want to fully disguise that Matomo is in use. |
| `includeHeatmapSessionRecording` | `true` | Proxies Matomo's Heatmap & Session Recording `configs.php` endpoint, and has `matomoProxyTrackingCode()` register the `heatmaps`/`sessionRecordings` below via Matomo's `addConfig` API. Turn off if you don't use that Matomo plugin. |
| `heatmaps` | *(none)* | Heatmap configs (`id`, `sampleRate`) to register via `addConfig`. Find these values in Matomo under Administration → Websites → Heatmap & Session Recording. See [Heatmap & Session Recording](#heatmap--session-recording) below for why this is necessary. |
| `sessionRecordings` | *(none)* | Session recording configs (`id`, `sampleRate`, `minTime`, `keystrokes`, `activity`), same as `heatmaps` above. |
| `timeout` | `5` | Seconds to wait for the Matomo server to respond. |
| `httpIpForwardHeader` | *(blank)* | Forward the visitor IP via this HTTP header (e.g. `X-Forwarded-For`) instead of the default `cip` tracking parameter. Only works if Matomo's trusted-proxy settings are configured for it — see [Visitor IP forwarding](#visitor-ip-forwarding) below. Leave blank to use the default method. |
| `cookieAllowlist` | Matomo's standard cookie names/prefixes | One cookie name per line; entries ending in `*` match by prefix. Only cookies matching an entry are forwarded to Matomo — everything else (session cookies, other first-party cookies, etc.) is stripped before the request leaves your server. Keep `matomo_ignore` in the list, or visitors who opted out get silently re-tracked. |

## Routes

With the default `basePath` of `matomo-proxy`, this plugin serves:

| Route | Method | Proxies |
|---|---|---|
| `/matomo-proxy/js` | `GET` | `matomo.js` |
| `/matomo-proxy/hit` | `GET`, `POST` | `matomo.php` (single and bulk tracking requests) |
| `/matomo-proxy/hsr-config` | `GET` | `plugins/HeatmapSessionRecording/configs.php` (if `includeHeatmapSessionRecording` is enabled) |

## Adding the tracking code to your templates

The plugin registers a `matomoProxyTrackingCode()` Twig function that outputs the tracker `<script>`/`<noscript>` block already wired up to your current `basePath` — no hardcoded URLs to keep in sync if you rename it later:

```twig
{{ matomoProxyTrackingCode(10) }}
```

Pass a second argument for the optional extras Matomo's own generated snippet usually includes:

```twig
{{ matomoProxyTrackingCode(10, {
    cookieDomain: '*.example.com',
    doNotTrack: true,
    requireCookieConsent: true,
}) }}
```

| Option | Default | Description |
|---|---|---|
| `cookieDomain` | *(unset)* | If set, pushes `setCookieDomain` before tracking starts. |
| `doNotTrack` | `true` | Pushes `setDoNotTrack`. |
| `requireCookieConsent` | `true` | Pushes `requireCookieConsent`, so Matomo sets no cookie until your own consent flow calls `rememberCookieConsentGiven`/`forgetCookieConsentGiven`. Set to `false` if you don't have a cookie-consent flow and want tracking to start immediately. |

If you're not using Craft templates for this (e.g. embedding it elsewhere), the equivalent raw HTML is:

```html
<script>
  var _paq = window._paq = window._paq || [];
  _paq.push(['requireCookieConsent']);
  _paq.push(["setDoNotTrack", true]);
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  (function() {
    var u = "/matomo-proxy/"; // same-origin, not your real Matomo URL — matches your basePath setting
    _paq.push(['setTrackerUrl', u + 'hit']);
    _paq.push(['setSiteId', 'YOUR_SITE_ID']);
    var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
    g.async = true;
    g.src = u + 'js';
    s.parentNode.insertBefore(g, s);
  })();
</script>
<noscript><p><img referrerpolicy="no-referrer-when-downgrade" src="/matomo-proxy/hit?idsite=YOUR_SITE_ID&rec=1" style="border:0;" alt="" /></p></noscript>
```

## Heatmap & Session Recording

If your Matomo instance has the Heatmap & Session Recording plugin enabled, its tracker code is embedded directly inside `matomo.js` and starts running automatically as soon as the tracker loads — you don't opt into it from your own template.

By default, that code tries to auto-fetch its configuration over HTTP from a URL it derives from your tracker URL: it checks whether the tracker URL you set literally ends in `matomo.php` or `piwik.php`, and only strips that suffix to compute Matomo's base URL if so. This plugin deliberately avoids `.php`-suffixed routes (see [Why not just drop the upstream files in?](#why-not-just-drop-the-upstream-files-in) above), so that check never matches, and Heatmap & Session Recording ends up requesting a broken, nonsensical URL — which typically shows up as a browser console error like:

> Refused to execute script from '.../hit/plugins/HeatmapSessionRecording/configs.php?...' because its MIME type ('text/html') is not executable...

`matomoProxyTrackingCode()` works around this by registering your `heatmaps`/`sessionRecordings` settings directly via Matomo's `HeatmapSessionRecording.addConfig` JS API, which also marks Matomo's internal config state as already-received — so the broken auto-fetch never fires in the first place. This happens automatically whenever `includeHeatmapSessionRecording` is enabled, even if you haven't configured any heatmaps/recordings yet (an empty `addConfig` call is enough to suppress the auto-fetch and stop the console error).

## Visitor IP forwarding

Because the proxy sits between your visitors and Matomo, it has to tell Matomo the real visitor IP — otherwise Matomo records the proxy server's own IP for every visit.

- **Default — via `cip` + `tokenAuth`:** sent as the `cip` tracking parameter, authorized by your configured token (this is why that user needs write/admin permission). Works out of the box, no Matomo-side configuration, for both single and bulk requests.
- **Header-only — via `httpIpForwardHeader`:** set this to, for example, `X-Forwarded-For`, to forward the visitor IP in that header instead. In this mode the plugin injects **no** `cip`/token at all, so this doesn't even require write access — but it only works if Matomo is configured to trust the header (both your web server in front of Matomo and Matomo's own trusted-proxy settings, `proxy_client_headers[]`/`proxy_ips[]` in `config.ini.php`). If it isn't, Matomo records the proxy's IP for every visitor.

## Attribution & license

This plugin ports the request-handling logic of [`matomo-org/tracker-proxy`](https://github.com/matomo-org/tracker-proxy), which is licensed GPL-3.0. As a derivative work, this plugin is licensed **GPL-3.0-or-later** — see [LICENSE.md](LICENSE.md).

## Development

```sh
composer install
composer test       # PHPUnit
composer check-cs   # Easy Coding Standard
composer phpstan    # PHPStan
```
