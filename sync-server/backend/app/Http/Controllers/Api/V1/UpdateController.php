<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UpdateRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class UpdateController extends Controller
{
    public function manifest(Request $request, string $target, string $arch, string $currentVersion): JsonResponse
    {
        $latest = UpdateRelease::orderByDesc('released_at')->first();

        if (! $latest || version_compare($latest->version, $currentVersion, '<=')) {
            return response()->json([
                'version' => $currentVersion,
                'available' => false,
            ]);
        }

        $platformKey = match ("{$target}-{$arch}") {
            'darwin-aarch64' => 'darwin-aarch64',
            'darwin-x86_64' => 'darwin-x86_64',
            'windows-x86_64' => 'windows-x86_64',
            'windows-i686' => 'windows-i686',
            default => null,
        };

        if (! $platformKey) {
            return response()->json([
                'version' => $currentVersion,
                'available' => false,
            ]);
        }

        $asset = $latest->assets()->where('platform', $platformKey)->first();

        if (! $asset) {
            return response()->json([
                'version' => $currentVersion,
                'available' => false,
            ]);
        }

        $disk = Storage::disk('updates');
        if (! $disk->exists($asset->filename)) {
            return response()->json([
                'version' => $currentVersion,
                'available' => false,
            ]);
        }

        return response()->json([
            'version' => $latest->version,
            'notes' => $latest->release_notes,
            'pub_date' => $latest->released_at->toIso8601String(),
            'platforms' => [
                $platformKey => [
                    'signature' => $asset->signature,
                    'url' => url("updates/{$asset->filename}"),
                ],
            ],
        ]);
    }
}
