<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $projects = $request->user()->projects()->with('projectRoots')->get();

        return response()->json($projects);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $project = $request->user()->projects()->findOrFail($id);

        $data = $request->validate([
            'canonical_name' => ['string'],
            'manual_name' => ['nullable', 'string'],
            'active' => ['boolean'],
        ]);

        $project->update($data);

        return response()->json($project);
    }

    public function merge(Request $request, string $id): JsonResponse
    {
        $source = $request->user()->projects()->findOrFail($id);

        $data = $request->validate([
            'target_project_id' => ['required', 'string', 'exists:projects,id'],
        ]);

        $target = $request->user()->projects()->findOrFail($data['target_project_id']);

        if ($source->id === $target->id) {
            return response()->json(['error' => 'Cannot merge a project into itself'], 422);
        }

        // Move project roots
        $source->projectRoots()->update(['project_id' => $target->id]);

        // Move conversations
        $source->conversations()->update(['project_id' => $target->id]);

        // Move usage events
        $source->usageEvents()->update(['project_id' => $target->id]);

        // Mark source inactive
        $source->update(['active' => false]);

        return response()->json(['ok' => true, 'target_project_id' => $target->id]);
    }
}
