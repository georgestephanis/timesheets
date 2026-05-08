<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class GitHubTest extends TestCase
{
    // githubRepoFromRemoteUrl

    public function testHttpsUrl(): void
    {
        $this->assertSame('owner/repo', githubRepoFromRemoteUrl('https://github.com/owner/repo.git'));
    }

    public function testHttpsUrlWithoutDotGit(): void
    {
        $this->assertSame('owner/repo', githubRepoFromRemoteUrl('https://github.com/owner/repo'));
    }

    public function testSshUrl(): void
    {
        $this->assertSame('owner/repo', githubRepoFromRemoteUrl('git@github.com:owner/repo.git'));
    }

    public function testSshUrlWithoutDotGit(): void
    {
        $this->assertSame('owner/repo', githubRepoFromRemoteUrl('git@github.com:owner/repo'));
    }

    public function testNonGitHubUrl(): void
    {
        $this->assertNull(githubRepoFromRemoteUrl('https://gitlab.com/owner/repo.git'));
    }

    public function testEmpty(): void
    {
        $this->assertNull(githubRepoFromRemoteUrl(''));
    }

    public function testCaseInsensitive(): void
    {
        $this->assertSame('owner/repo', githubRepoFromRemoteUrl('https://GITHUB.COM/owner/repo'));
    }
}
