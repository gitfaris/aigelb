<?php

declare(strict_types=1);

namespace IGelb\Aigelb\Domain\Model;

use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

class Questions extends AbstractEntity {
    protected string $question = '';
    protected ?Agent $agent = null;

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function setQuestion(string $question): void
    {
        $this->question = $question;
    }

    public function getAgent(): ?Agent
    {
        return $this->agent;
    }

    public function setAgent(?Agent $agent): void
    {
        $this->agent = $agent;
    }
}
