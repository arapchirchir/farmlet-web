<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellerProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'thumbnail' => $this->thumbnail,
            'price' => (float) $this->price,
            'discount_price' => (float) $this->discount_price,
            'processing_available' => (bool) $this->processing_available,
            'raw_price' => (float) ($this->raw_price ?? $this->price),
            'processed_price' => $this->processed_price !== null ? (float) $this->processed_price : null,
            'quantity' => (int) $this->pivot?->quantity ?? $this->quantity,
            'brand' => $this->brand?->name ?? null,
            'unit' => $this->unit ?? '',
            'is_active' => (bool) $this->is_active,
        ];
    }
}
