<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Service;

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Extbase\Mvc\Request;

/**
 * Service for handling language-related operations
 */
final readonly class LanguageService
{
    private const DEFAULT_LANGUAGE = 'de-DE';

    /**
     * Get current language locale from request context
     *
     * @param Request $request The current request object
     * @return string Language locale (e.g., 'de-DE', 'en-US')
     */
    public function getCurrentLanguage(Request $request): string
    {
        /** @var SiteLanguage|null $language */
        $language = $request->getAttribute('language');

        if ($language === null) {
            return self::DEFAULT_LANGUAGE;
        }

        return $language->getLocale()->__toString();
    }

    /**
     * Get current language ISO code (2-letter) from request context
     *
     * @param Request $request The current request object
     * @return string Language code (e.g., 'de', 'en')
     */
    public function getCurrentLanguageCode(Request $request): string
    {
        /** @var SiteLanguage|null $language */
        $language = $request->getAttribute('language');

        if ($language === null) {
            return 'de'; // Default fallback
        }

        return $language->getLocale()->getLanguageCode();
    }

    /**
     * Check if current language is supported by AI-Gelb API
     *
     * @param Request $request The current request object
     * @return bool True if language is supported
     */
    public function isSupportedLanguage(Request $request): bool
    {
        $supportedLanguages = ['de', 'en'];
        $currentLanguage = $this->getCurrentLanguageCode($request);

        return in_array($currentLanguage, $supportedLanguages, true);
    }

    /**
     * Get fallback language if current language is not supported
     *
     * @param Request $request The current request object
     * @return string Supported language locale
     */
    public function getSupportedLanguageOrFallback(Request $request): string
    {
        if ($this->isSupportedLanguage($request)) {
            return $this->getCurrentLanguage($request);
        }

        return self::DEFAULT_LANGUAGE;
    }
}
