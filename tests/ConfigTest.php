<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    // parseSlackSignal

    public function testParseSlackSignalValid(): void
    {
        $result = parseSlackSignal('Acme Corp / general');
        $this->assertNotNull($result);
        $this->assertSame('Acme Corp', $result['workspace']);
        $this->assertSame('general', $result['channel']);
    }

    public function testParseSlackSignalNoSeparator(): void
    {
        $this->assertNull(parseSlackSignal('Acme Corp'));
    }

    public function testParseSlackSignalEmptyWorkspace(): void
    {
        $this->assertNull(parseSlackSignal(' / general'));
    }

    public function testParseSlackSignalEmptyChannel(): void
    {
        $this->assertNull(parseSlackSignal('Acme Corp / '));
    }

    public function testParseSlackSignalPreservesSpaces(): void
    {
        $result = parseSlackSignal('My Company / some-channel');
        $this->assertSame('My Company', $result['workspace']);
        $this->assertSame('some-channel', $result['channel']);
    }

    // applySignalToProject

    private function baseConfig(): array
    {
        return [
            'projects' => [
                'Acme' => [],
            ],
        ];
    }

    public function testApplyVscodeSignal(): void
    {
        $config = $this->baseConfig();
        applySignalToProject($config, 'vscode', 'acme-frontend', 'Acme');
        $this->assertContains('acme-frontend', $config['projects']['Acme']['vscode_dirs']);
    }

    public function testApplyVscodeSignalNoDuplicates(): void
    {
        $config = $this->baseConfig();
        applySignalToProject($config, 'vscode', 'acme-frontend', 'Acme');
        applySignalToProject($config, 'vscode', 'acme-frontend', 'Acme');
        $this->assertCount(1, $config['projects']['Acme']['vscode_dirs']);
    }

    public function testApplyBrowserSignal(): void
    {
        $config = $this->baseConfig();
        applySignalToProject($config, 'browser', 'acme.com', 'Acme');
        $this->assertContains('acme.com', $config['projects']['Acme']['domains']);
    }

    public function testApplyBrowserSignalIgnoresEmpty(): void
    {
        $config = $this->baseConfig();
        applySignalToProject($config, 'browser', '', 'Acme');
        $this->assertArrayNotHasKey('domains', $config['projects']['Acme']);
    }

    public function testApplyBrowserSignalIgnoresNoUrl(): void
    {
        $config = $this->baseConfig();
        applySignalToProject($config, 'browser', '(no url)', 'Acme');
        $this->assertArrayNotHasKey('domains', $config['projects']['Acme']);
    }

    public function testApplySlackSignal(): void
    {
        $config = $this->baseConfig();
        applySignalToProject($config, 'slack', 'Acme Corp / general', 'Acme');
        $this->assertNotEmpty($config['projects']['Acme']['slack']);
        $this->assertSame('Acme Corp', $config['projects']['Acme']['slack'][0]['workspace']);
        $this->assertSame('general', $config['projects']['Acme']['slack'][0]['channel_glob']);
    }

    public function testApplySlackSignalThreadsOmitsChannelGlob(): void
    {
        $config = $this->baseConfig();
        applySignalToProject($config, 'slack', 'Acme Corp / __threads__', 'Acme');
        $rule = $config['projects']['Acme']['slack'][0];
        $this->assertArrayNotHasKey('channel_glob', $rule);
    }

    public function testApplySlackSignalNoDuplicates(): void
    {
        $config = $this->baseConfig();
        applySignalToProject($config, 'slack', 'Acme Corp / general', 'Acme');
        applySignalToProject($config, 'slack', 'Acme Corp / general', 'Acme');
        $this->assertCount(1, $config['projects']['Acme']['slack']);
    }

    public function testApplySignalUnknownProjectIsNoOp(): void
    {
        $config = $this->baseConfig();
        applySignalToProject($config, 'vscode', 'something', 'NonExistent');
        $this->assertSame(['Acme' => []], $config['projects']);
    }

    public function testApplySshSignal(): void
    {
        $config = $this->baseConfig();
        applySignalToProject($config, 'apps', 'ssh:prod-server', 'Acme');
        $this->assertContains('prod-server', $config['projects']['Acme']['ssh_hosts']);
    }
}
