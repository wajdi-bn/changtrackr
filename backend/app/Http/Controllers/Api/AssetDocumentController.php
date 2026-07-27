<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreAssetDocumentRequest;
use App\Http\Resources\AssetDocumentResource;
use App\Models\AssetDocument;
use App\Models\InternalReport;
use App\Models\Intervention;
use App\Models\Station;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AssetDocumentController extends Controller
{
    private const CATEGORIES = [
        Station::class => ['manual', 'certificate', 'installation', 'maintenance', 'safety', 'other'],
        Intervention::class => ['diagnostic', 'work_order', 'supplier', 'safety', 'other'],
        InternalReport::class => ['report_attachment', 'evidence', 'dataset', 'other'],
    ];

    private const LIMITS = [
        Station::class => 30,
        Intervention::class => 15,
        InternalReport::class => 10,
    ];

    public function stationIndex(Request $request, Station $station): JsonResponse
    {
        return $this->index($request, $station);
    }

    public function stationStore(StoreAssetDocumentRequest $request, Station $station, PlatformAuditService $audit): JsonResponse
    {
        return $this->store($request, $station, $audit);
    }

    public function interventionIndex(Request $request, Intervention $intervention): JsonResponse
    {
        return $this->index($request, $intervention);
    }

    public function interventionStore(StoreAssetDocumentRequest $request, Intervention $intervention, PlatformAuditService $audit): JsonResponse
    {
        return $this->store($request, $intervention, $audit);
    }

    public function reportIndex(Request $request, InternalReport $internalReport): JsonResponse
    {
        return $this->index($request, $internalReport);
    }

    public function reportStore(StoreAssetDocumentRequest $request, InternalReport $internalReport, PlatformAuditService $audit): JsonResponse
    {
        return $this->store($request, $internalReport, $audit);
    }

    public function content(Request $request, AssetDocument $assetDocument): StreamedResponse
    {
        $user = $this->actor($request);
        $this->authorizeView($user, $assetDocument->documentable);
        abort_if(
            $user->hasRole('client')
            && $assetDocument->documentable instanceof Station
            && $assetDocument->visibility !== 'public',
            403,
        );
        abort_unless(Storage::disk($assetDocument->disk)->exists($assetDocument->path), 404);
        $inline = $request->boolean('inline') && $assetDocument->isPreviewable();
        $disposition = $inline ? 'inline' : 'attachment';

        return Storage::disk($assetDocument->disk)->response(
            $assetDocument->path,
            $assetDocument->original_name,
            [
                'Content-Type' => $assetDocument->mime_type,
                'Content-Disposition' => $disposition.'; filename="'.addslashes($assetDocument->original_name).'"',
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
            ],
        );
    }

    public function destroy(Request $request, AssetDocument $assetDocument, PlatformAuditService $audit): JsonResponse
    {
        $user = $this->actor($request);
        $this->authorizeManage($user, $assetDocument->documentable);
        $path = $assetDocument->path;
        $disk = $assetDocument->disk;
        $title = $assetDocument->title;
        $audit->record($user, 'document.deleted', $assetDocument->documentable, 'Deleted document '.$title.'.');
        $assetDocument->delete();
        Storage::disk($disk)->delete($path);

        return response()->json(['message' => 'Document deleted.']);
    }

    private function index(Request $request, Model $documentable): JsonResponse
    {
        $user = $this->actor($request);
        $this->authorizeView($user, $documentable);
        $query = $documentable->documents()->with('uploadedBy')->latest();
        if ($user->hasRole('client')) {
            $query->where('visibility', 'public');
        }
        $documents = $query->get();

        return response()->json([
            'data' => AssetDocumentResource::collection($documents),
            'meta' => [
                'categories' => self::CATEGORIES[$documentable::class],
                'can_manage' => $this->canManage($user, $documentable),
                'max_files' => self::LIMITS[$documentable::class],
                'max_file_size_mb' => 10,
                'accepted_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'],
            ],
        ]);
    }

    private function store(StoreAssetDocumentRequest $request, Model $documentable, PlatformAuditService $audit): JsonResponse
    {
        $user = $this->actor($request);
        $this->authorizeManage($user, $documentable);
        $attributes = $request->validated();
        $this->validateContext($documentable, $attributes);
        if ($documentable->documents()->count() >= self::LIMITS[$documentable::class]) {
            throw ValidationException::withMessages(['file' => ['The document limit for this record has been reached.']]);
        }

        $file = $request->file('file');
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $organizationId = (int) $documentable->organization_id;
        $path = 'documents/'.$organizationId.'/'.Str::uuid().'.'.$extension;
        $stored = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        if ($stored === false) {
            abort(500, 'The document could not be stored.');
        }

        try {
            $document = $documentable->documents()->create([
                'organization_id' => $organizationId,
                'uploaded_by_id' => $user->id,
                'category' => $attributes['category'],
                'title' => $attributes['title'],
                'description' => $attributes['description'] ?? null,
                'version_label' => $attributes['version_label'] ?? null,
                'visibility' => $documentable instanceof Station ? ($attributes['visibility'] ?? 'organization') : 'organization',
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
                'issued_at' => $attributes['issued_at'] ?? null,
                'expires_at' => $attributes['expires_at'] ?? null,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        $audit->record($user, 'document.uploaded', $documentable, 'Uploaded document '.$document->title.'.');

        return (new AssetDocumentResource($document->load('uploadedBy')))->response()->setStatusCode(201);
    }

    /** @param array<string, mixed> $attributes */
    private function validateContext(Model $documentable, array $attributes): void
    {
        if (! in_array($attributes['category'], self::CATEGORIES[$documentable::class], true)) {
            throw ValidationException::withMessages(['category' => ['This category is not valid for the selected record.']]);
        }
        if (! $documentable instanceof Station && ($attributes['visibility'] ?? 'organization') !== 'organization') {
            throw ValidationException::withMessages(['visibility' => ['Only station documents can be public.']]);
        }
    }

    private function authorizeView(User $user, Model $documentable): void
    {
        if ($documentable instanceof Station) {
            Gate::forUser($user)->authorize('view', $documentable);

            return;
        }
        if ($documentable instanceof Intervention) {
            Gate::forUser($user)->authorize('view', $documentable);

            return;
        }
        if ($documentable instanceof InternalReport) {
            abort_unless(
                $documentable->organization_id === $user->organization_id
                && (
                    $documentable->sender_id === $user->id
                    || ($documentable->recipient_id === $user->id && $documentable->status !== 'draft')
                ),
                403,
            );

            return;
        }
        abort(404);
    }

    private function authorizeManage(User $user, Model $documentable): void
    {
        abort_unless($this->canManage($user, $documentable), 403);
    }

    private function canManage(User $user, Model $documentable): bool
    {
        if ($documentable instanceof Station) {
            return Gate::forUser($user)->allows('update', $documentable);
        }
        if ($documentable instanceof Intervention) {
            if (Gate::forUser($user)->denies('view', $documentable)) {
                return false;
            }

            return $user->can('interventions.manage')
                || ($user->can('interventions.report') && $documentable->assigned_technician_id === $user->id);
        }
        if ($documentable instanceof InternalReport) {
            return $documentable->organization_id === $user->organization_id
                && $documentable->sender_id === $user->id
                && $documentable->status === 'draft'
                && $user->can('reports.exchange');
        }

        return false;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
