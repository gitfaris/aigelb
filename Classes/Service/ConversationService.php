<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Service;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Conversation Management Service
 *
 * Handles all conversation-related operations including:
 * - Session management for active conversations
 * - Cookie-based conversation history storage
 * - Agent-specific conversation handling
 * - Conversation lifecycle management
 */
final class ConversationService
{
    private const SESSION_KEY_CONVERSATION_ID = 'aigelb_conversation_id';
    private const COOKIE_PREFIX = 'aigelb_conversations_';
    private const COOKIE_LIFETIME = 30 * 24 * 60 * 60; // 30 days
    private const MAX_CONVERSATIONS_PER_AGENT = 10;

    /**
     * Constructor with dependency injection
     */
    public function __construct(
        private readonly AIGelbService $aIGelbService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Get current conversation ID for agent or create new one
     *
     * @param string $agentId The specific agent ID to ensure isolation
     * @param string|null $submittedConversationId Optional conversation ID from form
     * @param int|null $contentElementUid Optional content element UID for additional isolation
     */
    public function getCurrentConversationId(
        string $agentId,
        ?string $submittedConversationId = null,
        ?int $contentElementUid = null
    ): string {
        $this->ensureSessionStarted();
        $sessionKey = $this->getAgentSpecificSessionKey($agentId, $contentElementUid);

        // Use submitted conversation ID if valid and belongs to this agent
        if (!empty($submittedConversationId) && $this->isConversationValidForAgent($submittedConversationId, $agentId)) {
            $_SESSION[$sessionKey] = $submittedConversationId;
            return $submittedConversationId;
        }

        // Check session for existing conversation
        if (!empty($_SESSION[$sessionKey])) {
            $existingConversationId = $_SESSION[$sessionKey];
            if ($this->isConversationValidForAgent($existingConversationId, $agentId)) {
                return $existingConversationId;
            }
        }

        // Create new conversation
        return $this->createNewConversation($agentId, $contentElementUid);
    }

    /**
     * Select a specific conversation from history
     */
    public function selectConversation(
        string $agentId,
        string $conversationId,
        ?int $contentElementUid = null
    ): bool {
        if (!$this->isValidConversationForAgent($conversationId, $agentId)) {
            return false;
        }

        $this->setCurrentConversationId($agentId, $conversationId, $contentElementUid);
        return true;
    }

    /**
     * Create a new conversation for agent
     */
    public function createNewConversation(string $agentId, ?int $contentElementUid = null): string
    {
        $this->ensureSessionStarted();

        $conversationId = $this->aIGelbService->createConversation($agentId);

        if (empty($conversationId)) {
            $this->logger->error('Failed to create new conversation', [
                'agentId' => $agentId,
                'contentElementUid' => $contentElementUid
            ]);
            throw new \RuntimeException('Failed to create new conversation');
        }

        $sessionKey = $this->getAgentSpecificSessionKey($agentId, $contentElementUid);
        $_SESSION[$sessionKey] = $conversationId;

        // Initialize in history
        $this->initializeConversationInHistory($agentId, $conversationId);

        $this->logger->info('New conversation created', [
            'agentId' => $agentId,
            'conversationId' => $conversationId,
            'contentElementUid' => $contentElementUid
        ]);

        return $conversationId;
    }

    /**
     * Delete conversation from history
     */
    public function deleteConversation(
        string $agentId,
        string $conversationId,
        ?int $contentElementUid = null
    ): bool {
        $history = $this->getConversationHistory($agentId);

        if (!isset($history[$conversationId])) {
            return false;
        }

        unset($history[$conversationId]);
        $this->saveConversationHistory($agentId, $history);

        // Clear from session if it's the current conversation
        $this->ensureSessionStarted();
        $sessionKey = $this->getAgentSpecificSessionKey($agentId, $contentElementUid);
        if (($_SESSION[$sessionKey] ?? '') === $conversationId) {
            unset($_SESSION[$sessionKey]);
        }

        $this->logger->info('Conversation deleted', [
            'agentId' => $agentId,
            'conversationId' => $conversationId,
            'contentElementUid' => $contentElementUid
        ]);

        return true;
    }

    /**
     * Update conversation history after user interaction
     */
    public function updateConversationHistory(string $agentId, string $conversationId, string $userMessage): void
    {
        $history = $this->getConversationHistory($agentId);

        // Check if this is the first message for this conversation
        $isFirstMessage = !isset($history[$conversationId]) || $history[$conversationId]['messageCount'] === 0;

        // Generate title from first message if not exists or if this is the first message
        $title = $history[$conversationId]['title'] ?? $this->generateConversationTitle($userMessage);
        if ($isFirstMessage) {
            $title = $this->generateConversationTitle($userMessage);
        }

        // Store first message if this is the first message
        $firstMessage = $history[$conversationId]['firstMessage'] ?? '';
        if ($isFirstMessage) {
            $firstMessage = mb_substr($userMessage, 0, 100);
        }

        // Update or create conversation entry
        $history[$conversationId] = [
            'id' => $conversationId,
            'title' => $title,
            'lastActivity' => time(),
            'firstMessage' => $firstMessage,
            'messageCount' => ($history[$conversationId]['messageCount'] ?? 0) + 1,
            'agentId' => $agentId, // Store agent ID for validation
        ];

        // Limit number of stored conversations
        if (count($history) > self::MAX_CONVERSATIONS_PER_AGENT) {
            $history = array_slice($history, 0, self::MAX_CONVERSATIONS_PER_AGENT, true);
        }

        $this->saveConversationHistory($agentId, $history);

        $this->logger->debug('Conversation history updated', [
            'agentId' => $agentId,
            'conversationId' => $conversationId,
            'messageCount' => $history[$conversationId]['messageCount'],
            'isFirstMessage' => $isFirstMessage
        ]);
    }

    /**
     * Get conversation history for agent
     *
     * @return array<string, array<string, mixed>>
     */
    public function getConversationHistory(string $agentId): array
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

        // Filter conversations to ensure they belong to this agent
        $agentConversations = array_filter($decodedData, function ($conversation) use ($agentId) {
            return isset($conversation['agentId']) && $conversation['agentId'] === $agentId;
        });

        // Sort by last activity (newest first)
        uasort($agentConversations, function ($a, $b) {
            return ($b['lastActivity'] ?? 0) <=> ($a['lastActivity'] ?? 0);
        });

        return $agentConversations;
    }

