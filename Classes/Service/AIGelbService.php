<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * AI-Gelb Service Class
 *
 * Core service for AI-Gelb TYPO3 Extension providing communication with AI-Gelb API.
 * Handles agent management, conversations, and AI interactions.
 */
#[Channel('aigelb-service')]
final readonly class AIGelbService {

    // Environment variable names for configuration
    private const API_TOKEN_ENV = 'AIGELB_API_TOKEN';
    private const API_URL_ENV = 'AIGELB_API_URL';
    private const RAG_URL_ENV = 'AIGELB_RAG_URL';
    private const AGENT_ID_ENV = 'AIGELB_AGENTID';

    /**
     * Constructor with dependency injection
     */
    public function __construct(
        protected readonly LoggerInterface $logger,
        private readonly ConnectionPool $connectionPool,
        protected readonly RequestFactory $requestFactory,
    ) {}

    /**
     * Get API token from environment variables
     *
     * @throws \RuntimeException If token not found
     */
    private function getApiToken(): string {
        $token = getenv(self::API_TOKEN_ENV);
        if (!$token) {
            throw new \RuntimeException('API Token not found in environment variables');
        }
        return $token;
    }

    /**
     * Create standard HTTP headers for API requests
     *
     * @return array<string, string> HTTP headers with authorization and content type
     */
    private function getDefaultHeaders(): array {
        return [
            'Authorization' => 'Bearer ' . $this->getApiToken(),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Send HTTP request to AI-Gelb API
     *
     * @param string $url API endpoint URL
     * @param string $method HTTP method
     * @param array|null $data Request body data (JSON encoded)
     * @return ResponseInterface PSR-7 response
     */
    private function sendApiRequest(string $url, string $method, array $data = null): ResponseInterface {
        $options = [
            'headers' => $this->getDefaultHeaders(),
            'body' => json_encode($data),
        ];

        return $this->requestFactory->request($url, $method, $options);
    }

    /**
     * Get current agent ID with priority: Environment > Database
     *
     * @return string Agent ID or empty string if not found
     */
    public function getAgentId(): string
    {
        // Priority 1: Check environment variable
        $envAgentId = getenv(self::AGENT_ID_ENV);
        if ($envAgentId !== false && !empty($envAgentId)) {
            return $envAgentId;
        }

        // Priority 2: Database fallback
        $result = $this->connectionPool
            ->getConnectionForTable('tx_aigelb_domain_model_agent')
            ->select(
                ['tx_aigelb_agentid'],
                'tx_aigelb_domain_model_agent',
                []
            )
            ->fetchAssociative();

        return $result['tx_aigelb_agentid'] ?? '';
    }

    /**
     * Create new conversation with AI-Gelb API
     *
     * @return string Conversation ID or empty string on failure
     */
    public function createConversation(): string {
        try {
            $apiUrl = getenv(self::API_URL_ENV) . '/api/conversation';

            $data = [
                'headlessAgentId' => $this->getAgentId()
            ];

            $response = $this->sendApiRequest($apiUrl, 'POST', $data);
            $contents = json_decode($response->getBody()->getContents());

            $this->logger->info('Conversation created successfully', [
                'agentId' => $this->getAgentId(),
                'conversationId' => $contents->conversation_id
            ]);

            return $contents->conversation_id;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create conversation', [
                'agentId' => $this->getAgentId(),
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Send user input to AI agent (public interface)
     *
     * @param string $userInput User question/message
     * @param string $language Language locale (e.g., 'de-DE')
     * @param string $conversationId Conversation context ID
     * @return string AI response
     */
    public function streamAgent(
        string $userInput,
        string $language,
        string $conversationId
    ): string {
        $agentId = $this->getAgentId();

        return $this->streamAgentWithId($agentId, $userInput, $language, $conversationId);
    }

    /**
     * Internal implementation for streaming AI responses
     *
     * Sends request to streaming endpoint and processes chunked response.
     *
     * @param string $agentId Unique agent identifier
     * @param string $userInput User message
     * @param string $language Response language
     * @param string $conversationId Conversation context
     * @return string Complete AI response
     */
    private function streamAgentWithId(string $agentId, string $userInput, string $language, string $conversationId): string
    {
        try {
            $apiUrl = getenv(self::API_URL_ENV) . '/api/stream/' . $agentId;

            $data = [
                'message' => $userInput,
                'language' => $language,
                'headlessAgentId' => $this->getAgentId(),
                'conversation_id' => $conversationId,
            ];

            $response = $this->sendApiRequest($apiUrl, 'POST', $data);

            // Process streaming response in chunks
            $stream = $response->getBody();
            $result = '';

            while (!$stream->eof()) {
                $chunk = $stream->read(4096);
                if ($chunk !== '') {
                    $result .= $chunk;
                }
            }

            $this->logger->info('AI agent response received successfully', [
                'agentId' => $agentId,
                'conversationId' => $conversationId,
                'messageLength' => strlen($userInput),
                'responseLength' => strlen($result),
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to stream agent response', [
                'agentId' => $agentId,
                'conversationId' => $conversationId,
                'message' => $userInput,
                'language' => $language,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Retrieve all messages from a conversation
     *
     * @param string $conversationId Unique conversation identifier
     * @return array<int, array<string, mixed>> Array of message objects
     */
    public function getConversationMessages(string $conversationId): array
    {
        try {
            $apiUrl = getenv(self::API_URL_ENV) . '/api/conversation/' . $conversationId . '/messages';

            $response = $this->sendApiRequest($apiUrl, 'GET');

            $contents = $response->getBody()->getContents();
            $messages = json_decode($contents, true);

            if (!is_array($messages)) {
                $this->logger->warning('Invalid response format for conversation messages', [
                    'conversationId' => $conversationId,
                    'response' => $contents,
                ]);
                return [];
            }

            $this->logger->info('Conversation messages retrieved successfully', [
                'conversationId' => $conversationId,
                'messageCount' => count($messages),
            ]);

            return $messages;
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve conversation messages', [
                'conversationId' => $conversationId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Load predefined questions from TYPO3 database
     *
     * Enables backend-managed quick selection buttons in frontend.
     *
     * @return array<int, array<string, mixed>> Array of question objects
     */
    public function getPredefinedQuestions(): array
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
}
