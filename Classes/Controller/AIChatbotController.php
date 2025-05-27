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
     * - Creates/maintains conversation sessions
     * - Loads conversation history and predefined questions
     * - Processes user input and generates AI responses
     * - Handles error states and validation
     *
     * @return ResponseInterface HTML response with chat interface
     */
    public function chatbotAction(): ResponseInterface
    {
        // Start session if not already started
        $this->ensureSessionStarted();

        // Get conversation ID from session or create new one
        $conversationId = $this->getCurrentConversationId();

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

            // Send user input to AI and get response
            $response = $this->aIGelbService->streamAgent(
                $userInput,
                $language,
                $conversationId
            );

            // Assign current interaction data to template
            $this->view->assign('hasResponse', true);
            $this->view->assign('userInput', $userInput);
            $this->view->assign('aiResponse', $response);
        }

        // Handle new conversation request (clear session)
        if ($this->request->hasArgument('newConversation') && $this->request->getArgument('newConversation') === '1') {
            $this->startNewConversation();
            // Redirect to avoid form resubmission
            return $this->redirectToUri($this->uriBuilder->uriFor('chatbot'));
        }

        return $this->htmlResponse();
    }

    /**
     * Get current conversation ID from session or create new one
     *
     * @return string Conversation ID
     */
    private function getCurrentConversationId(): string
    {
        // Check if conversation ID is passed as argument (form submission)
        if ($this->request->hasArgument('conversationId')) {
            $conversationId = (string)$this->request->getArgument('conversationId');
            // Store in session for persistence
            $_SESSION[self::SESSION_KEY_CONVERSATION_ID] = $conversationId;
            return $conversationId;
        }

        // Check session for existing conversation
        if (!empty($_SESSION[self::SESSION_KEY_CONVERSATION_ID])) {
            return $_SESSION[self::SESSION_KEY_CONVERSATION_ID];
        }

        // Create new conversation and store in session
        $conversationId = $this->aIGelbService->createConversation();
        $_SESSION[self::SESSION_KEY_CONVERSATION_ID] = $conversationId;

        return $conversationId;
    }

    /**
     * Start a new conversation (clear session)
     */
    private function startNewConversation(): void
    {
        $this->ensureSessionStarted();

        // Remove conversation ID from session
        unset($_SESSION[self::SESSION_KEY_CONVERSATION_ID]);

        // Create new conversation immediately
        $newConversationId = $this->aIGelbService->createConversation();
        $_SESSION[self::SESSION_KEY_CONVERSATION_ID] = $newConversationId;
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
