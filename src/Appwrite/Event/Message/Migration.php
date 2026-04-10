<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

class Migration extends Base
{
    public function __construct(
        public Document $project,
        public Document $migration,
        public array $platform = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project->getArrayCopy(),
            'migration' => $this->migration->getArrayCopy(),
            'platform' => $this->platform,
        ];
    }

    public static function fromArray(array $data): static
    {
        /** @phpstan-ignore new.static (subclass constructors are backwards-compatible via optional params) */
        return new static(
            project: new Document($data['project'] ?? []),
            migration: new Document($data['migration'] ?? []),
            platform: $data['platform'] ?? [],
        );
    }
}
