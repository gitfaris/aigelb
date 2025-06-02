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
     * Get default agent ID with priority: Environment > Database
     *
     * This is used as fallback when no specific agent ID is provided
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
     * Get agent ID by database UID (for FlexForm selection)
     *
     * @param int $agentUid Database UID of the agent
     * @return string Agent ID or empty string if not found
     */
    public function getAgentIdByUid(int $agentUid): string
    {
        if ($agentUid <= 0) {
            return '';
        }

        $result = $this->connectionPool
            ->getConnectionForTable('tx_aigelb_domain_model_agent')
            ->select(
                ['tx_aigelb_agentid'],
                'tx_aigelb_domain_model_agent',
                ['uid' => $agentUid]
            )
            ->fetchAssociative();

        return $result['tx_aigelb_agentid'] ?? '';
    }

    /**
     * Create new conversation with AI-Gelb API
     *
     * @param string|null $agentId Optional specific agent ID, falls back to default if not provided
     * @return string Conversation ID or empty string on failure
     */
    public function createConversation(string $agentId = null): string {
        try {
            $apiUrl = getenv(self::API_URL_ENV) . '/api/conversation';

            // Use provided agent ID or fall back to default
            $effectiveAgentId = $agentId ?: $this->getAgentId();

            $data = [
                'headlessAgentId' => $effectiveAgentId
            ];

            $response = $this->sendApiRequest($apiUrl, 'POST', $data);
            $contents = json_decode($response->getBody()->getContents());

            $this->logger->info('Conversation created successfully', [
                'agentId' => $effectiveAgentId,
                'conversationId' => $contents->conversation_id
            ]);

            return $contents->conversation_id;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create conversation', [
                'agentId' => $agentId ?: $this->getAgentId(),
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Send user input to AI agent and get streaming response
     *
     * @param string $userInput User question/message
     * @param string $language Language locale (e.g., 'de-DE')
     * @param string $conversationId Conversation context ID
     * @param string|null $agentId Optional specific agent ID from FlexForm, uses default if not provided
     * @return string AI response
     */
    public function streamAgent(
        string $userInput,
        string $language,
        string $conversationId,
        string $agentId = null
    ): string {
        // Use provided agent ID or fall back to default
        $effectiveAgentId = $agentId ?: $this->getAgentId();

        try {
            $apiUrl = getenv(self::API_URL_ENV) . '/api/stream/' . $effectiveAgentId;

            $data = [
                'message' => $userInput,
                'language' => $language,
                'headlessAgentId' => $effectiveAgentId,
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
                'agentId' => $effectiveAgentId,
                'conversationId' => $conversationId,
                'messageLength' => strlen($userInput),
                'responseLength' => strlen($result),
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to stream agent response', [
                'agentId' => $effectiveAgentId,
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

    // ... rest of the methods remain unchanged (addKnowledge, deleteKnowledge, etc. for command use)
}
