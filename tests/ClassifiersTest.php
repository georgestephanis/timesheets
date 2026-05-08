<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ClassifiersTest extends TestCase
{
    // classifyVscode

    public function testClassifyVscodeEmDash(): void
    {
        $this->assertSame('my-project', classifyVscode('index.php — my-project'));
    }

    public function testClassifyVscodeBareTitle(): void
    {
        $this->assertSame('my-project', classifyVscode('my-project'));
    }

    public function testClassifyVscodeStripsUnsavedDot(): void
    {
        $this->assertSame('my-project', classifyVscode('● index.php — my-project'));
    }

    public function testClassifyVscodeIgnoresAppSuffix(): void
    {
        $this->assertSame('my-project', classifyVscode('my-project — Visual Studio Code'));
    }

    public function testClassifyVscodeFileAndProjectAndAppSuffix(): void
    {
        $this->assertSame('my-project', classifyVscode('index.php — my-project — Visual Studio Code'));
    }

    public function testClassifyVscodeEmptyString(): void
    {
        $this->assertNull(classifyVscode(''));
    }

    public function testClassifyVscodePureAppName(): void
    {
        $this->assertNull(classifyVscode('Visual Studio Code'));
    }

    public function testClassifyVscodeVsCodeShort(): void
    {
        $this->assertNull(classifyVscode('VS Code'));
    }

    // classifySlack

    public function testClassifySlackChannel(): void
    {
        $result = classifySlack('general (Channel) - Acme Corp - Slack');
        $this->assertNotNull($result);
        $this->assertSame('Acme Corp', $result['workspace']);
        $this->assertSame('general', $result['channel']);
        $this->assertSame('Channel', $result['kind']);
    }

    public function testClassifySlackDM(): void
    {
        $result = classifySlack('Jane Doe (DM) - Acme Corp - Slack');
        $this->assertNotNull($result);
        $this->assertSame('DM', $result['kind']);
    }

    public function testClassifySlackGroup(): void
    {
        $result = classifySlack('project-team (Group) - Acme Corp - Slack');
        $this->assertNotNull($result);
        $this->assertSame('Group', $result['kind']);
    }

    public function testClassifySlackThreads(): void
    {
        $result = classifySlack('Threads - Acme Corp - Slack');
        $this->assertNotNull($result);
        $this->assertSame('__threads__', $result['channel']);
        $this->assertSame('view', $result['kind']);
    }

    public function testClassifySlackActivity(): void
    {
        $result = classifySlack('Activity - Acme Corp - Slack');
        $this->assertNotNull($result);
        $this->assertSame('__activity__', $result['channel']);
    }

    public function testClassifySlackHuddle(): void
    {
        $result = classifySlack('Huddle - Acme Corp - Slack');
        $this->assertNotNull($result);
        $this->assertSame('__huddle__', $result['channel']);
        $this->assertSame('huddle', $result['kind']);
    }

    public function testClassifySlackWithNewItems(): void
    {
        $result = classifySlack('general (Channel) - Acme Corp - 3 new items - Slack');
        $this->assertNotNull($result);
        $this->assertSame('Acme Corp', $result['workspace']);
    }

    public function testClassifySlackNoMatch(): void
    {
        $this->assertNull(classifySlack('Not a slack title'));
    }

    public function testClassifySlackMain(): void
    {
        $result = classifySlack('general (Channel) - Acme Corp - Slack [Main]');
        $this->assertNotNull($result);
        $this->assertSame('Acme Corp', $result['workspace']);
    }

    // classifySsh

    public function testClassifySshBasic(): void
    {
        $this->assertSame('prod-server', classifySsh('ssh prod-server'));
    }

    public function testClassifySshMidTitle(): void
    {
        $this->assertSame('staging.example.com', classifySsh('bash — ssh staging.example.com'));
    }

    public function testClassifySshNoMatch(): void
    {
        $this->assertNull(classifySsh('bash — ~/code/project'));
    }

    public function testClassifySshEmpty(): void
    {
        $this->assertNull(classifySsh(''));
    }
}
