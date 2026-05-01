<?php

namespace Appwrite\Insights\Cta;

use Utopia\Database\Database;
use Utopia\Database\Document;

interface Action
{
    /**
     * Unique, registered name for this action.
     *
     * Convention: `domain.verb` in camelCase, e.g. `databases.createIndex`.
     */
    public function getName(): string;

    /**
     * The project scope a caller must hold to trigger CTAs that map to this action.
     *
     * Returned exactly as it would appear in the role/scopes config (e.g. `databases.write`).
     */
    public function getRequiredScope(): string;

    /**
     * Validate the params blob attached to the CTA.
     *
     * Implementations MUST throw `Appwrite\Extend\Exception::INSIGHT_CTA_VALIDATION_FAILED`
     * (or a more specific error) when params are missing or malformed.
     *
     * @param array<string, mixed> $params
     */
    public function validate(array $params): void;

    /**
     * Execute the action on behalf of the authenticated caller.
     *
     * Returns a `Document` describing the result. The document is rendered using
     * `Response::MODEL_INSIGHT_CTA_RESULT` and its keys must match that model's rules.
     *
     * @param array<string, mixed> $params
     */
    public function execute(array $params, Document $insight, Document $project, Database $dbForProject): Document;
}
