<?php

namespace App\Domains\Taxation\Http\Resources;

use App\Domains\Taxation\Models\BasLodgement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BasLodgement */
class BasLodgementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'from_date' => $this->period_start?->toDateString(),
            'to_date' => $this->period_end?->toDateString(),
            'basis' => $this->basis,
            'figures' => $this->figures,
            'lodged_on' => $this->lodged_on?->toDateString(),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => (int) $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
