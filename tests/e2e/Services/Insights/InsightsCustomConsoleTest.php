<?php

namespace Tests\E2E\Services\Insights;

use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class InsightsCustomConsoleTest extends Scope
{
    use InsightsBase;
    use ProjectConsole;
    use SideConsole;
}
