<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcessingTaskLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'stage' => $this->stage,
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}