<?php

declare(strict_types=1);

/**
 * Backend Module Configuration for AI-Gelb Extension
 *
 * Registers the backend module for managing AI-indexed pages
 * and monitoring knowledge base synchronization status.
 */

use IGelb\Aigelb\Controller\Backend\AIGelbBackendController;

return [
    'aigelb' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user',
        'workspaces' => 'live',
        'path' => '/module/web/aigelb',
        'iconIdentifier' => 'tx-aigelb-svgicon',
        'labels' => 'LLL:EXT:aigelb/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'Aigelb',
        'controllerActions' => [
            AIGelbBackendController::class => [
                'index',
            ],
        ],
    ],
];
