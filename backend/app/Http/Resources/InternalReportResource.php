<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InternalReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => $this->category,
            'priority' => $this->priority,
            'status' => $this->status,
            'summary' => $this->summary,
            'body' => $this->body,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'related' => $this->related_type ? ['type' => $this->related_type, 'id' => $this->related_id] : null,
            'sender' => $this->whenLoaded('sender', fn () => $this->sender ? ['id' => $this->sender->id, 'name' => $this->sender->name, 'avatar_url' => $this->sender->avatar_url, 'role' => $this->sender->primaryRoleName()] : null),
            'recipient' => $this->whenLoaded('recipient', fn () => $this->recipient ? ['id' => $this->recipient->id, 'name' => $this->recipient->name, 'avatar_url' => $this->recipient->avatar_url, 'role' => $this->recipient->primaryRoleName()] : null),
            'attachments' => $this->whenLoaded('documents', fn () => AssetDocumentResource::collection($this->documents)),
            'sent_at' => $this->sent_at?->toISOString(),
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
