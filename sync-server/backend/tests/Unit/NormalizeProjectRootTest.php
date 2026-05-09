<?php

namespace Tests\Unit;

use App\Services\Projects\NormalizeProjectRoot;
use Tests\TestCase;

class NormalizeProjectRootTest extends TestCase
{
    private NormalizeProjectRoot $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NormalizeProjectRoot;
    }

    public function test_macos_developer_path(): void
    {
        $result = $this->service->handle('/Users/petarpetkov/Developer/FitHero', 'macos');
        $this->assertSame('FitHero', $result['canonical_name']);
    }

    public function test_worktree_stripping(): void
    {
        $result = $this->service->handle('/Users/petarpetkov/Developer/FitHero/.worktrees/abc', 'macos');
        $this->assertSame('FitHero', $result['canonical_name']);
    }

    public function test_claude_worktree_suffix(): void
    {
        $result = $this->service->handle('/Users/petarpetkov/Developer/GichevaArt--claude-worktrees-cool-mcnulty-aa3c17', 'macos');
        $this->assertSame('GichevaArt', $result['canonical_name']);
    }

    public function test_windows_path(): void
    {
        $result = $this->service->handle('C:\\Users\\Petar\\Developer\\FitHero', 'windows');
        $this->assertSame('FitHero', $result['canonical_name']);
    }

    public function test_windows_source_repos(): void
    {
        $result = $this->service->handle('C:\\Users\\Petar\\source\\repos\\FitHero', 'windows');
        $this->assertSame('FitHero', $result['canonical_name']);
    }

    public function test_session_uuid_returns_null(): void
    {
        $result = $this->service->handle('/Users/petarpetkov/.kimi/sessions/uuid/wire.jsonl', 'macos');
        $this->assertNull($result['canonical_name']);
    }
}
