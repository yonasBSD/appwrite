<?php

namespace Appwrite\Event\Message;

use Utopia\Database\Document;

class Execution extends Base
{
    public function __construct(
        public Document $project,
        public Document $execution,
    ) {
    }

    public function toArray(): array
    {
        return [
            'project' => $this->project->getArrayCopy(),
            'execution' => $this->execution->getArrayCopy(),
        ];
    }

    public static function fromArray(array $data): static
    {
        /** @phpstan-ignore new.static (subclass constructors are backwards-compatible via optional params) */
        return new static(
            project: new Document($data['project'] ?? []),
            execution: new Document($data['execution'] ?? []),
        );
    }
}
