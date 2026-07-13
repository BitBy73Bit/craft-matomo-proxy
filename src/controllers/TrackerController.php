<?php

namespace bitbytebit\matomoproxy\controllers;

use bitbytebit\matomoproxy\Plugin;
use Craft;
use craft\web\Controller;
use craft\web\Response;

/**
 * Public front-end endpoints that proxy tracking traffic to the real Matomo instance.
 */
class TrackerController extends Controller
{
    protected array|bool|int $allowAnonymous = true;
    public $enableCsrfValidation = false;

    public function actionJs(): Response
    {
        return Plugin::getInstance()->proxy->serveTrackerJs(Craft::$app->getRequest());
    }

    public function actionHit(): Response
    {
        return Plugin::getInstance()->proxy->forwardTrackingHit(Craft::$app->getRequest());
    }

    public function actionHsrConfig(): Response
    {
        return Plugin::getInstance()->proxy->forwardHeatmapSessionRecordingConfig(Craft::$app->getRequest());
    }
}
