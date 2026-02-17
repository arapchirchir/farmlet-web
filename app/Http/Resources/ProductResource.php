<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->load(['reviews', 'orders', 'brand', 'shop', 'flashSales']);

        $lang = request()->header('accept-language') ?? 'en';

        $favorite = false;
        $user = Auth::guard('api')->user();

        if ($user && $user->customer) {
            $favorite = $user->customer->favorites()->where('product_id', $this->id)->exists();
        }

        $rawBasePrice = $this->raw_price ?? $this->price;
        $discountPercentage = $this->getDiscountPercentage($rawBasePrice, $this->discount_price);
        $totalSold = $this->orders->sum('pivot.quantity');

        $flashSale = $this->flashSales?->first();
        $flashSaleProduct = null;
        $quantity = null;

        if ($flashSale) {
            $flashSaleProduct = $flashSale?->products()->where('id', $this->id)->first();

            $quantity = $flashSaleProduct?->pivot->quantity - $flashSaleProduct->pivot->sale_quantity;

            if ($quantity == 0) {
                $quantity = null;
                $flashSaleProduct = null;
            } else {
                $discountPercentage = $flashSale?->pivot->discount;
            }
        }

        $price = $rawBasePrice * $request->currencyData['rate'];
        $discountPrice = $flashSaleProduct ? $flashSaleProduct->pivot->price : $this->discount_price;
        $discountCurrencyPrice = ($discountPrice * $request->currencyData['rate']);
        $discountCurrencyPercentage =$discountPercentage * $request->currencyData['rate'];
        $processedPrice = $this->processed_price ? ($this->processed_price * $request->currencyData['rate']) : null;
        $translation = $this->translations()?->where('lang', $lang)->first();
        $name = $translation?->name ?? $this->name;

        $brandTranslation = $this->brand?->translations()?->where('lang', $lang)->first();
        $brandName = $brandTranslation?->name ?? $this->brand?->name;

        return [
            'id' => $this->id,
            'slug' =>$this->slug,
            'name' => $name,
            'thumbnail' => $this->thumbnail,
            'price' => (float) number_format($price, 2, '.', ''),
            'discount_price' => (float) number_format($discountCurrencyPrice, 2, '.', ''),
            'discount_percentage' => (float) number_format($discountCurrencyPercentage, 2, '.', ''),
            'processing_available' => (bool) $this->processing_available,
            'raw_price' => (float) number_format($price, 2, '.', ''),
            'processed_price' => $processedPrice !== null ? (float) number_format($processedPrice, 2, '.', '') : null,
            'rating' => (float) $this->averageRating ?? 0.0,
            'total_reviews' => (string) Number::abbreviate($this->reviews?->count(), maxPrecision: 2),
            'total_sold' => (string) number_format($totalSold, 0, '.', ','),
            'quantity' => (int) ($flashSaleProduct ? $quantity : $this->quantity),
            'is_favorite' => (bool) $favorite,
            'unit' => $this->unit ?? null,
            'unit_measurement_add' => $this->unit_measurement_add ?? 0,
            'brand' => $brandName,
            'shop' => ProductShopResource::make($this->shop),
            'category' => $this->categories()->first()?->name,
        ];
    }
}
