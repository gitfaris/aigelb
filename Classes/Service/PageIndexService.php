<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Service for managing AI-indexed pages
 *
 * Handles all database operations and business logic
 * related to AI page indexing and status management.
 */
final readonly class PageIndexService
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Retrieve all pages marked for AI indexing
     *
     * @return array<int, array<string, mixed>> Array of indexed page data with enhanced status information
     */
    public function getIndexedPages(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        $pages = $queryBuilder
            ->select(
                'uid',
                'pid',
                'title',
                'slug',
                'hidden',
                'deleted',
                'tstamp',
                'crdate',
                'tx_aigelb_indexpage',
                'tx_aigelb_promptrequirement',
                'tx_aigelb_knowledgebase',
                'tx_aigelb_language',
                'tx_aigelb_lastupdated',
                'tx_aigelb_knowledgeid'
            )
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'tx_aigelb_indexpage',
                    $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)
                )
            )
            ->orderBy('title', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        // Enhance data with additional information
        foreach ($pages as &$page) {
            $page = $this->enhancePageData($page);
        }

        return $pages;
    }

    /**
     * Get count of all indexed pages
     *
     * @return int Number of pages marked for indexing
     */
    public function getIndexedPagesCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'tx_aigelb_indexpage',
                    $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Get statistics about indexed pages
     *
     * @return array<string, int> Array with status counts
     */
    public function getIndexedPagesStatistics(): array
    {
        $pages = $this->getIndexedPages();

        $stats = [
            'total' => count($pages),
            'indexed' => 0,
            'not_indexed' => 0,
            'needs_update' => 0,
            'hidden_deleted' => 0,
        ];

        foreach ($pages as $page) {
            if ($page['hidden'] || $page['deleted']) {
                $stats['hidden_deleted']++;
            } elseif (!$page['isIndexed']) {
                $stats['not_indexed']++;
            } elseif ($page['needsUpdate']) {
                $stats['needs_update']++;
            } else {
                $stats['indexed']++;
            }
        }

        return $stats;
    }

    /**
     * Get pages that need indexing (never indexed)
     *
     * @return array<int, array<string, mixed>> Array of pages needing initial indexing
     */
    public function getPagesNeedingIndexing(): array
    {
        $pages = $this->getIndexedPages();

        return array_filter($pages, function ($page) {
            return !$page['isIndexed'] && !$page['hidden'] && !$page['deleted'];
        });
    }

    /**
     * Get pages that need updating (modified after last index)
     *
     * @return array<int, array<string, mixed>> Array of pages needing updates
     */
    public function getPagesNeedingUpdate(): array
    {
        $pages = $this->getIndexedPages();

        return array_filter($pages, function ($page) {
            return $page['needsUpdate'] && !$page['hidden'] && !$page['deleted'];
        });
    }

    /**
     * Get pages that are hidden or deleted but still indexed
     *
     * @return array<int, array<string, mixed>> Array of pages that should be removed from index
     */
    public function getPagesForRemoval(): array
    {
        $pages = $this->getIndexedPages();

        return array_filter($pages, function ($page) {
            return ($page['hidden'] || $page['deleted']) && $page['isIndexed'];
        });
    }

    /**
     * Enhance page data with calculated status information
     *
     * @param array<string, mixed> $page Raw page data from database
     * @return array<string, mixed> Enhanced page data with status information
     */
    private function enhancePageData(array $page): array
    {
        $page['isIndexed'] = !empty($page['tx_aigelb_knowledgeid']);
        $page['needsUpdate'] = $this->pageNeedsUpdate($page);
        $page['statusClass'] = $this->getPageStatusClass($page);
        $page['statusLabel'] = $this->getPageStatusLabel($page);

        return $page;
    }

    /**
     * Check if page needs knowledge base update
     *
     * @param array<string, mixed> $page Page data
     * @return bool True if page needs update
     */
    private function pageNeedsUpdate(array $page): bool
    {
        if (empty($page['tx_aigelb_lastupdated'])) {
            return true; // Never indexed
        }

        return (int)$page['tstamp'] > (int)$page['tx_aigelb_lastupdated'];
    }

    /**
     * Get CSS class for page status visualization
     *
     * @param array<string, mixed> $page Page data
     * @return string CSS class name
     */
    private function getPageStatusClass(array $page): string
    {
        if ($page['hidden'] || $page['deleted']) {
            return 'danger';
        }

        if (!$page['isIndexed']) {
            return 'warning';
        }

        if ($page['needsUpdate']) {
            return 'info';
        }

        return 'success';
    }

    /**
     * Get human-readable status label
     *
     * @param array<string, mixed> $page Page data
     * @return string Status label key for translation
     */
    private function getPageStatusLabel(array $page): string
    {
        if ($page['hidden']) {
            return 'LLL:EXT:aigelb/Resources/Private/Language/locallang_be.xlf:status.hidden';
        }

        if ($page['deleted']) {
            return 'LLL:EXT:aigelb/Resources/Private/Language/locallang_be.xlf:status.deleted';
        }

        if (!$page['isIndexed']) {
            return 'LLL:EXT:aigelb/Resources/Private/Language/locallang_be.xlf:status.not_indexed';
        }

        if ($page['needsUpdate']) {
            return 'LLL:EXT:aigelb/Resources/Private/Language/locallang_be.xlf:status.needs_update';
        }

        return 'LLL:EXT:aigelb/Resources/Private/Language/locallang_be.xlf:status.up_to_date';
    }
}
