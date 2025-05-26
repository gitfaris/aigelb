<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;

#[Channel('aigelb-service')]
final readonly class AIGelbService {
    private const API_TOKEN_ENV = 'AIGELB_API_TOKEN';
    private const API_URL_ENV = 'AIGELB_API_URL';
    private const RAG_URL_ENV = 'AIGELB_RAG_URL';
    private const AGENT_ID_ENV = 'AIGELB_AGENTID';

    public function __construct(
        protected readonly LoggerInterface $logger,
        private readonly ConnectionPool $connectionPool,
        protected readonly RequestFactory $requestFactory,
    ) {}

    private function getApiToken(): string {
        $token = getenv(self::API_TOKEN_ENV);
        if (!$token) {
            throw new \RuntimeException('API Token not found in environment variables');
        }
        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function getDefaultHeaders(): array {
        return [
            'Authorization' => 'Bearer ' . $this->getApiToken(),
            'Content-Type' => 'application/json',
        ];
    }

    private function sendApiRequest(string $url, string $method, array $data = null): ResponseInterface { // @phpstan-ignore-line
        $options = [
            'headers' => $this->getDefaultHeaders(),
            'body' => json_encode($data),
        ];

        return $this->requestFactory->request($url, $method, $options);
    }

    /**
     * Get the current agent ID from database or environment
     *
     * @return string Agent ID
     */
    public function getAgentId(): string
    {
        // Priority: Environment variable first, then database
        $envAgentId = getenv(self::AGENT_ID_ENV);
        if ($envAgentId !== false && !empty($envAgentId)) {
            return $envAgentId;
        }

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
     * Creates a new conversation id
     *
     * @return string The conversation ID or empty string on failure
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
     * Stream agent response with automatic agent ID resolution
     *
     * @param string $userInput User question/input
     * @param string $language Language locale (e.g., 'de-DE')
     * @param string $conversationId Conversation identifier
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
     * Sends a message to the AI agent and streams the response
     *
     * @param string $agentId The unique identifier of the agent
     * @param string $userInput The user message to send
     * @param string $language The language locale (e.g., 'en-US', 'de-DE')
     * @param string $conversationId The conversation ID to maintain context
     * @return string The streamed AI response
     */
    private function streamAgentWithId(string $agentId, string $userInput, string $language, string $conversationId): string
    {
        try {
            // Use the correct API URL from documentation
            $apiUrl = getenv(self::API_URL_ENV) . '/api/stream/' . $agentId;

            // Build request data according to API specification
            $data = [
                'message' => $userInput,
                'language' => $language,
                'headlessAgentId' => $this->getAgentId(),
                'conversation_id' => $conversationId,
            ];

            $response = $this->sendApiRequest($apiUrl, 'POST', $data);

            $stream = $response->getBody();
            $result = '';

            // Stream the response content
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
     * Retrieves all messages from a specific conversation
     *
     * @param string $conversationId The unique identifier of the conversation
     * @return array<int, array<string, mixed>> Array of message objects or empty array on failure
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
     * Get predefined questions from database
     *
     * @return array<int, array<string, mixed>>
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
