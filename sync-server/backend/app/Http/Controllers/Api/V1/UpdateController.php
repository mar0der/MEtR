<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\UpdateRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class UpdateController extends Controller
{
    public function manifest(Request $request, string $target, string $arch, string $currentVersion): JsonResponse|Response
    {
        $latest = UpdateRelease::orderByDesc('released_at')->first();

        if (! $latest || version_compare($latest->version, $currentVersion, '<=')) {
            // Tauri v2 expects 204 No Content when there is no update
            return response()->noContent();
        }

        $platformKey = match ("{$target}-{$arch}") {
            'darwin-aarch64' => 'darwin-aarch64',
            'darwin-x86_64' => 'darwin-x86_64',
            'windows-x86_64' => 'windows-x86_64',
            'windows-i686' => 'windows-i686',
            'linux-x86_64' => 'linux-x86_64',
            'linux-aarch64' => 'linux-aarch64',
            default => null,
        };

        if (! $platformKey) {
            return response()->noContent();
        }

        $asset = $latest->assets()->where('platform', $platformKey)->first();

        if (! $asset) {
            return response()->noContent();
        }

        $disk = Storage::disk('updates');
        if (! $disk->exists($asset->filename)) {
            return response()->noContent();
        }

        // Force HTTPS for update URLs
        $baseUrl = rtrim(config('app.url', 'https://metr.petarpetkov.com'), '/');
        $baseUrl = str_replace('http://', 'https://', $baseUrl);

        return response()->json([
            'version' => $latest->version,
            'notes' => $latest->release_notes,
            'pub_date' => $latest->released_at->toIso8601String(),
            'signature' => $asset->signature,
            'url' => "{$baseUrl}/updates/{$asset->filename}",
        ]);
    }
}
