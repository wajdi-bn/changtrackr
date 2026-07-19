<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'token_hash', 'token_ciphertext', 'masked_token', 'label', 'kind', 'status', 'expires_at', 'last_used_at'])]
class OcppIdTag extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OcppTransaction::class);
    }

    protected function casts(): array
    {
        return [
            'token_ciphertext' => 'encrypted',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
