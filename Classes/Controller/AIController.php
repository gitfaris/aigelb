<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Controller;

use IGelb\Aigelb\Service\AIGelbService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * AI Controller for handling chatbot interactions
 */
final class AIController extends ActionController
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly AIGelbService $aIGelbService,
    ) {}

    /**
     * Main chatbot action handling both display and response logic
     */
    public function chatbotAction(): ResponseInterface
    {
        // Initialize conversation ID only if not provided from request
        $conversationId = $this->request->hasArgument('conversationId')
            ? (string)$this->request->getArgument('conversationId')
            : $this->aIGelbService->createConversation();

        $this->view->assign('conversationId', $conversationId);

        // Load predefined questions for initial display
        $questions = $this->getQuestions();
        $this->view->assign('questions', $questions);

        // Load existing conversation messages if conversation ID exists
        $conversationMessages = [];
        if (!empty($conversationId)) {
            $conversationMessages = $this->aIGelbService->getConversationMessages($conversationId);
        }
        $this->view->assign('conversationMessages', $conversationMessages);

        // Handle user input if provided
        if ($this->request->hasArgument('userinput')) {
            $userInput = trim((string)$this->request->getArgument('userinput'));

            if (empty($userInput)) {
                $this->view->assign('hasError', true);
                $this->view->assign('errorMessage', 'Please provide a valid input.');
                return $this->htmlResponse();
            }

            // Get language from current site context
            $language = $this->getCurrentLanguage();

            // Process AI response
            $response = $this->aIGelbService->streamAgent(
                $this->getAgentId(),
                $userInput,
                $language,
                $conversationId
            );

            // Assign response data to view
            $this->view->assign('hasResponse', true);
            $this->view->assign('userInput', $userInput);
            $this->view->assign('aiResponse', $response);
        }

        return $this->htmlResponse();
    }

    /**
     * Get the current agent ID from database or environment
     */
    protected function getAgentId(): string
    {
        // Priority: Environment variable first, then database
        $envAgentId = getenv('AIGELB_AGENTID');
        if ($envAgentId !== false && !empty($envAgentId)) {
            return $envAgentId;
        }

        $result = $this->connectionPool
            ->getConnectionForTable('tt_content')
            ->select(
                ['tx_aigelb_agentid'],
                'tx_aigelb_domain_model_agent',
                []
            )
            ->fetchAssociative();

        return $result['tx_aigelb_agentid'] ?? '';
    }

    /**
     * Get predefined questions from database
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getQuestions(): array
    {
        $result = $this->connectionPool
            ->getConnectionForTable('tx_aigelb_domain_model_questions')
            ->select(
                ['question'],
                'tx_aigelb_domain_model_questions',
                []
            )
            ->fetchAllAssociative();

        return $result ?: [];
    }

    /**
     * Get current language locale for AI processing
     */
    protected function getCurrentLanguage(): string
    {
        /** @var SiteLanguage|null $language */
        $language = $this->request->getAttribute('language');

        if ($language === null) {
            return 'de-DE'; // Default fallback
        }

        return $language->getLocale()->__toString();
    }
}
