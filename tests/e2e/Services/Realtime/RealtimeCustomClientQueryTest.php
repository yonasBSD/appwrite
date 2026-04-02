<?php

namespace Tests\E2E\Services\Realtime;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Functions\FunctionsBase;

class RealtimeCustomClientQueryTest extends Scope
{
    use FunctionsBase;
    use RealtimeBase;
    use ProjectCustom;
    use SideClient;
    use RealtimeQueryBase;

    protected function supportForCheckConnectionStatus(): bool
    {
        return true;
    }
}
