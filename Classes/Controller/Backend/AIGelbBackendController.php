<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Controller\Backend;

use IGelb\Aigelb\Service\PageIndexService;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Psr\Http\Message\ResponseInterface;

/**
 * Backend Controller for AI-Gelb Administration
 *
 * Provides backend interface for managing AI-indexed pages
 * and monitoring knowledge base synchronization status.
 */
final class AIGelbBackendController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageIndexService $pageIndexService,
    ) {}

    /**
     * Display overview of all AI-indexed pages
     *
     * Shows list of pages marked for AI indexing with their current status,
     * last update timestamps, and knowledge base information.
     *
     * @return ResponseInterface Backend module response
     */
    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        // Set module title and description
        $moduleTemplate->setTitle($this->getLanguageService()->sL('LLL:EXT:aigelb/Resources/Private/Language/locallang_be.xlf:module.title'));

        // Get all AI-indexed pages and statistics
        $indexedPages = $this->pageIndexService->getIndexedPages();
        $statistics = $this->pageIndexService->getIndexedPagesStatistics();

        // Assign data to view
        $this->view->assignMultiple([
            'indexedPages' => $indexedPages,
            'pageCount' => $statistics['total'],
            'statistics' => $statistics,
        ]);

        $moduleTemplate->setContent($this->view->render());

        return $this->htmlResponse($moduleTemplate->renderContent());
    }

    /**
     * Get TYPO3 language service
     *
     * @return \TYPO3\CMS\Core\Localization\LanguageService
     */
    private function getLanguageService(): \TYPO3\CMS\Core\Localization\LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
