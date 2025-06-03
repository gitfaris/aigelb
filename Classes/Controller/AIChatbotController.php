<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Controller;

use IGelb\Aigelb\Service\AIGelbService;
use IGelb\Aigelb\Service\LanguageService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * AI Chatbot Controller with Cookie-based Conversation History
 *
 * Enhanced version that stores conversation history in cookies
 * allowing users to return to previous conversations.
 */
final class AIChatbotController extends ActionController
{
    private const SESSION_KEY_CONVERSATION_ID = 'aigelb_conversation_id';
    private const COOKIE_PREFIX = 'aigelb_conversations_';
    private const COOKIE_LIFETIME = 30 * 24 * 60 * 60; // 30 days
    private const MAX_CONVERSATIONS_PER_AGENT = 10; // Limit stored conversations

    /**
     * Constructor with dependency injection
     */
    public function __construct(
        private readonly AIGelbService $aIGelbService,
        private readonly LanguageService $languageService,
    ) {}

    /**
     * Main chatbot action with conversation history management
     */
    public function chatbotAction(): ResponseInterface
    {
        // FlexForm settings are automatically available in $this->settings
        $this->view->assign('settings', $this->settings);

        // Get selected agent from FlexForm settings and resolve agent ID
        $selectedAgentUid = (int)($this->settings['agent'] ?? 0);
        $selectedAgentId = '';

        if ($selectedAgentUid > 0) {
            $selectedAgentId = $this->aIGelbService->getAgentIdByUid($selectedAgentUid);
        }

        // If no agent selected, use default agent
        if (empty($selectedAgentId)) {
            $selectedAgentId = $this->aIGelbService->getAgentId();
        }

        // Start session if not already started
        $this->ensureSessionStarted();

        // Handle conversation selection from history
        $selectedConversationId = '';
        if ($this->request->hasArgument('selectConversation')) {
            $selectedConversationId = (string)$this->request->getArgument('selectConversation');
            if ($this->isValidConversationForAgent($selectedConversationId, $selectedAgentId)) {
                $this->setCurrentConversationId($selectedAgentId, $selectedConversationId);
            }
        }

        // Get conversation ID from session/cookie or create new one
        $conversationId = $this->getCurrentConversationId($selectedAgentId);

        $this->view->assign('conversationId', $conversationId);
        $this->view->assign('selectedAgentId', $selectedAgentId);

        // Load conversation history from cookies for sidebar display
        $conversationHistory = $this->getConversationHistory($selectedAgentId);
        $this->view->assign('conversationHistory', $conversationHistory);
        $this->view->assign('currentConversationId', $conversationId);

        // Load predefined questions for quick selection UI
        $questions = $this->getQuestions($selectedAgentUid);
        $this->view->assign('questions', $questions);

        // Load existing conversation messages if available
        $conversationMessages = [];
        if (!empty($conversationId)) {
            $conversationMessages = $this->aIGelbService->getConversationMessages($conversationId);
        }
        $this->view->assign('conversationMessages', $conversationMessages);

        // Process user input if form was submitted
        if ($this->request->hasArgument('userinput')) {
            $userInput = trim((string)$this->request->getArgument('userinput'));

            // Validate user input
            if (empty($userInput)) {
                $this->view->assign('hasError', true);
                $this->view->assign('errorMessage', 'Please provide a valid input.');
                return $this->htmlResponse();
            }

            // Get appropriate language from current site context
            $language = $this->languageService->getSupportedLanguageOrFallback($this->request);

            // Send user input to AI and get response
            $response = $this->aIGelbService->streamAgent(
                $userInput,
                $language,
                $conversationId,
                $selectedAgentId
            );

            // Update conversation history in cookies after successful interaction
            if (!empty($response)) {
                $this->updateConversationHistory($selectedAgentId, $conversationId, $userInput);
            }

            // Assign current interaction data to template
            $this->view->assign('hasResponse', true);
            $this->view->assign('userInput', $userInput);
            $this->view->assign('aiResponse', $response);
        }

        // Handle new conversation request
        if ($this->request->hasArgument('newConversation') && $this->request->getArgument('newConversation') === '1') {
            $this->startNewConversation($selectedAgentId);
            return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
        }

        // Handle delete conversation request
        if ($this->request->hasArgument('deleteConversation')) {
            $conversationToDelete = (string)$this->request->getArgument('deleteConversation');
            $this->deleteConversationFromHistory($selectedAgentId, $conversationToDelete);

            // If we deleted the current conversation, start a new one
            if ($conversationToDelete === $conversationId) {
                $this->startNewConversation($selectedAgentId);
            }

            return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
        }

        return $this->htmlResponse();
    }

