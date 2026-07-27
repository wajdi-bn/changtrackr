<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'organization_id', 'documentable_type', 'documentable_id', 'uploaded_by_id',
    'category', 'title', 'description', 'version_label', 'visibility', 'disk',
    'path', 'original_name', 'mime_type', 'size_bytes', 'checksum_sha256',
    'issued_at', 'expires_at',
])]
class AssetDocument extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPreviewable(): bool
    {
        return $this->mime_type === 'application/pdf'
            || str_starts_with($this->mime_type, 'image/');
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'issued_at' => 'date',
            'expires_at' => 'date',
        ];
    }
}
