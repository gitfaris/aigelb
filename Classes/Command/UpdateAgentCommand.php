<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Command;

use Doctrine\DBAL\ParameterType;
use IGelb\Aigelb\Service\AIGelbService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsCommand('aigelb:updateagent', "Update agent's knowledgebase and (optionally) system prompt")]
class UpdateAgentCommand extends Command {
    protected SymfonyStyle|null $io = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly AIGelbService $aIGelbService,
        private readonly SiteFinder $siteFinder,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void {
        $this->setHelp("Update agent's knowledgebase and (optionally) system prompt");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title($this->getDescription());

        $baseUrl = $this->getBaseUrl();

        if (!$this->aIGelbService->hasAgent()) {
            $this->io->info('No agent ID was created yet. Fetching one from AI-Gelb');
            $agentId = $this->aIGelbService->getAgentId($baseUrl);

            $this->io->info('Saving agent ID');
            $this->aIGelbService->saveAgentId($agentId);
        } else {
            $this->io->info('Get Agent ID');
            $agentId = $this->getAgentId();
        }

        $this->io->info('Fetching all pages for potential updates');
        $pages = $this->getAllNonIndexedPagesToIndex();

        // index new pages
        foreach ($pages as $page) {
            if (!$page['tx_aigelb_lastupdated']) {
                $this->updatePage($agentId, $page, $baseUrl);
            }
        }

        // update existing pages with changes
        $this->io->info('Checking for updated pages'); // @phpstan-ignore-line
        $pages = $this->getAllIndexedPagesUpdatedAfterIndex();
        foreach ($pages as $page) {
            $this->updatePage($agentId, $page, $baseUrl);
        }

        // remove hidden or deleted pages
        $this->io->info('Checking for hidden or deleted pages'); // @phpstan-ignore-line
        $pages = $this->getAllIndexedPagesWhichAreHiddenOrDeleted();
        foreach ($pages as $page) {
            $this->aIGelbService->deleteKnowledge($page['tx_aigelb_knowledgeid']);
        }

        $this->io->success('All pages updated'); // @phpstan-ignore-line
        return Command::SUCCESS;

    }

    protected function updatePage(string $agentId, array $page, string $baseUrl): void { // @phpstan-ignore-line
        $this->io->info('Adding page uid ' . $page['uid']); // @phpstan-ignore-line
        $fullUrl = rtrim($baseUrl, '/') . '/' . ltrim($page['slug'], '/');
        $promptRequirement = $page['tx_aigelb_promptrequirement'] ?? '';

        $response = $this->aIGelbService->addKnowledge(
            $agentId,
            $fullUrl,
            $promptRequirement
        );

        if ($response) {
            $response = json_decode($response);
            $this->saveUpdatedTimeAndKnowledgeForPage($page['uid'], $response->id);
            $this->io->success('Page uid ' . $page['uid'] . ' added'); // @phpstan-ignore-line
        } else {
            $this->io->error('Adding page uid ' . $page['uid'] . ' failed'); // @phpstan-ignore-line
        }
    }

    protected function getAgentId(): string {
        // in case we already have an agentId set in Directus we transfer it through .env
        if (getenv('AIGELB_AGENTID')) {
            return getenv('AIGELB_AGENTID');
        }

        $return = $this->connectionPool
            ->getConnectionForTable('tt_content')
            ->select(
                ['tx_aigelb_agentid'],
                'tx_aigelb_domain_model_agent',
                [],
            )
            ->fetchAssociative();

        return $return['tx_aigelb_agentid']; // @phpstan-ignore-line
    }

    protected function saveUpdatedTimeAndKnowledgeForPage(int $pageUid, string $knowledgeId): void {
        $this->connectionPool
            ->getConnectionForTable('pages')
            ->update(
                'pages',
                ['tx_aigelb_lastupdated' => time(), 'tx_aigelb_knowledgeid' => $knowledgeId],
                ['uid' => $pageUid]
            );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getAllNonIndexedPagesToIndex() {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->select(
            'uid',
            'slug',
            'tx_aigelb_indexpage',
            'tx_aigelb_promptrequirement',
            'tx_aigelb_knowledgebase',
            'tx_aigelb_language',
            'tx_aigelb_lastupdated'
        )
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('tx_aigelb_indexpage', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER))
            )
            ->andWhere(
                $queryBuilder->expr()->isNotNull('tx_aigelb_knowledgebase')
            )
            ->andWhere(
                $queryBuilder->expr()->isNotNull('tx_aigelb_language')
            )
            ->andWhere(
                $queryBuilder->expr()->isNotNull('tx_aigelb_lastupdated')
            );
        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getAllIndexedPagesUpdatedAfterIndex() {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->select(
            'uid',
            'slug',
            'tx_aigelb_indexpage',
            'tx_aigelb_promptrequirement',
            'tx_aigelb_knowledgebase',
            'tx_aigelb_language',
            'tx_aigelb_lastupdated'
        )
            ->from('pages')
            ->where(
                $queryBuilder->expr()->gte('SYS_LASTCHANGED', 'tx_aigelb_lastupdated')
            )
            ->andWhere(
                $queryBuilder->expr()->eq('tx_aigelb_indexpage', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER))
            )
            ->andWhere(
                $queryBuilder->expr()->isNotNull('tx_aigelb_knowledgebase')
            )
            ->andWhere(
                $queryBuilder->expr()->isNotNull('tx_aigelb_language')
            )
            ->andWhere(
                $queryBuilder->expr()->isNotNull('tx_aigelb_lastupdated')
            );
        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    protected function getAllIndexedPagesWhichAreHiddenOrDeleted(): mixed {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select(
            'uid',
            'tx_aigelb_knowledgeid'
        )
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('tx_aigelb_indexpage', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER))
            )
            ->andWhere(
                $queryBuilder->expr()->isNotNull('tx_aigelb_knowledgebase')
            )
            ->andWhere(
                $queryBuilder->expr()->isNotNull('tx_aigelb_language')
            )
            ->andWhere(
                $queryBuilder->expr()->isNotNull('tx_aigelb_lastupdated')
            )
            ->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER))
                )
            );
        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    protected function getBaseUrl(): string {
        $sites = $this->siteFinder->getAllSites();
        $site = reset($sites);

        if ($site) {
            $baseUrl = $site->getBase()->__toString();
            return $baseUrl;
        }

        throw new \Exception('Base could not be fetched from site configuration');
    }

}
