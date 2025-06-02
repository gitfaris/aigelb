<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

/**
 * Add TypoScript for AI-Gelb Extension
 * This file automatically includes the TypoScript configuration
 * for the AI-Gelb chatbot functionality
 */

// Add static TypoScript (includes setup.typoscript)
ExtensionManagementUtility::addStaticFile(
    'aigelb',
    'Configuration/TypoScript',
    'AI-Gelb Chatbot'
);
