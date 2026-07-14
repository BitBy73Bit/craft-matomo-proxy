# Release Notes for Matomo Tracker Proxy

## 1.0.0

- Initial release.
- Proxies Matomo's tracker JS, single/bulk tracking requests, and the Heatmap & Session Recording `configs.php` endpoint through configurable, extension-free Craft routes.
- CP settings with environment-variable-aware `matomoUrl`/`tokenAuth` fields, plus `config/matomo-proxy.php` override support.
- Configurable cookie allowlist and visitor-IP forwarding (`cip`/token or header-based).
- `matomoProxyTrackingCode()` Twig function outputs the tracker script/noscript block wired up to the configured base path.
- `heatmaps`/`sessionRecordings` settings register Heatmap & Session Recording configs directly via Matomo's `addConfig` API, working around Matomo's own tracker code being unable to auto-discover its config URL through this plugin's extension-free routing.
