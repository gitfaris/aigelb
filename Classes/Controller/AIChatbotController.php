<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Controller;

use IGelb\Aigelb\Service\AIGelbService;
use IGelb\Aigelb\Service\ConversationService;
use IGelb\Aigelb\Service\LanguageService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * AI Chatbot Controller
 *
 * Simplified controller that delegates conversation management
 * to the ConversationService for better separation of concerns.
 */
final class AIChatbotController extends ActionController
{
    /**
     * Constructor with dependency injection
     */
    public function __construct(
        private readonly AIGelbService $aIGelbService,
        private readonly ConversationService $conversationService,
        private readonly LanguageService $languageService,
    ) {}

    /**
     * Main chatbot action
     */
    public function chatbotAction(): ResponseInterface
    {
        // Get agent configuration from FlexForm
        $selectedAgentId = $this->resolveSelectedAgent();

        // Handle special actions first
        if ($redirectResponse = $this->handleSpecialActions($selectedAgentId)) {
            return $redirectResponse;
        }

        // Get current conversation
        $submittedConversationId = $this->request->hasArgument('conversationId')
            ? (string)$this->request->getArgument('conversationId')
            : null;

        $conversationId = $this->conversationService->getCurrentConversationId(
            $selectedAgentId,
            $submittedConversationId
        );

        // Assign basic template variables
        $this->assignBasicTemplateVariables($selectedAgentId, $conversationId);

        // Process user input if submitted
        if ($this->request->hasArgument('userinput')) {
            $this->processUserInput($selectedAgentId, $conversationId);
        }

        return $this->htmlResponse();
    }

    /**
     * Resolve selected agent ID from FlexForm settings
     */
    private function resolveSelectedAgent(): string
    {
        $selectedAgentUid = (int)($this->settings['agent'] ?? 0);

        if ($selectedAgentUid > 0) {
            $selectedAgentId = $this->aIGelbService->getAgentIdByUid($selectedAgentUid);
            if (!empty($selectedAgentId)) {
                return $selectedAgentId;
            }
        }

        return $this->aIGelbService->getAgentId();
    }

    /**
     * Handle special actions (new conversation, select conversation, delete)
     */
    private function handleSpecialActions(string $selectedAgentId): ?ResponseInterface
    {
        // Handle conversation selection
        if ($this->request->hasArgument('selectConversation')) {
            $conversationId = (string)$this->request->getArgument('selectConversation');
            $this->conversationService->selectConversation($selectedAgentId, $conversationId);
            return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
        }

        // Handle new conversation request
        if ($this->request->hasArgument('newConversation') && $this->request->getArgument('newConversation') === '1') {
            $this->conversationService->createNewConversation($selectedAgentId);
            return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
        }

        // Handle delete conversation request
        if ($this->request->hasArgument('deleteConversation')) {
            $conversationToDelete = (string)$this->request->getArgument('deleteConversation');
            $this->conversationService->deleteConversation($selectedAgentId, $conversationToDelete);
            return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
        }

        // Handle clear history request (debugging/maintenance)
        if ($this->request->hasArgument('clearHistory') && $this->request->getArgument('clearHistory') === 'confirm') {
            $this->conversationService->clearConversationHistory($selectedAgentId);
            return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
        }

        return null;
    }

    /**
     * Assign basic template variables
     */
    private function assignBasicTemplateVariables(string $selectedAgentId, string $conversationId): void
    {
        // FlexForm settings
        $this->view->assign('settings', $this->settings);

        // Conversation data
        $this->view->assign('conversationId', $conversationId);
        $this->view->assign('selectedAgentId', $selectedAgentId);
        $this->view->assign('currentConversationId', $conversationId);

        // Conversation history for sidebar
        $conversationHistory = $this->conversationService->getConversationHistory($selectedAgentId);
        $this->view->assign('conversationHistory', $conversationHistory);

        // Load existing conversation messages
        $conversationMessages = $this->conversationService->getConversationMessages($conversationId);
        $this->view->assign('conversationMessages', $conversationMessages);

        // Load predefined questions
        $selectedAgentUid = (int)($this->settings['agent'] ?? 0);
        $questions = $this->aIGelbService->getPredefinedQuestions($selectedAgentUid);
        $this->view->assign('questions', $questions);
    }

    /**
     * Process user input and get AI response
     */
    private function processUserInput(string $selectedAgentId, string $conversationId): void
    {
        $userInput = trim((string)$this->request->getArgument('userinput'));

        // Validate user input
        if (empty($userInput)) {
            $this->view->assign('hasError', true);
            $this->view->assign('errorMessage', 'Please provide a valid input.');
            return;
        }

        // Get language for AI request
        $language = $this->languageService->getSupportedLanguageOrFallback($this->request);

        // Send user input to AI and get response
        $response = $this->aIGelbService->streamAgent(
            $userInput,
            $language,
            $conversationId,
            $selectedAgentId
        );

        // Handle response
        if (!empty($response)) {
            // Update conversation history
            $this->conversationService->updateConversationHistory(
                $selectedAgentId,
                $conversationId,
                $userInput
            );

            // Assign success response to template
            $this->view->assign('hasResponse', true);
            $this->view->assign('userInput', $userInput);
            $this->view->assign('aiResponse', $response);
        } else {
            // Handle API error
            $this->view->assign('hasError', true);
            $this->view->assign('errorMessage', 'Sorry, I could not process your request at the moment. Please try again.');
        }
    }
}