    /**
     * Get current conversation ID from session/cookie or create new one
     */
    private function getCurrentConversationId(string $agentId): string
    {
        $sessionKey = $this->getAgentSpecificSessionKey($agentId);

        // Check if conversation ID is passed as argument (form submission)
        if ($this->request->hasArgument('conversationId')) {
            $conversationId = (string)$this->request->getArgument('conversationId');

            if ($this->isConversationValidForAgent($conversationId, $agentId)) {
                $_SESSION[$sessionKey] = $conversationId;
                return $conversationId;
            }
        }

        // Check session for existing conversation
        if (!empty($_SESSION[$sessionKey])) {
            $existingConversationId = $_SESSION[$sessionKey];
            if ($this->isConversationValidForAgent($existingConversationId, $agentId)) {
                return $existingConversationId;
            }
        }

        // Create new conversation and store in session
        $conversationId = $this->aIGelbService->createConversation($agentId);
        $_SESSION[$sessionKey] = $conversationId;

        return $conversationId;
    }

    /**
     * Set current conversation ID in session
     */
    private function setCurrentConversationId(string $agentId, string $conversationId): void
    {
        $this->ensureSessionStarted();
        $sessionKey = $this->getAgentSpecificSessionKey($agentId);
        $_SESSION[$sessionKey] = $conversationId;
    }

    /**
     * Start a new conversation for specific agent
     */
    private function startNewConversation(string $agentId): void
    {
        $this->ensureSessionStarted();
        $sessionKey = $this->getAgentSpecificSessionKey($agentId);

        // Create new conversation
        $newConversationId = $this->aIGelbService->createConversation($agentId);
        $_SESSION[$sessionKey] = $newConversationId;

        // Initialize conversation in history with empty title (will be updated on first message)
        $this->initializeConversationInHistory($agentId, $newConversationId);
    }

    /**
     * Get conversation history from cookies for specific agent
     *
     * @return array<string, array<string, mixed>> Conversation history indexed by conversation ID
     */
    private function getConversationHistory(string $agentId): array
    {
        $cookieName = $this->getAgentSpecificCookieName($agentId);
        $cookieValue = $_COOKIE[$cookieName] ?? '';

        if (empty($cookieValue)) {
            return [];
        }

        $decodedData = json_decode(base64_decode($cookieValue), true);
        if (!is_array($decodedData)) {
            return [];
        }

        // Sort by last activity (newest first)
        uasort($decodedData, function ($a, $b) {
            return ($b['lastActivity'] ?? 0) <=> ($a['lastActivity'] ?? 0);
        });

        return $decodedData;
    }

    /**
     * Update conversation history in cookies
     */
    private function updateConversationHistory(string $agentId, string $conversationId, string $lastMessage): void
    {
        $history = $this->getConversationHistory($agentId);

        // Generate title from first message if not exists
        $title = $history[$conversationId]['title'] ?? $this->generateConversationTitle($lastMessage);

        // Update or create conversation entry
        $history[$conversationId] = [
            'id' => $conversationId,
            'title' => $title,
            'lastActivity' => time(),
            'lastMessage' => mb_substr($lastMessage, 0, 100), // Store preview
            'messageCount' => ($history[$conversationId]['messageCount'] ?? 0) + 1,
        ];

        // Limit number of stored conversations
        if (count($history) > self::MAX_CONVERSATIONS_PER_AGENT) {
            // Keep only the most recent conversations
            $history = array_slice($history, 0, self::MAX_CONVERSATIONS_PER_AGENT, true);
        }

        $this->saveConversationHistory($agentId, $history);
    }

