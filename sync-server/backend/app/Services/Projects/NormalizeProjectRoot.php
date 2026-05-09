<?php

namespace App\Services\Projects;

class NormalizeProjectRoot
{
    /**
     * Normalize a raw root path and extract a canonical project name guess.
     *
     * @return array{normalized_hash: string, display_path: string|null, canonical_name: string|null}
     */
    public function handle(string $rawPath, string $platform): array
    {
        $normalized = $this->normalizePath($rawPath, $platform);

        $canonical = $this->extractCanonicalName($normalized);

        return [
            'normalized_hash' => hash('sha256', $normalized),
            'display_path' => $normalized,
            'canonical_name' => $canonical,
        ];
    }

    private function normalizePath(string $path, string $platform): string
    {
        $path = str_replace('\\', '/', $path);

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' || $platform === 'windows') {
            $path = preg_replace('/^[a-zA-Z]:/', '', $path);
        }

        // Strip worktree suffixes
        $path = preg_replace('#/\.worktrees/[^/]+$#', '', $path);
        $path = preg_replace('#/worktrees/[^/]+$#', '', $path);
        $path = preg_replace('#/\.claude/worktrees/[^/]+$#', '', $path);
        $path = preg_replace('#--claude-worktrees-[^/]+$#', '', $path);

        return rtrim($path, '/');
    }

    private function extractCanonicalName(string $normalized): ?string
    {
        // If path looks like a session/worktree temp, return null
        if (str_contains($normalized, '/sessions/') || str_contains($normalized, 'worktrees')) {
            return null;
        }

        $parts = explode('/', $normalized);
        $name = end($parts);

        if ($name === '' || $name === false) {
            return null;
        }

        // Strip common worktree suffix patterns from the final directory name
        $name = preg_replace('#--claude-worktrees-.*$#', '', $name);

        return $name ?: null;
    }
}
