<?php

namespace bitbytebit\matomoproxy;

use bitbytebit\matomoproxy\models\Settings;
use bitbytebit\matomoproxy\services\Proxy;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Proxies Matomo tracking requests through this site's own domain so the real
 * Matomo server URL is never exposed to visitors or search engines.
 *
 * @property-read Proxy $proxy
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'proxy' => Proxy::class,
        ]);

        Craft::$app->onInit(function() {
            $this->_registerSiteUrlRules();
        });
    }

    public function getSettings(): Settings
    {
        /** @var Settings */
        return parent::getSettings();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('matomo-proxy/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    private function _registerSiteUrlRules(): void
    {
        $basePath = trim($this->getSettings()->basePath, '/');

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) use ($basePath) {
                $event->rules["$basePath/js"] = 'matomo-proxy/tracker/js';
                $event->rules["$basePath/hit"] = 'matomo-proxy/tracker/hit';

                if ($this->getSettings()->includeHeatmapSessionRecording) {
                    $event->rules["$basePath/hsr-config"] = 'matomo-proxy/tracker/hsr-config';
                }
            }
        );
    }
}