    /**
     * Initialize new conversation in history
     */
    private function initializeConversationInHistory(string $agentId, string $conversationId): void
    {
        $history = $this->getConversationHistory($agentId);

        $history[$conversationId] = [
            'id' => $conversationId,
            'title' => 'New Conversation',
            'lastActivity' => time(),
            'lastMessage' => '',
            'messageCount' => 0,
        ];

        $this->saveConversationHistory($agentId, $history);
    }

    /**
     * Delete conversation from history
     */
    private function deleteConversationFromHistory(string $agentId, string $conversationId): void
    {
        $history = $this->getConversationHistory($agentId);
        unset($history[$conversationId]);
        $this->saveConversationHistory($agentId, $history);
    }

    /**
     * Save conversation history to cookies
     *
     * @param array<string, array<string, mixed>> $history
     */
    private function saveConversationHistory(string $agentId, array $history): void
    {
        $cookieName = $this->getAgentSpecificCookieName($agentId);
        $cookieValue = base64_encode(json_encode($history));

        // Set cookie with secure settings
        setcookie(
            $cookieName,
            $cookieValue,
            [
                'expires' => time() + self::COOKIE_LIFETIME,
                'path' => '/',
                'secure' => $this->request->getAttribute('normalizedParams')->isHttps(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }

    /**
     * Generate conversation title from first message
     */
    private function generateConversationTitle(string $message): string
    {
        // Clean and truncate message for title
        $title = trim(strip_tags($message));
        $title = mb_substr($title, 0, 50);

        // Add ellipsis if truncated
        if (mb_strlen($title) === 50) {
            $title .= '...';
        }

        return $title ?: 'New Conversation';
    }

    /**
     * Generate agent-specific cookie name
     */
    private function getAgentSpecificCookieName(string $agentId): string
    {
        $agentHash = substr(md5($agentId), 0, 8);
        return self::COOKIE_PREFIX . $agentHash;
    }

    /**
     * Generate agent-specific session key
     */
    private function getAgentSpecificSessionKey(string $agentId): string
    {
        $agentHash = substr(md5($agentId), 0, 8);
        return self::SESSION_KEY_CONVERSATION_ID . '_agent_' . $agentHash;
    }

    /**
     * Validate if a conversation ID is valid for the given agent
     */
    private function isValidConversationForAgent(string $conversationId, string $agentId): bool
    {
        $history = $this->getConversationHistory($agentId);
        return isset($history[$conversationId]);
    }

    /**
     * Validate if a conversation ID belongs to a specific agent
     */
    private function isConversationValidForAgent(string $conversationId, string $agentId): bool
    {
        return !empty($conversationId) && !empty($agentId);
    }

    /**
     * Ensure PHP session is started
     */
    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Load predefined questions from database for specific agent
     */
    protected function getQuestions(int $agentUid = null): array
    {
        return $this->aIGelbService->getPredefinedQuestions($agentUid);
    }

    /**
     * Clear all conversation history for debugging/maintenance
     */
    private function clearAllConversationHistory(): void
    {
        // This method can be called via a special parameter for maintenance
        if ($this->request->hasArgument('clearHistory') && $this->request->getArgument('clearHistory') === 'confirm') {
            foreach ($_COOKIE as $cookieName => $cookieValue) {
                if (str_starts_with($cookieName, self::COOKIE_PREFIX)) {
                    setcookie($cookieName, '', time() - 3600, '/');
                }
            }
        }
    }
}
