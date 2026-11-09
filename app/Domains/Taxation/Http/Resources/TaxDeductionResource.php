<?php

namespace App\Domains\Taxation\Http\Resources;

use App\Domains\Taxation\Models\TaxDeduction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxDeduction */
class TaxDeductionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'deduction_date' => $this->deduction_date?->toDateString(),
            'category' => $this->category,
            'description' => $this->description,
            'amount' => (int) $this->amount,
            'notes' => $this->notes,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => (int) $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
