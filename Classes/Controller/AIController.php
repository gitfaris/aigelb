<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Controller;

use IGelb\Aigelb\Service\AIGelbService;
use IGelb\Aigelb\Service\LanguageService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * AI Controller for handling chatbot interactions
 *
 * Frontend controller managing chat interface, conversation handling,
 * and user interactions with AI-Gelb service.
 */
final class AIController extends ActionController
{
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
        // Initialize or retrieve conversation ID for session continuity
        $conversationId = $this->request->hasArgument('conversationId')
            ? (string)$this->request->getArgument('conversationId')
            : $this->aIGelbService->createConversation();

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

        return $this->htmlResponse();
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
