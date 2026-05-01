<?php

namespace Appwrite\Insights\CTA;

use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

/**
 * Contract for CTA actions registered in the insights CTA registry.
 *
 * A CTA action is a named handler invoked when a user triggers a call-to-action
 * attached to an insight. Implementations validate `$params` themselves and return
 * the document produced by the action (e.g. a freshly-created index).
 *
 * Convention for `getName()`: dot-separated `domain.<sub>.verb` in camelCase, e.g. `databases.indexes.create`.
 */
interface Action
{
    /**
     * Unique, registered name for this action.
     */
    public static function getName(): string;

    /**
     * Run the action. Implementations may throw any `Appwrite\Extend\Exception` to
     * signal a failed execution; the returned Document is surfaced to the caller
     * in the CTA execution response.
     *
     * @param  array<string, mixed>  $params
     */
    public function execute(
        array $params,
        Document $insight,
        Document $project,
        Database $dbForProject,
        callable $getDatabasesDB,
        EventDatabase $queueForDatabase,
        Event $queueForEvents,
        Authorization $authorization,
    ): Document;
}
