<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Interventions\StoreInterventionPhotoRequest;
use App\Http\Requests\Interventions\StoreInterventionReportRequest;
use App\Http\Resources\InterventionResource;
use App\Models\Intervention;
use App\Models\InterventionPhoto;
use App\Models\User;
use App\Services\Interventions\InterventionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InterventionReportController extends Controller
{
    private const RELATIONS = [
        'alert', 'maintenancePlan', 'station', 'connector', 'assignedTechnician', 'events',
        'report.submittedBy', 'photos.uploadedBy',
    ];

    public function __construct(private readonly InterventionReportService $reports) {}

    public function store(StoreInterventionReportRequest $request, Intervention $intervention): InterventionResource
    {
        Gate::authorize('submitReport', $intervention);
        /** @var User $user */
        $user = $request->user();
        $intervention = $this->reports->submit($intervention, $request->validated(), $user);

        return new InterventionResource($intervention->load(self::RELATIONS));
    }

    public function storePhoto(StoreInterventionPhotoRequest $request, Intervention $intervention): JsonResponse
    {
        Gate::authorize('manageEvidence', $intervention);
        if ($intervention->photos()->count() >= 10) {
            throw ValidationException::withMessages(['photo' => ['An intervention can contain at most 10 photos.']]);
        }

        $file = $request->file('photo');
        $extension = strtolower($file->extension() ?: 'jpg');
        $path = 'interventions/'.$intervention->id.'/'.Str::uuid().'.'.$extension;
        $stored = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        if ($stored === false) {
            abort(500, 'The evidence photo could not be stored.');
        }

        try {
            $photo = $intervention->photos()->create([
                'uploaded_by_id' => $request->user()->id,
                'phase' => $request->validated('phase'),
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
                'caption' => $request->validated('caption'),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        $intervention->events()->create([
            'actor_id' => $request->user()->id,
            'event_type' => 'evidence_added',
            'description' => ucfirst($photo->phase).' intervention photo added.',
            'occurred_at' => now(),
        ]);

        return response()->json(['data' => new InterventionResource($intervention->fresh()->load(self::RELATIONS))], 201);
    }

    public function content(Intervention $intervention, InterventionPhoto $photo): StreamedResponse
    {
        $this->assertPhotoOwnership($intervention, $photo);
        Gate::authorize('viewEvidence', $intervention);
        abort_unless(Storage::disk($photo->disk)->exists($photo->path), 404);

        return Storage::disk($photo->disk)->response($photo->path, $photo->original_name, [
            'Content-Type' => $photo->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($photo->original_name).'"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyPhoto(Intervention $intervention, InterventionPhoto $photo): JsonResponse
    {
        $this->assertPhotoOwnership($intervention, $photo);
        Gate::authorize('manageEvidence', $intervention);
        $path = $photo->path;
        $disk = $photo->disk;
        $phase = $photo->phase;
        $photo->delete();
        Storage::disk($disk)->delete($path);
        $intervention->events()->create([
            'actor_id' => request()->user()->id,
            'event_type' => 'evidence_removed',
            'description' => ucfirst($phase).' intervention photo removed.',
            'occurred_at' => now(),
        ]);

        return response()->json(['data' => new InterventionResource($intervention->fresh()->load(self::RELATIONS))]);
    }

    private function assertPhotoOwnership(Intervention $intervention, InterventionPhoto $photo): void
    {
        abort_unless($photo->intervention_id === $intervention->id, 404);
    }
}
