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
 * Enhanced controller with agent and content element isolation
 * to support multiple chatbots on the same page.
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
     * Main chatbot action with enhanced isolation
     */
    public function chatbotAction(): ResponseInterface
    {
        // Get content element UID for isolation
        $contentElementUid = $this->getContentElementUid();

        // Get agent configuration from FlexForm
        $selectedAgentId = $this->resolveSelectedAgent();

        // Create unique identifier for this chatbot instance
        $chatbotInstanceId = $this->generateChatbotInstanceId($selectedAgentId, $contentElementUid);

        // Handle special actions first
        if ($redirectResponse = $this->handleSpecialActions($selectedAgentId, $contentElementUid)) {
            return $redirectResponse;
        }

        // Get current conversation with content element isolation
        $submittedConversationId = $this->request->hasArgument('conversationId')
            ? (string)$this->request->getArgument('conversationId')
            : null;

        $conversationId = $this->conversationService->getCurrentConversationId(
            $selectedAgentId,
            $submittedConversationId,
            $contentElementUid
        );

        // Assign basic template variables
        $this->assignBasicTemplateVariables($selectedAgentId, $conversationId, $chatbotInstanceId, $contentElementUid);

        // Process user input if submitted and it belongs to this instance
        if ($this->shouldProcessUserInput($chatbotInstanceId)) {
            $this->processUserInput($selectedAgentId, $conversationId);
        }

        return $this->htmlResponse();
    }

    /**
     * Get content element UID from request/context
     */
    private function getContentElementUid(): ?int
    {
        // Try to get from configurationManager
        $contentObject = $this->configurationManager->getContentObject();
        if ($contentObject !== null) {
            $contentElementUid = (int)$contentObject->data['uid'];
            if ($contentElementUid > 0) {
                return $contentElementUid;
            }
        }

        // Fallback: try to get from request if passed as hidden field
        if ($this->request->hasArgument('contentElementUid')) {
            $contentElementUid = (int)$this->request->getArgument('contentElementUid');
            if ($contentElementUid > 0) {
                return $contentElementUid;
            }
        }

        return null;
    }

    /**
     * Generate unique chatbot instance ID
     */
    private function generateChatbotInstanceId(string $agentId, ?int $contentElementUid): string
    {
        $components = [$agentId];

        if ($contentElementUid !== null) {
            $components[] = 'ce_' . $contentElementUid;
        }

        return md5(implode('_', $components));
    }

    /**
     * Check if this request should be processed by this chatbot instance
     */
    private function shouldProcessUserInput(string $chatbotInstanceId): bool
    {
        if (!$this->request->hasArgument('userinput')) {
            return false;
        }

        // Check if this request is for this specific chatbot instance
        $submittedInstanceId = $this->request->hasArgument('chatbotInstanceId')
            ? (string)$this->request->getArgument('chatbotInstanceId')
            : '';

        return $submittedInstanceId === $chatbotInstanceId;
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
    private function handleSpecialActions(string $selectedAgentId, ?int $contentElementUid): ?ResponseInterface
    {
        $chatbotInstanceId = $this->generateChatbotInstanceId($selectedAgentId, $contentElementUid);

        // Handle conversation selection - only if it's for this specific instance
        if ($this->request->hasArgument('selectConversation')) {
            $requestedInstanceId = $this->request->hasArgument('chatbotInstanceId')
                ? (string)$this->request->getArgument('chatbotInstanceId')
                : '';

            if ($requestedInstanceId === $chatbotInstanceId) {
                $conversationId = (string)$this->request->getArgument('selectConversation');
                $this->conversationService->selectConversation($selectedAgentId, $conversationId, $contentElementUid);
                return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
            }
        }

        // Handle new conversation request - only if it's for this specific instance
        if ($this->request->hasArgument('newConversation') && $this->request->getArgument('newConversation') === '1') {
            $requestedInstanceId = $this->request->hasArgument('chatbotInstanceId')
                ? (string)$this->request->getArgument('chatbotInstanceId')
                : '';

            if ($requestedInstanceId === $chatbotInstanceId) {
                $this->conversationService->createNewConversation($selectedAgentId, $contentElementUid);
                return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
            }
        }

        // Handle delete conversation request - only if it's for this specific instance
        if ($this->request->hasArgument('deleteConversation')) {
            $requestedInstanceId = $this->request->hasArgument('chatbotInstanceId')
                ? (string)$this->request->getArgument('chatbotInstanceId')
                : '';

            if ($requestedInstanceId === $chatbotInstanceId) {
                $conversationToDelete = (string)$this->request->getArgument('deleteConversation');
                $this->conversationService->deleteConversation($selectedAgentId, $conversationToDelete, $contentElementUid);
                return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
            }
        }

        // Handle clear history request (debugging/maintenance) - only if it's for this specific instance
        if ($this->request->hasArgument('clearHistory') && $this->request->getArgument('clearHistory') === 'confirm') {
            $requestedInstanceId = $this->request->hasArgument('chatbotInstanceId')
                ? (string)$this->request->getArgument('chatbotInstanceId')
                : '';

            if ($requestedInstanceId === $chatbotInstanceId) {
                $this->conversationService->clearConversationHistory($selectedAgentId, $contentElementUid);
                return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
            }
        }

        return null;
    }

    /**
     * Assign basic template variables
     */
    private function assignBasicTemplateVariables(
        string $selectedAgentId,
        string $conversationId,
        string $chatbotInstanceId,
        ?int $contentElementUid
    ): void {
        // FlexForm settings
        $this->view->assign('settings', $this->settings);

        // Conversation data
        $this->view->assign('conversationId', $conversationId);
        $this->view->assign('selectedAgentId', $selectedAgentId);
        $this->view->assign('currentConversationId', $conversationId);
        $this->view->assign('chatbotInstanceId', $chatbotInstanceId);
        $this->view->assign('contentElementUid', $contentElementUid);

        // Conversation history for sidebar (agent-specific)
        $conversationHistory = $this->conversationService->getConversationHistory($selectedAgentId);
        $this->view->assign('conversationHistory', $conversationHistory);

        // Load existing conversation messages
        $conversationMessages = $this->conversationService->getConversationMessages($conversationId);
        $this->view->assign('conversationMessages', $conversationMessages);

        // Load predefined questions (agent-specific)
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