    /**
     * Get conversation messages from API
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConversationMessages(string $conversationId): array
    {
        if (empty($conversationId)) {
            return [];
        }

        return $this->aIGelbService->getConversationMessages($conversationId);
    }

    /**
     * Clear all conversation history for agent (maintenance function)
     */
    public function clearConversationHistory(string $agentId, ?int $contentElementUid = null): void
    {
        $cookieName = $this->getAgentSpecificCookieName($agentId);

        setcookie($cookieName, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        // Clear session
        $this->ensureSessionStarted();
        $sessionKey = $this->getAgentSpecificSessionKey($agentId, $contentElementUid);
        unset($_SESSION[$sessionKey]);

        $this->logger->info('Conversation history cleared', [
            'agentId' => $agentId,
            'contentElementUid' => $contentElementUid
        ]);
    }

    /**
     * Clear all conversation histories for all agents (global maintenance)
     */
    public function clearAllConversationHistories(): void
    {
        foreach ($_COOKIE as $cookieName => $cookieValue) {
            if (str_starts_with($cookieName, self::COOKIE_PREFIX)) {
                setcookie($cookieName, '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
        }

        // Clear session data
        $this->ensureSessionStarted();
        foreach ($_SESSION as $key => $value) {
            if (str_starts_with($key, self::SESSION_KEY_CONVERSATION_ID)) {
                unset($_SESSION[$key]);
            }
        }

        $this->logger->info('All conversation histories cleared');
    }

    /**
     * Get statistics about conversations for agent
     *
     * @return array<string, int>
     */
    public function getConversationStatistics(string $agentId): array
    {
        $history = $this->getConversationHistory($agentId);

        $totalMessages = 0;
        $totalConversations = count($history);
        $activeConversations = 0;
        $oldestActivity = time();
        $newestActivity = 0;

        foreach ($history as $conversation) {
            $totalMessages += $conversation['messageCount'] ?? 0;
            $lastActivity = $conversation['lastActivity'] ?? 0;

            if ($lastActivity > 0) {
                $oldestActivity = min($oldestActivity, $lastActivity);
                $newestActivity = max($newestActivity, $lastActivity);
            }

            // Consider conversation active if used in last 7 days
            if ($lastActivity > (time() - 7 * 24 * 60 * 60)) {
                $activeConversations++;
            }
        }

        return [
            'totalConversations' => $totalConversations,
            'totalMessages' => $totalMessages,
            'activeConversations' => $activeConversations,
            'oldestActivity' => $oldestActivity === time() ? 0 : $oldestActivity,
            'newestActivity' => $newestActivity,
        ];
    }

    /**
     * Set current conversation ID in session
     */
    private function setCurrentConversationId(
        string $agentId,
        string $conversationId,
        ?int $contentElementUid = null
    ): void {
        $this->ensureSessionStarted();
        $sessionKey = $this->getAgentSpecificSessionKey($agentId, $contentElementUid);
        $_SESSION[$sessionKey] = $conversationId;
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
            'firstMessage' => '',
            'messageCount' => 0,
            'agentId' => $agentId, // Store agent ID for validation
        ];

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

        // Determine if HTTPS is being used
        $isHttps = false;
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $isHttps = true;
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $isHttps = true;
        }

        setcookie($cookieName, $cookieValue, [
            'expires' => time() + self::COOKIE_LIFETIME,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    /**
     * Generate conversation title from first message
     */
    private function generateConversationTitle(string $message): string
    {
        $title = trim(strip_tags($message));
        $title = mb_substr($title, 0, 50);

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
     * Generate agent-specific session key with optional content element isolation
     */
    private function getAgentSpecificSessionKey(string $agentId, ?int $contentElementUid = null): string
    {
        $agentHash = substr(md5($agentId), 0, 8);
        $baseKey = self::SESSION_KEY_CONVERSATION_ID . '_agent_' . $agentHash;

        // Add content element UID for additional isolation if provided
        if ($contentElementUid !== null) {
            $baseKey .= '_ce_' . $contentElementUid;
        }

        return $baseKey;
    }

    /**
     * Validate if conversation ID exists in agent's history and belongs to the agent
     */
    private function isValidConversationForAgent(string $conversationId, string $agentId): bool
    {
        $history = $this->getConversationHistory($agentId);
        $conversation = $history[$conversationId] ?? null;

        return $conversation !== null &&
               isset($conversation['agentId']) &&
               $conversation['agentId'] === $agentId;
    }

    /**
     * Basic validation for conversation and agent IDs with agent-specific check
     */
    private function isConversationValidForAgent(string $conversationId, string $agentId): bool
    {
        if (empty($conversationId) || empty($agentId)) {
            return false;
        }

        // Additional validation: check if conversation belongs to this agent
        return $this->isValidConversationForAgent($conversationId, $agentId);
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
}
