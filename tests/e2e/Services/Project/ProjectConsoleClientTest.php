<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class ProjectConsoleClientTest extends Scope
{
    use ProjectBase;
    use ProjectCustom;
    use SideConsole;
    
    public function testDeleteProject(): void
    {
        // TODO:
        // 1. Create new team
        // 2. Create new project
        // 3. Delete project
        // 4. Verify project is deleted
    }
    
    public function testDeleteProjectUsingKey(): void
    {
        // TODO:
        // 1. Create new team
        // 2. Create new project
        // 3. Create new API key
        // 4. Delete project using API key
        // 5. Verify project is deleted
    }
}
