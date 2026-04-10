<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

final class Audit extends Base
{
    public function __construct(
        public readonly Document $project,
        public readonly Document $user,
        public readonly array $payload,
        public readonly string $resource,
        public readonly string $mode,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly string $event,
        public readonly string $hostname,
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => [
                '$id' => $this->project->getId(),
                '$sequence' => $this->project->getSequence(),
                'database' => $this->project->getAttribute('database', ''),
            ],
            'user' => $this->user->getArrayCopy(),
            'payload' => $this->payload,
            'resource' => $this->resource,
            'mode' => $this->mode,
            'ip' => $this->ip,
            'userAgent' => $this->userAgent,
            'event' => $this->event,
            'hostname' => $this->hostname,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            project: new Document($data['project'] ?? []),
            user: new Document($data['user'] ?? []),
            payload: $data['payload'] ?? [],
            resource: $data['resource'] ?? '',
            mode: $data['mode'] ?? '',
            ip: $data['ip'] ?? '',
            userAgent: $data['userAgent'] ?? '',
            event: $data['event'] ?? '',
            hostname: $data['hostname'] ?? '',
        );
    }
}
