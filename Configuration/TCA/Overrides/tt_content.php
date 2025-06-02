<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

(static function (): void {
    $pluginSignature = ExtensionUtility::registerPlugin(
        'aigelb',
        'AigelbChatbot',
        'AI Gelb ChatBot',
    );

    // Simplified showitem configuration - nur noch FlexForm und Standardfelder
    $GLOBALS['TCA']['tt_content']['types']['aigelb_aigelbchatbot']['showitem'] = '
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
            --palette--;;general,
        --div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.plugin,
            pi_flexform,
        --div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.appearance,
            --palette--;;frames,
            --palette--;;appearanceLinks,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:language,
            --palette--;;language,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
            --palette--;;hidden,
            --palette--;;access,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:categories,
            categories,
        --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:notes,
            rowDescription,
    ';

    // FlexForm-Konfiguration hinzufügen
    ExtensionManagementUtility::addPiFlexFormValue(
        '*',
        'FILE:EXT:aigelb/Configuration/FlexForms/Chatbot.xml',
        'aigelb_aigelbchatbot'
    );
})();
