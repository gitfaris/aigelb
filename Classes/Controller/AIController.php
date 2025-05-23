<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Controller;

use IGelb\Aigelb\Service\AIGelbService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

final class AIController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController {
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly AIGelbService $aIGelbService,
    ) {}

    public function indexAction(): ResponseInterface {

        $conversationId = $this->aIGelbService->createConversation();

        $questions = $this->getQuestions();
        $this->view->assign('questions', $questions);
        return $this->htmlResponse();
    }

    public function responseAction(): ResponseInterface {
        if (!$this->request->hasArgument('userinput')) {
            $this->view->assign('noinput', 'true');
            return $this->htmlResponse();
        }

        /** @var SiteLanguage|null $language */
        $language = $this->request->getAttribute('language');
        $locale = $language !== null ? $language->getLocale()->__toString() : 'de-DE';

        $response = $this->aIGelbService->streamAgent(
            $this->getAgentId(),
            $this->request->getArgument('userinput'),
            $locale
        );

        $this->view->assign('response', $response);
        return $this->htmlResponse();
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

        return $return['tx_aigelb_agentid'] ?? '';
    }

    /**
     * @return array<int, mixed>|false
     */
    protected function getQuestions(): array|false {
        return $this->connectionPool
            ->getConnectionForTable('tx_aigelb_domain_model_questions')
            ->select(
                ['question'],
                'tx_aigelb_domain_model_questions',
                [],
            )
            ->fetchALlAssociative();
    }
}
