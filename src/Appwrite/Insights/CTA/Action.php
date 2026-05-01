<?php

namespace Appwrite\Insights\CTA;

use Utopia\Platform\Action as PlatformAction;

/**
 * Base class for CTA actions registered in the insights CTA registry.
 *
 * A CTA action is a named, parameter-validated callable invoked when a user triggers
 * a call-to-action attached to an insight. Subclasses declare their inputs via `param()`
 * and dependencies via `inject()`, and provide their executable body via `callback()`.
 *
 * Convention for `getName()`: dot-separated `domain.<sub>.verb` in camelCase, e.g. `databases.indexes.create`.
 * The required project scope is declared via `label('scope', '...')`.
 */
abstract class Action extends PlatformAction
{
    /**
     * Unique, registered name for this action.
     */
    abstract public static function getName(): string;
}
