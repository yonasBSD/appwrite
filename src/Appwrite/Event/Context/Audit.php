<?php

namespace Appwrite\Event\Context;

use Utopia\Database\Document;

class Audit
{
    protected ?Document $project = null;

    protected ?Document $user = null;

    protected string $mode = '';

    protected string $userAgent = '';

    protected string $ip = '';

    protected string $hostname = '';

    protected string $event = '';

    protected string $resource = '';

    protected array $payload = [];

    public function setProject(Document $project): self
    {
        $this->project = $project;

        return $this;
    }

    public function getProject(): ?Document
    {
        return $this->project;
    }

    public function setUser(Document $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getUser(): ?Document
    {
        return $this->user;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function setIP(string $ip): self
    {
        $this->ip = $ip;

        return $this;
    }

    public function getIP(): string
    {
        return $this->ip;
    }

    public function setHostname(string $hostname): self
    {
        $this->hostname = $hostname;

        return $this;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function setEvent(string $event): self
    {
        $this->event = $event;

        return $this;
    }

    public function getEvent(): string
    {
        return $this->event;
    }

    public function setResource(string $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function setPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }
}
