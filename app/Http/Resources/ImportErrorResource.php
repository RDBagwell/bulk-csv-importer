<?php

namespace App\Http\Resources;

use App\Models\ImportError;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ImportError
 */
class ImportErrorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'column' => $this->column !== '' ? $this->column : null,
            'message' => $this->message,
            'raw_excerpt' => $this->raw_excerpt,
        ];
    }
}
