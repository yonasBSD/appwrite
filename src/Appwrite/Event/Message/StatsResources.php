<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

class StatsResources extends Base
{
    public function __construct(
        public Document $project,
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project->getArrayCopy(),
        ];
    }

    public static function fromArray(array $data): static
    {
        /** @phpstan-ignore new.static (subclass constructors are backwards-compatible via optional params) */
        return new static(
            project: new Document($data['project'] ?? []),
        );
    }
}
