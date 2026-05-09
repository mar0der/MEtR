<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DeviceController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:255'],
            'hostname_hash' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        $device = Device::updateOrCreate(
            [
                'user_id' => $user->id,
                'device_uuid' => $data['device_uuid'],
            ],
            [
                'display_name' => $data['display_name'],
                'platform' => $data['platform'],
                'hostname_hash' => $data['hostname_hash'] ?? null,
                'os_version' => $data['os_version'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'last_seen_at' => now(),
            ]
        );

        return response()->json([
            'device' => [
                'id' => $device->id,
                'device_uuid' => $device->device_uuid,
                'display_name' => $device->display_name,
            ],
        ]);
    }
}
