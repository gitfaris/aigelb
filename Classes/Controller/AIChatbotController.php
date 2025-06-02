<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Controller;

use IGelb\Aigelb\Service\AIGelbService;
use IGelb\Aigelb\Service\LanguageService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * AI Chatbot Controller for handling chatbot interactions
 *
 * Frontend controller managing chat interface, conversation handling,
 * and user interactions with AI-Gelb service.
 */
final class AIChatbotController extends ActionController
{
    private const SESSION_KEY_CONVERSATION_ID = 'aigelb_conversation_id';

    /**
     * Constructor with dependency injection
     *
     * @param AIGelbService $aIGelbService Service for AI-Gelb API communication
     * @param LanguageService $languageService Service for language detection and handling
     */
    public function __construct(
        private readonly AIGelbService $aIGelbService,
        private readonly LanguageService $languageService,
    ) {}

    /**
     * Main chatbot action handling display and response logic
     *
     * Manages complete chatbot workflow:
     * - Creates/maintains conversation sessions per agent
     * - Loads conversation history and predefined questions
     * - Processes user input and generates AI responses
     * - Handles error states and validation
     *
     * @return ResponseInterface HTML response with chat interface
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

        // Get conversation ID from session or create new one (now agent-specific)
        $conversationId = $this->getCurrentConversationId($selectedAgentId);

        $this->view->assign('conversationId', $conversationId);

        // Load predefined questions for quick selection UI
        $questions = $this->getQuestions();
        $this->view->assign('questions', $questions);

        // Load existing conversation history if available
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

            // Send user input to AI and get response using the selected agent
            $response = $this->aIGelbService->streamAgent(
                $userInput,
                $language,
                $conversationId,
                $selectedAgentId // Pass the agent ID from FlexForm
            );

            // Assign current interaction data to template
            $this->view->assign('hasResponse', true);
            $this->view->assign('userInput', $userInput);
            $this->view->assign('aiResponse', $response);
        }

        // Handle new conversation request (clear session for specific agent)
        if ($this->request->hasArgument('newConversation') && $this->request->getArgument('newConversation') === '1') {
            $this->startNewConversation($selectedAgentId);
            // Redirect to avoid form resubmission
            return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
        }

        return $this->htmlResponse();
    }

    /**
     * Get current conversation ID from session or create new one
     * Now creates agent-specific session keys to separate conversations per agent
     *
     * @param string $agentId Agent ID to use for conversation creation
     * @return string Conversation ID
     */
    private function getCurrentConversationId(string $agentId): string
    {
        // Create a unique session key for this specific agent
        $sessionKey = $this->getAgentSpecificSessionKey($agentId);

        // Check if conversation ID is passed as argument (form submission)
        if ($this->request->hasArgument('conversationId')) {
            $conversationId = (string)$this->request->getArgument('conversationId');

            // Validate that this conversation ID belongs to the current agent
            if ($this->isConversationValidForAgent($conversationId, $agentId)) {
                // Store in session for persistence
                $_SESSION[$sessionKey] = $conversationId;
                return $conversationId;
            } else {
                // Invalid conversation for this agent, create new one
                unset($_SESSION[$sessionKey]);
            }
        }

        // Check session for existing conversation for this specific agent
        if (!empty($_SESSION[$sessionKey])) {
            $existingConversationId = $_SESSION[$sessionKey];

            // Validate that the stored conversation is still valid for this agent
            if ($this->isConversationValidForAgent($existingConversationId, $agentId)) {
                return $existingConversationId;
            } else {
                // Invalid stored conversation, remove it
                unset($_SESSION[$sessionKey]);
            }
        }

        // Create new conversation with specific agent and store in session
        $conversationId = $this->aIGelbService->createConversation($agentId);
        $_SESSION[$sessionKey] = $conversationId;

        return $conversationId;
    }

    /**
     * Generate agent-specific session key
     *
     * @param string $agentId Agent ID
     * @return string Session key unique to this agent
     */
    private function getAgentSpecificSessionKey(string $agentId): string
    {
        // Create a unique session key for this specific agent
        // Using short hash to keep session key reasonable length
        $agentHash = substr(md5($agentId), 0, 8);
        return self::SESSION_KEY_CONVERSATION_ID . '_agent_' . $agentHash;
    }

    /**
     * Validate if a conversation ID belongs to a specific agent
     *
     * This is a safety check to ensure conversations don't get mixed up between agents
     *
     * @param string $conversationId Conversation ID to validate
     * @param string $agentId Expected agent ID
     * @return bool True if conversation is valid for this agent
     */
    private function isConversationValidForAgent(string $conversationId, string $agentId): bool
    {
        // For now, we assume all non-empty conversation IDs are valid
        // You could extend this to make an API call to validate the conversation
        // against the agent if needed for additional security
        return !empty($conversationId) && !empty($agentId);
    }

    /**
     * Start a new conversation (clear session) for specific agent
     *
     * @param string $agentId Agent ID to create new conversation for
     */
    private function startNewConversation(string $agentId): void
    {
        $this->ensureSessionStarted();

        // Create a unique session key for this specific agent
        $sessionKey = $this->getAgentSpecificSessionKey($agentId);

        // Remove conversation ID from session for this specific agent
        unset($_SESSION[$sessionKey]);

        // Create new conversation immediately with specific agent
        $newConversationId = $this->aIGelbService->createConversation($agentId);
        $_SESSION[$sessionKey] = $newConversationId;
    }

    /**
     * Clear all conversations for all agents (optional utility method)
     */
    private function clearAllConversations(): void
    {
        $this->ensureSessionStarted();

        // Remove all conversation-related session keys
        foreach ($_SESSION as $key => $value) {
            if (str_starts_with($key, self::SESSION_KEY_CONVERSATION_ID)) {
                unset($_SESSION[$key]);
            }
        }
    }

    /**
     * Get active conversation count (optional utility method)
     *
     * @return int Number of active conversations across all agents
     */
    private function getActiveConversationCount(): int
    {
        $this->ensureSessionStarted();

        $count = 0;
        foreach ($_SESSION as $key => $value) {
            if (str_starts_with($key, self::SESSION_KEY_CONVERSATION_ID) && !empty($value)) {
                $count++;
            }
        }

        return $count;
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
     * Load predefined questions from database
     *
     * Retrieves backend-managed questions for frontend quick selection.
     *
     * @return array<int, array<string, mixed>> Array of question objects
     */
    protected function getQuestions(): array
    {
        return $this->aIGelbService->getPredefinedQuestions();
    }
}
