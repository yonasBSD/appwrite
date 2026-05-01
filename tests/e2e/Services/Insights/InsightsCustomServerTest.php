<?php

namespace Tests\E2E\Services\Insights;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class InsightsCustomServerTest extends Scope
{
    use InsightsBase;
    use ProjectCustom;
    use SideServer;
}
