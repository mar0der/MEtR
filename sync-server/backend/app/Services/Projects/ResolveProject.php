<?php

namespace App\Services\Projects;

use App\Models\Device;
use App\Models\Project;
use App\Models\ProjectRoot;
use Illuminate\Support\Str;

class ResolveProject
{
    public function __construct(
        private NormalizeProjectRoot $normalizer,
    ) {}

    /**
     * Resolve or create a project for a given raw root path.
     */
    public function handle(Device $device, string $rawPath, string $providerId): ?Project
    {
        $normalized = $this->normalizer->handle($rawPath, $device->platform);

        if ($normalized['canonical_name'] === null) {
            return null;
        }

        $projectRoot = ProjectRoot::where([
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'normalized_path_hash' => $normalized['normalized_hash'],
        ])->first();

        if ($projectRoot) {
            $projectRoot->update(['last_seen_at' => now()]);

            return $projectRoot->project;
        }

        // Look for an existing project with the same canonical name for this user
        $project = Project::where([
            'user_id' => $device->user_id,
            'canonical_name' => $normalized['canonical_name'],
        ])->first();

        if (! $project) {
            $project = Project::create([
                'user_id' => $device->user_id,
                'canonical_name' => $normalized['canonical_name'],
                'slug' => Str::slug($normalized['canonical_name']),
            ]);
        }

        ProjectRoot::create([
            'project_id' => $project->id,
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'absolute_path' => $rawPath,
            'normalized_path_hash' => $normalized['normalized_hash'],
            'display_path' => $normalized['display_path'],
            'source_provider_id' => $providerId,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return $project;
    }
}
