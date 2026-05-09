<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ModelPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PricingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $prices = ModelPrice::with('provider')->get();

        return response()->json($prices);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_id' => ['required', 'string', 'exists:providers,id'],
            'model' => ['required', 'string'],
            'aliases_json' => ['nullable', 'array'],
            'currency' => ['string'],
            'input_per_1m' => ['nullable', 'numeric'],
            'output_per_1m' => ['nullable', 'numeric'],
            'cached_input_per_1m' => ['nullable', 'numeric'],
            'cache_write_per_1m' => ['nullable', 'numeric'],
            'cache_read_per_1m' => ['nullable', 'numeric'],
            'reasoning_per_1m' => ['nullable', 'numeric'],
            'tool_per_1m' => ['nullable', 'numeric'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date'],
            'source_url' => ['nullable', 'string'],
            'catalog_version' => ['nullable', 'string'],
            'user_override' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! empty($data['aliases_json'])) {
            $data['aliases_json'] = json_encode($data['aliases_json']);
        }

        $price = ModelPrice::create($data);

        return response()->json($price, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $price = ModelPrice::findOrFail($id);

        $data = $request->validate([
            'aliases_json' => ['nullable', 'array'],
            'input_per_1m' => ['nullable', 'numeric'],
            'output_per_1m' => ['nullable', 'numeric'],
            'cached_input_per_1m' => ['nullable', 'numeric'],
            'cache_write_per_1m' => ['nullable', 'numeric'],
            'cache_read_per_1m' => ['nullable', 'numeric'],
            'reasoning_per_1m' => ['nullable', 'numeric'],
            'tool_per_1m' => ['nullable', 'numeric'],
            'effective_to' => ['nullable', 'date'],
            'user_override' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if (array_key_exists('aliases_json', $data) && $data['aliases_json'] !== null) {
            $data['aliases_json'] = json_encode($data['aliases_json']);
        }

        $price->update($data);

        return response()->json($price);
    }
}
