<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Agent extends AbstractEntity {
    protected string $agentId = '';
    protected string $title = '';

    public function getAgentId(): string
    {
        return $this->agentId;
    }

    public function setAgentId(string $agentId): void
    {
        $this->agentId = $agentId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}
