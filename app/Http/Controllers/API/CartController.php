<?php

namespace App\Http\Controllers\API;

use App\Models\Cart;
use App\Models\Address;
use App\Models\Shop;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\CartAccessToken;
use App\Http\Requests\CartRequest;
use App\Http\Controllers\Controller;
use App\Repositories\CartRepository;
use App\Http\Requests\CheckoutRequest;
use App\Repositories\ProductRepository;

class CartController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $isBuyNow = request()->is_buy_now ?? false;
        $carts =userCart($request)->where('is_buy_now', $isBuyNow)->get();
        $groupCart = $carts->groupBy('shop_id');
        $result = CartRepository::ShopWiseCartProducts($groupCart);

        return $this->json('cart list', [
            'total' => $result['total_items'],
            'cart_items' => $result['shop_wise_products'],
            'info' => $result['info'],
        ], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CartRequest $request)
    {
        $tokens = cartAccessToken(request());

        if(!$tokens['customer_id'] && !$tokens['access_token']){
            $accessToken = Str::uuid()->toString();
            CartAccessToken::create([
                'access_token' => $accessToken,
            ]);
            return $this->json('Access token generated', [
                'access_token' => $accessToken,
            ]);
        }

        if(!$tokens['customer_id'] && !$tokens['access_token']){
            return $this->json('login first', [], 422);
        }

        $isBuyNow = $request->is_buy_now ?? false;
        $processingType = $request->processing_type ?? 'raw';

        $product = ProductRepository::find($request->product_id);

        if (! $product) {
            return $this->json('Product not available now.', [], 422);
        }

        if (
            $processingType === 'processed'
            && (! $product->processing_available || is_null($product->processed_price))
        ) {
            return $this->json('Processed option is not available for this product.', [], 422);
        }

        $quantity = $request->quantity ?? 1;
        $cart = userCart($request)
            ->where('product_id', $product->id)
            ->where('is_buy_now', $isBuyNow)
            ->where('processing_type', $processingType)
            ->first();

        if ($isBuyNow) {
            $buyNowCart =userCart($request)->where('is_buy_now', true)->first();

            if (
                $buyNowCart
                && ($buyNowCart->product_id != $request->product_id || $buyNowCart->processing_type !== $processingType)
            ) {
                $buyNowCart->delete();
            }
        }

        if (($product->quantity < $quantity) || ($product->quantity <= $cart?->quantity)) {
            return $this->json('Sorry! product cart quantity is limited. No more stock', [], 422);
        }

        // store or update cart
        CartRepository::storeOrUpdateByRequest($request, $product);
        $carts=userCart($request)->where('is_buy_now', $isBuyNow)->get();

        $groupCart = $carts->groupBy('shop_id');
        $result = CartRepository::ShopWiseCartProducts($groupCart);

        return $this->json('product added to cart', [
            'total' => $result['total_items'],
            'cart_items' => $result['shop_wise_products'],
            'info' => $result['info'],
        ], 200);
    }

    /**
     * increase cart quantity
     */
    public function increment(CartRequest $request)
    {
        $isBuyNow = $request->is_buy_now ?? false;
        $processingType = $request->processing_type ?? 'raw';

        $product = ProductRepository::find($request->product_id);

        if (! $product) {
            return $this->json('Product not available now.', [], 422);
        }
        $cart = userCart($request)
            ->where('product_id', $product->id)
            ->where('is_buy_now', $isBuyNow)
            ->where('processing_type', $processingType)
            ->first();

        if (! $cart) {
            return $this->json('Sorry product not found in cart', [], 422);
        }

        $quantity = $cart->quantity;

        $flashSale = $product->flashSales?->first();

        $flashSaleProduct = $flashSale?->products()->where('id', $product->id)->first();

        $productQty = $product->quantity;

        if ($flashSaleProduct) {
            $flashSaleQty = $flashSaleProduct->pivot->quantity - $flashSaleProduct->pivot->sale_quantity;

            if ($flashSaleQty > 0) {
                $productQty = $flashSaleQty;
            }
        }

        if ($productQty > $quantity) {
            $cart->update([
                'quantity' => $quantity + 1,
            ]);
        } else {
            return $this->json('Sorry! product cart quantity is limited. No more stock', [], 422);
        }
        $carts =userCart($request)->where('is_buy_now', $isBuyNow)->get();
        $groupCart = $carts->groupBy('shop_id');
        $result = CartRepository::ShopWiseCartProducts($groupCart);

        return $this->json('product quantity increased', [
            'total' => $result['total_items'],
            'cart_items' => $result['shop_wise_products'],
            'info' => $result['info'],
        ], 200);
    }

    /**
     * decrease cart quantity
     * */
    public function decrement(CartRequest $request)
    {
        $isBuyNow = $request->is_buy_now ?? false;
        $processingType = $request->processing_type ?? 'raw';

        $product = ProductRepository::find($request->product_id);

        if (! $product) {
            return $this->json('Product not available now.', [], 422);
        }
        $cart = userCart($request)
            ->where('product_id', $product->id)
            ->where('is_buy_now', $isBuyNow)
            ->where('processing_type', $processingType)
            ->first();

        if (! $cart) {
            return $this->json('Sorry product not found in cart', [], 422);
        }

        $message = 'product removed from cart';

        if ($cart->quantity > 1) {
            $cart->update([
                'quantity' => $cart->quantity - 1,
            ]);

            $message = 'product quantity decreased';
        } else {
            $cart->delete();
        }
        $carts =userCart($request)->where('is_buy_now', $isBuyNow)->get();
        $groupCart = $carts->groupBy('shop_id');
        $result = CartRepository::ShopWiseCartProducts($groupCart);

        return $this->json($message, [
            'total' => $result['total_items'],
            'cart_items' => $result['shop_wise_products'],
            'info' => $result['info'],
        ], 200);
    }

    public function checkout(CheckoutRequest $request)
    {
        $isBuyNow = $request->is_buy_now ?? false;
        $tokens = cartAccessToken(request());

        $shopIds = collect($request->shop_ids ?? [])
            ->filter()
            ->map(fn($shopId) => (int) $shopId)
            ->unique()
            ->values();

        if ($shopIds->isEmpty()) {
            $shopIds = userCart($request)->where('is_buy_now', $isBuyNow)->pluck('shop_id')->unique()->values();
        }

        $eligibleShopIds = $this->eligibleShopIdsForCheckout($request, $shopIds);

        $carts = userCart($request)
            ->whereIn('shop_id', $eligibleShopIds)
            ->where('is_buy_now', $isBuyNow)
            ->get();

        $checkout = CartRepository::checkoutByRequest($request, $carts);

        $groupCart = $carts->groupBy('shop_id');
        $result = CartRepository::ShopWiseCartProducts($groupCart);

        $message = 'Checkout information';

        $applyCoupon = false;

        if ($request->coupon_code && $checkout['coupon_discount'] > 0 && $tokens['customer_id']) {
            $applyCoupon = true;
            $message = 'Coupon applied';
        } elseif ($request->coupon_code) {
            $message = 'Coupon not applied';
        }

        return $this->json($message, [
            'checkout' => $checkout,
            'apply_coupon' => $applyCoupon,
            'eligible_shop_ids' => $eligibleShopIds->values(),
            'checkout_items' => $result['shop_wise_products'],
        ]);
    }

    public function destroy(CartRequest $request)
    {
        $isBuyNow = $request->is_buy_now ?? false;
        $processingType = $request->processing_type ?? 'raw';

        $carts = userCart($request)
            ->where('product_id', $request->product_id)
            ->where('processing_type', $processingType)
            ->get();

        if ($carts->isEmpty()) {
            return $this->json('Sorry product not found in cart', [], 422);
        }

        foreach ($carts as $cart) {
            $cart->delete();
        }
        $carts =userCart($request)->where('is_buy_now', $isBuyNow)->get();
        $groupCart = $carts->groupBy('shop_id');
        $result = CartRepository::ShopWiseCartProducts($groupCart);

        return $this->json('product removed from cart', [
            'total' => $carts->count(),
            'cart_items' => $result['shop_wise_products'],
            'info' => $result['info'],
        ], 200);
    }

    private function eligibleShopIdsForCheckout(Request $request, $shopIds)
    {
        $shopIds = collect($shopIds)->filter()->map(fn($shopId) => (int) $shopId)->unique()->values();
        if ($shopIds->isEmpty()) {
            return $shopIds;
        }

        $address = null;
        if ($request->filled('address_id')) {
            $address = Address::query()->find($request->integer('address_id'));
        }

        $countyId = $request->integer('county_id') ?: $address?->county_id ?: auth()->user()?->county_id;
        $subcountyId = $request->integer('subcounty_id') ?: $address?->subcounty_id ?: auth()->user()?->subcounty_id;

        if (! $subcountyId) {
            return $shopIds;
        }

        return Shop::query()
            ->whereIn('id', $shopIds)
            ->where('subcounty_id', $subcountyId)
            ->when($countyId, function ($query) use ($countyId) {
                $query->where('county_id', $countyId);
            })
            ->pluck('id')
            ->map(fn($shopId) => (int) $shopId)
            ->values();
    }
}
