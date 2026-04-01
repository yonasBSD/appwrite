<?php

namespace Tests\E2E\Services\Databases\VectorsDB\Transactions;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class TransactionsCustomClientTest extends Scope
{
    use TransactionsBase;
    use ProjectCustom;
    use SideClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireAdapter('postgresql');
    }
}
