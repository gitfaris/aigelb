<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Controller;

use IGelb\Aigelb\Service\AIGelbService;
use IGelb\Aigelb\Service\LanguageService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * AI Controller for handling chatbot interactions
 */
final class AIController extends ActionController
{
    public function __construct(
        private readonly AIGelbService $aIGelbService,
        private readonly LanguageService $languageService,
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

            // Get language from language service
            $language = $this->languageService->getSupportedLanguageOrFallback($this->request);

            // Process AI response
            $response = $this->aIGelbService->streamAgent(
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
     * Get predefined questions from database
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getQuestions(): array
    {
        return $this->aIGelbService->getPredefinedQuestions();
    }
}
