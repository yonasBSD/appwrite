<?php

namespace Appwrite\Migration\Version;

use Appwrite\Migration\Migration;
use Exception;
use Throwable;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Conflict;
use Utopia\Database\Exception\Structure;
use Utopia\Database\Exception\Timeout;

class V25 extends Migration
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        // No schema or data migration yet
    }
}
