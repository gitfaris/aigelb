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

    public function getAgentId(string $baseUrl): string {
        if ($agentId = getenv(self::AGENT_ID_ENV)) {
            return $agentId;
        }

        try {
            $apiUrl = getenv(self::API_URL_ENV) . '/api/agent';
            $response = $this->sendApiRequest($apiUrl, 'POST', ['url' => $baseUrl]);

            $contents = json_decode($response->getBody()->getContents());
            $this->logger->info('Agent ID fetched successfully', ['id' => $contents->id]);

            return $contents->id;
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch Agent ID', [
                'error' => $e->getMessage(),
                'baseUrl' => $baseUrl,
            ]);
            return '';
        }
    }

    public function addKnowledge(string $agentId, string $url, string $promptRequirements): string {
        try {
            $apiUrl = getenv(self::API_URL_ENV) . '/api/agent/' . $agentId . '/knowledge';
            $data = [
                'type' => 'page',
                'context' => $promptRequirements,
                'source' => $url,
            ];

            $response = $this->sendApiRequest($apiUrl, 'POST', $data);
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            $this->logger->error('Failed to add knowledge', [
                'agentId' => $agentId,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    public function deleteKnowledge(string $knowledgeId): string {
        try {
            $apiUrl = getenv(self::API_URL_ENV) . '/api/knowledge/' . $knowledgeId;
            $response = $this->sendApiRequest($apiUrl, 'DELETE');
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete knowledge', [
                'knowledgeId' => $knowledgeId,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
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
                'headlessAgentId' => getenv(self::AGENT_ID_ENV)
            ];

            $response = $this->sendApiRequest($apiUrl, 'POST', $data);
            $contents = json_decode($response->getBody()->getContents());

            $this->logger->info('Conversation created successfully', [
                'agentId' => getenv(self::AGENT_ID_ENV),
                'conversationId' => $contents->conversation_id
            ]);

            return $contents->conversation_id;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create conversation', [
                'agentId' => getenv(self::AGENT_ID_ENV),
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    public function streamAgent(string $agentId, string $message, string $language): string {
        try {
            $apiUrl = getenv(self::RAG_URL_ENV) . '/api/stream/' . $agentId;
            $data = [
                'message' => $message,
                'language' => $language,
            ];

            $response = $this->sendApiRequest($apiUrl, 'POST', $data);

            $stream = $response->getBody();
            $result = '';

            while (!$stream->eof()) {
                $chunk = $stream->read(4096);
                if ($chunk !== '') {
                    $result .= $chunk;
                }
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to stream agent', [
                'agentId' => $agentId,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    public function hasAgent(): string {
        $result = $this->connectionPool
            ->getConnectionForTable('tt_content')
            ->select(
                ['tx_aigelb_agentid'],
                'tx_aigelb_domain_model_agent',
                [],
            )
            ->fetchAssociative();

        return $result === false ? '' : $result['tx_aigelb_agentid'];
    }

    public function saveAgentId(string $agentId): void {
        $this->connectionPool
            ->getConnectionForTable('tt_content')
            ->insert(
                'tx_aigelb_domain_model_agent',
                [
                    'tx_aigelb_agentid' => $agentId,
                    'crdate' => time(),
                ],
            );
    }
}
