<?php

declare(strict_types=1);

use IGelb\Aigelb\Controller\AIChatbotController;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') || exit;

(static function (): void {
    $extensionKey = 'aigelb';

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScript(
        $extensionKey,
        'setup',
        "@import 'EXT:$extensionKey/Configuration/TypoScript/setup.typoscript'"
    );

    ExtensionUtility::configurePlugin(
        'aigelb',
        'Aigelbframework',
        [AIChatbotController::class => 'chatbot'],
        [AIChatbotController::class => 'chatbot'],
        ExtensionUtility::PLUGIN_TYPE_CONTENT_ELEMENT
    );

    //DataHandler Hooks
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['ig_site-update-page'] = \IGelb\Aigelb\Hooks\DataHandlerHooks\DataHandlerHook::class;
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['ig_site-update-page-delete'] = \IGelb\Aigelb\Hooks\DataHandlerHooks\DataHandlerHook::class;
})();
