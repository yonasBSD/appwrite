<?php

namespace Tests\Unit\Platform\Modules\Installer;

use Appwrite\Utopia\View;
use PHPUnit\Framework\TestCase;

class ComposeTemplateTest extends TestCase
{
    public function testRenderedInstallerComposeIncludesExecutionsWorker(): void
    {
        $canonicalCompose = $this->readRepoFile('/docker-compose.yml');
        $renderedCompose = $this->renderInstallerCompose();

        $this->assertStringContainsString("appwrite-worker-executions:\n", $canonicalCompose);
        $this->assertStringContainsString("appwrite-worker-executions:\n", $renderedCompose);
        $this->assertStringContainsString("entrypoint: worker-executions\n", $renderedCompose);
    }

    public function testRenderedInstallerComposeUsesCanonicalExecutorImage(): void
    {
        $canonicalExecutorImage = $this->extractExecutorImage($this->readRepoFile('/docker-compose.yml'));
        $renderedCompose = $this->renderInstallerCompose();

        $this->assertStringContainsString("image: {$canonicalExecutorImage}\n", $renderedCompose);
    }

    private function renderInstallerCompose(): string
    {
        $view = new View($this->repoPath('/app/views/install/compose.phtml'));

        $view
            ->setParam('httpPort', '80')
            ->setParam('httpsPort', '443')
            ->setParam('version', '1.9.0')
            ->setParam('organization', 'appwrite')
            ->setParam('image', 'appwrite')
            ->setParam('database', 'mariadb')
            ->setParam('hostPath', '')
            ->setParam('enableAssistant', false);

        return $view->render(false);
    }

    private function extractExecutorImage(string $compose): string
    {
        $matched = preg_match('/^\s*image:\s*(openruntimes\/executor:[^\s]+)\s*$/m', $compose, $matches);

        $this->assertSame(1, $matched, 'Failed to find the canonical openruntimes executor image.');

        return $matches[1];
    }

    private function readRepoFile(string $path): string
    {
        $contents = file_get_contents($this->repoPath($path));

        $this->assertIsString($contents, "Failed to read {$path}.");

        return $contents;
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 5) . $path;
    }
}
