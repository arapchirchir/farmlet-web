<?php

namespace App\Repositories;

use App\Models\Shop;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Address;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ProcessingRoom;
use App\Enums\OrderStatus;
use App\Enums\DiscountType;
use App\Models\AdminCoupon;
use App\Models\OrderVatTax;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\OrderMailEvent;
use App\Models\CartAccessToken;
use App\Models\GeneraleSetting;
use App\Http\Requests\OrderRequest;
use App\Repositories\CurrencyRepository;
use App\Repositories\EscrowLedgerRepository;
use App\Repositories\DriverRepository;
use Abedin\Maker\Repositories\Repository;

class OrderRepository extends Repository
{
    /**
     * base method
     *
     * @method model()
     */
    public static function model()
    {
        return Order::class;
    }

    public static function getShopSales($shopId)
    {
        return self::query()->withoutGlobalScopes()->where('shop_id', $shopId)->get();
    }

    /**
     * Store new order from cart
     */
    public static function storeByRequestFromCart(OrderRequest $request, $paymentMethod, $carts)
    {
        $totalPayableAmount = 0;

        $payment = Payment::create([
            'amount' => $totalPayableAmount,
            'payment_method' => $request->payment_method,
        ]);
        $tokens = cartAccessToken(request());
        $customer = Customer::firstWhere('id', $tokens['customer_id']);

        $shopProducts = $carts->groupBy('shop_id');

        foreach ($shopProducts as $shopId => $cartProducts) {

            $shop = Shop::find($shopId);

            $getCartAmounts = self::getCartWiseAmounts($shop, collect($cartProducts), $request->coupon_code);

            $order = self::createNewOrder($request, $shop, $paymentMethod, $getCartAmounts);

            $totalPayableAmount += $getCartAmounts['payableAmount'];
            $payment->orders()->attach($order->id);

            foreach ($cartProducts as $cart) {

                $cart->product->decrement('quantity', $cart->quantity);

                $product = $cart->product;
                $processingType = $cart->processing_type ?? 'raw';
                $isProcessed = $processingType === 'processed'
                    && (bool) $product->processing_available
                    && ! is_null($product->processed_price);
                $rawPrice = $product->raw_price ?? $product->price;
                $price = $isProcessed
                    ? (float) $product->processed_price
                    : ($product->discount_price > 0 ? $product->discount_price : $rawPrice);

                $flashSale = $product->flashSales?->first();
                $flashSaleProduct = null;
                $quantity = 0;

                $saleQty = $cart->quantity;

                if ($flashSale && ! $isProcessed) {
                    $flashSaleProduct = $flashSale?->products()->where('id', $product->id)->first();

                    $quantity = $flashSaleProduct?->pivot->quantity - $flashSaleProduct->pivot->sale_quantity;

                    if ($quantity == 0) {
                        $flashSaleProduct = null;
                    } else {
                        $price = $flashSaleProduct->pivot->price;
                        $saleQty += $flashSaleProduct->pivot->sale_quantity;

                        $flashSale->products()->updateExistingPivot($product->id, [
                            'sale_quantity' => $saleQty,
                        ]);
                    }
                }

                $order->products()->attach($product->id, [
                    'quantity' => $cart->quantity,
                    'unit' => $cart->unit,
                    'price' => $price,
                    'processing_type' => $processingType,
                    'buying_price' => $product->buyingPrice() ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (function_exists('module_exists') && module_exists('Purchase')) {
                    $order->productStockOuts()->create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'quantity' => $cart->quantity
                    ]);
                }
            }

            foreach ($getCartAmounts['allVatTaxes'] ?? [] as $vatTax) {
                if (! $vatTax) continue;

                OrderVatTax::create([
                    'order_id' => $order->id,
                    'name' => $vatTax->name,
                    'percentage' => $vatTax->percentage,
                    'amount' => $vatTax->amount,
                ]);
            }

            $user = $customer->user ?? null;
            if ($user?->email) {
                try {
                    OrderMailEvent::dispatch($user->email, $order);
                } catch (\Throwable $th) {
                }
            }

            self::createEscrowEntries($order);
        }

        $payment->update([
            'amount' => $totalPayableAmount,
        ]);

        $isBuyNow = $request->is_buy_now ?? false;
        userCart(request())->whereIn('shop_id', $request->shop_ids)->where('is_buy_now', $isBuyNow)->delete();

        CartAccessToken::where('access_token', $tokens['access_token'])->delete();

        return [$payment, $order];
    }

    private static function createNewOrder($request, $shop, $paymentMethod, $getCartAmounts)
    {
        $lastOrderId = self::query()->max('id');

        $currency = Currency::where('id', $request->currencyData['id'])->first();
        $tokens = cartAccessToken(request());
        $address = Address::find($request->address_id);
        $orderType = $getCartAmounts['orderType'] ?? 'raw';
        $processingRoomId = self::resolveProcessingRoomId($orderType, $address);

        $order = self::create([
            'shop_id' => $shop->id,
            'order_type' => $orderType,
            'county_id' => $address?->county_id,
            'subcounty_id' => $address?->subcounty_id,
            'ward_id' => $address?->ward_id,
            'order_code' => str_pad($lastOrderId + 1, 6, '0', STR_PAD_LEFT),
            'prefix' => $shop->prefix ?? 'RG',
            'customer_id' => $tokens['customer_id'] ?? null,
            'vendor_id' => $shop->user_id,
            'driver_id' => null,
            'processing_room_id' => $processingRoomId,
            'coupon_id' => $getCartAmounts['coupon'],
            'delivery_charge' => $getCartAmounts['deliveryCharge'],
            'payable_amount' => $getCartAmounts['payableAmount'],
            'total_amount' => $getCartAmounts['totalAmount'],
            'tax_amount' => $getCartAmounts['totalTaxAmount'],
            'coupon_discount' => $getCartAmounts['discount'],
            'payment_method' => $paymentMethod->value,
            'order_status' => OrderStatus::PENDING->value,
            'address_id' => $request->address_id,
            'instruction' => $request->note,
            'payment_status' => PaymentStatus::PENDING->value,
            'currency_symbol' => $currency->symbol,
            'currency_rate' => $currency->rate
        ]);

        $generalSetting = generaleSetting('setting');

        if ($generalSetting?->business_based_on == 'subscription') {
            $subscription = $shop->currentSubscription;

            if ($subscription && $subscription->remaining_sales && $subscription->remaining_sales > 0) {
                $subscription->update([
                    'remaining_sales' => $subscription->remaining_sales - 1
                ]);
            }
        }

        return $order;
    }

    private static function resolveProcessingRoomId(string $orderType, ?Address $address): ?int
    {
        if ($orderType !== 'processed') {
            return null;
        }

        if (! $address?->subcounty_id) {
            throw new \RuntimeException('Processed orders require a delivery address with sub-county.');
        }

        $processingRoom = ProcessingRoom::query()
            ->where('is_active', true)
            ->where('subcounty_id', $address->subcounty_id)
            ->when($address->county_id, function ($query) use ($address) {
                $query->where('county_id', $address->county_id);
            })
            ->orderBy('id')
            ->first();

        if (! $processingRoom) {
            throw new \RuntimeException('No processing room is available for the selected sub-county.');
        }

        return (int) $processingRoom->id;
    }

    private static function getCartWiseAmounts(Shop $shop, $carts, $couponCode = null): array
    {
        $totalAmount = 0;
        $discount = 0;
        $coupon = null;
        $totalTaxAmount = 0;
        $orderType = 'raw';

        $orderQty = $carts->sum('quantity');
        $deliveryCharge = getDeliveryCharge($orderQty);

        $allVatTaxes = [];

        foreach ($carts ?? [] as $cart) {

            if (! $cart) {
                continue;
            }

            $product = $cart->product;
            $processingType = $cart->processing_type ?? 'raw';
            $isProcessed = $processingType === 'processed'
                && (bool) $product->processing_available
                && ! is_null($product->processed_price);
            if ($isProcessed) {
                $orderType = 'processed';
            }
            $rawPrice = $product->raw_price ?? $product->price;
            $price = $isProcessed
                ? (float) $product->processed_price
                : ($product->discount_price > 0 ? $product->discount_price : $rawPrice);

            $flashSale = $product->flashSales?->first();
            $flashSaleProduct = null;
            $quantity = 0;

            if ($flashSale && ! $isProcessed) {
                $flashSaleProduct = $flashSale?->products()->where('id', $product->id)->first();

                $quantity = $flashSaleProduct?->pivot->quantity - $flashSaleProduct->pivot->sale_quantity;

                if ($quantity == 0) {
                    $flashSaleProduct = null;
                } else {
                    $price = $flashSaleProduct->pivot->price;
                }
            }

            $totalAmount += ($price * $cart->quantity);
        }

        // order vat taxes
        $vatTaxes = VatTaxRepository::getActiveVatTaxes();

        foreach ($vatTaxes ?? [] as $vatTax) {
            if ($vatTax?->name && $vatTax?->percentage > 0) {
                $taxAmount = round($totalAmount * ($vatTax->percentage / 100), 2);

                $allVatTaxes[] = (object) [
                    'name' => $vatTax->name,
                    'percentage' => $vatTax->percentage,
                    'amount' => $taxAmount,
                ];

                $totalTaxAmount += $taxAmount;
            }
        }

        // get coupon discount
        $couponDiscount = self::getCouponDiscount($totalAmount, $shop->id, $couponCode);

        // check coupon discount amount
        if ($couponDiscount['total_discount_amount'] > 0) {
            $discount += $couponDiscount['total_discount_amount'];
            $coupon = $couponDiscount['coupon'];
        }

        // calculate payable amount
        $payableAmount = ($totalAmount + $deliveryCharge + $totalTaxAmount) - $discount;

        // return array
        return [
            'totalAmount' => $totalAmount,
            'totalTaxAmount' => $totalTaxAmount,
            'payableAmount' => $payableAmount,
            'discount' => $discount,
            'deliveryCharge' => $deliveryCharge,
            'coupon' => $coupon?->id,
            'orderType' => $orderType,
            'allVatTaxes' => $allVatTaxes,
        ];
    }

    /**
     * Creates a new order based on the provided order, generates a new order code,
     * and associates it with the corresponding shop orders and products.
     *
     * @param  Order  $order  The original order to be used as a base for the new order
     * @return Order The newly created order
     */
    public static function reOrder(Order $order, $payment): Order
    {
        $lastOrderId = self::query()->max('id');

        $newOrder = self::create([
            'shop_id' => $order->shop_id,
            'order_type' => $order->order_type ?? 'raw',
            'county_id' => $order->county_id,
            'subcounty_id' => $order->subcounty_id,
            'ward_id' => $order->ward_id,
            'order_code' => str_pad($lastOrderId + 1, 6, '0', STR_PAD_LEFT),
            'prefix' => 'RG',
            'customer_id' => $order->customer_id,
            'vendor_id' => $order->vendor_id ?? $order->shop?->user_id,
            'driver_id' => null,
            'processing_room_id' => $order->processing_room_id,
            'coupon_id' => $order->coupon_id ?? null,
            'delivery_charge' => $order->delivery_charge,
            'payable_amount' => $order->payable_amount,
            'total_amount' => $order->total_amount,
            'tax_amount' => $order->tax_amount,
            'coupon_discount' => $order->coupon_discount,
            'payment_method' => $payment->payment_method ?? $order->payment_method,
            'order_status' => OrderStatus::PENDING->value,
            'address_id' => $order->address_id,
            'instruction' => $order->instruction,
            'payment_status' => PaymentStatus::PENDING->value,
        ]);

        foreach ($order->products as $product) {

            $qty = $product->pivot->quantity;

            $product->decrement('quantity', $qty);

            $newOrder->products()->attach($product->id, [
                'quantity' => $product->pivot->quantity,
                'unit' => $product->unit ?? null,
                'price' => $product->pivot->price,
                'processing_type' => $product->pivot->processing_type ?? 'raw',
                'buying_price' => $product->buyingPrice() ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($order->vatTaxes ?? [] as $vatTax) {
            if (! $vatTax) {
                continue;
            }
            OrderVatTax::create([
                'order_id' => $newOrder->id,
                'name' => $vatTax->name,
                'percentage' => $vatTax->percentage,
                'amount' => $vatTax->amount,
            ]);
        }

        $user = auth()->user();
        if ($user?->email) {
            try {
                OrderMailEvent::dispatch($user->email, $newOrder);
            } catch (\Throwable $th) {
            }
        }

        self::createEscrowEntries($newOrder);

        return $newOrder;
    }

    /**
     * Get applied coupon orders
     *
     * @param  mixed  $coupon
     * @return collection
     */
    public static function getAppliedCouponOrders($coupon)
    {
        $tokens = cartAccessToken(request());
        $customer = Customer::firstWhere('id', $tokens['customer_id']) ?? null;
        return $customer?->orders()?->where('coupon_id', $coupon->id)->get() ?? [];
    }

    /**
     * Get coupon discount
     *
     * @param  mixed  $totalAmount
     * @param  mixed  $shopId
     * @param  mixed  $couponCode
     * @return array
     */
    public static function getCouponDiscount($totalAmount, $shopId, $couponCode = null)
    {
        $totalOrderAmount = 0;
        $totalDiscountAmount = 0;
        $coupon = null;

        if ($couponCode) {
            $shop = Shop::find($shopId);
            $coupon = $shop->coupons()->where('code', $couponCode)->Active()->isValid()->first();

            if (! $coupon) {
                $coupon = AdminCoupon::where('shop_id', $shopId)->whereHas('coupon', function ($query) use ($couponCode) {
                    $query->where('code', $couponCode)->Active()->isValid();
                })->first()?->coupon;
            }

            if ($coupon) {
                $discount = self::getCouponDiscountAmount($coupon, $totalAmount);
                $totalOrderAmount += $discount['total_amount'];
                $totalDiscountAmount += $discount['discount_amount'];
            }
        } else {

            $collectedCoupons = CouponRepository::getCollectedCoupons($shopId);

            foreach ($collectedCoupons as $collectedCoupon) {

                $discount = self::getCouponDiscountAmount($collectedCoupon, $totalAmount);

                $totalOrderAmount += $discount['total_amount'];

                if ($discount['discount_amount'] > 0) {
                    $coupon = $collectedCoupon;
                    $totalDiscountAmount += $discount['discount_amount'];
                    break;
                }
            }
        }

        return [
            'total_order_amount' => $totalOrderAmount,
            'total_discount_amount' => $totalDiscountAmount,
            'coupon' => $coupon,
        ];
    }

    /**
     * Get coupon discount amount
     *
     * @param  mixed  $coupon
     * @param  mixed  $totalAmount
     * @return array
     */
    private static function getCouponDiscountAmount($coupon, $totalAmount)
    {
        $appliedOrders = self::getAppliedCouponOrders($coupon);

        $amount = $coupon->type->value == DiscountType::PERCENTAGE->value ? ($totalAmount * $coupon->discount) / 100 : $coupon->discount;

        $couponDiscount = 0;
        if ($appliedOrders->count() < ($coupon->limit_for_user ?? 500) && $coupon->min_amount <= $totalAmount) {
            $couponDiscount = $amount;
            if ($coupon->max_discount_amount && $coupon->max_discount_amount < $amount) {
                $couponDiscount = $coupon->max_discount_amount;
            }
        }

        return [
            'total_amount' => $totalAmount,
            'discount_amount' => (float) round($couponDiscount ?? 0, 2),
        ];
    }

    /**
     * Order status update from rider
     */
    public static function OrderStatusUpdateFromRider(Order $order, $driverOrder, $orderStatus)
    {
        if (
            $orderStatus == OrderStatus::PROCESSING->value
            || $orderStatus == OrderStatus::PICKUP_FOR_PROCESSING->value
        ) {
            $driverOrder->update(['is_accept' => true]);
        }

        $order->update([
            'order_status' => $orderStatus,
        ]);

        if ($orderStatus == OrderStatus::ON_THE_WAY->value) {
            $order->update([
                'pick_date' => now(),
            ]);
        }

        if ($orderStatus == OrderStatus::DELIVERED->value && ! $order->driver_delivery_confirmed_at) {
            $order->update([
                'driver_delivery_confirmed_at' => now(),
                'delivered_at' => $order->delivered_at ?? now(),
            ]);
        }

        self::releaseSettlementIfEligible($order, $driverOrder);

        $order->refresh();
    }

    public static function confirmCustomerDelivery(Order $order): void
    {
        if ($order->order_status->value !== OrderStatus::DELIVERED->value) {
            throw new \RuntimeException('Delivery can only be confirmed after the order is marked delivered.');
        }

        if (! $order->customer_delivery_confirmed_at) {
            $order->update([
                'customer_delivery_confirmed_at' => now(),
            ]);
        }

        self::releaseSettlementIfEligible($order);
    }

    public static function OrderStatusUpdateFromProcessingManager(Order $order, string $orderStatus): void
    {
        if (($order->order_type ?? 'raw') !== 'processed') {
            throw new \RuntimeException('Only processed orders can be updated by a processing manager.');
        }

        $currentStatus = $order->order_status->value;

        $allowedTransitions = [
            OrderStatus::PICKUP_FOR_PROCESSING->value => OrderStatus::PROCESSING->value,
            OrderStatus::AT_PROCESSING_ROOM->value => OrderStatus::PROCESSING->value,
            OrderStatus::PROCESSING->value => OrderStatus::READY_FOR_DELIVERY->value,
        ];

        if (
            ! isset($allowedTransitions[$currentStatus])
            || $allowedTransitions[$currentStatus] !== $orderStatus
        ) {
            throw new \RuntimeException('Invalid processed-order transition requested.');
        }

        $order->update([
            'order_status' => $orderStatus,
        ]);

        $order->refresh();
    }

    public static function releaseSettlementIfEligible(Order $order, $driverOrder = null): bool
    {
        if (config('farmlet.escrow_mode', 'simulation') !== 'simulation') {
            throw new \RuntimeException('Only simulation escrow mode is supported.');
        }

        if ($order->settlement_released_at) {
            return false;
        }

        if ($order->order_status->value !== OrderStatus::DELIVERED->value) {
            return false;
        }

        if (! $order->driver_delivery_confirmed_at || ! $order->customer_delivery_confirmed_at) {
            return false;
        }

        $driverOrder = $driverOrder ?: $order->driverOrder;
        if (! $driverOrder) {
            return false;
        }

        $paymentMethod = $order->payment_method->value;

        if ($paymentMethod == PaymentMethod::CASH->value && ! $driverOrder->cash_collect) {
            $driverOrder->update(['cash_collect' => true]);

            $totalCashCollected = $driverOrder->driver->total_cash_collected + $order->payable_amount;
            $driverOrder->driver->update([
                'total_cash_collected' => $totalCashCollected,
            ]);
        }

        $generaleSetting = GeneraleSetting::first();

        $commission = 0;
        if ($generaleSetting?->business_based_on == 'commission' && $generaleSetting?->commission_charge != 'monthly') {
            if ($generaleSetting?->commission_type != 'fixed') {
                $commission = $order->total_amount * $generaleSetting->commission / 100;
            } else {
                $commission = $generaleSetting->commission ?? 0;
            }
        }

        // Release Escrow
        $ledgers = EscrowLedgerRepository::query()->where('order_id', $order->id)->where('status', 'held')->get();
        
        foreach ($ledgers as $ledger) {
            EscrowLedgerRepository::release($ledger);
        }

        // --- Original Settlement Logic (Credits Wallets) ---

        $order->update([
            'delivery_date' => $order->delivery_date ?? now(),
            'delivered_at' => $order->delivered_at ?? now(),
            'payment_status' => PaymentStatus::PAID->value,
            'admin_commission' => $commission,
            'settlement_released_at' => now(),
        ]);

        $wallet = $order->shop->user->wallet;
        if (! $wallet) {
            $wallet = WalletRepository::storeByRequest($order->shop->user);
        }

        WalletRepository::updateByRequest($wallet, $order->total_amount, 'credit');

        if ($generaleSetting?->business_based_on == 'commission') {
            TransactionRepository::storeByRequest($wallet, $commission, 'debit', true, true, 'admin commission added', 'order commission added in admin wallet');
        }

        $driverWallet = DriverRepository::getWallet($driverOrder->driver);
        WalletRepository::updateByRequest($driverWallet, $order->delivery_charge, 'credit');

        $driverOrder->update(['is_completed' => true]);

        return true;
    }

    public static function createEscrowEntries(Order $order): void
    {
        $generaleSetting = GeneraleSetting::first();

        // 1. Admin Commission
        $commission = 0;
        if ($generaleSetting?->business_based_on == 'commission' && $generaleSetting?->commission_charge != 'monthly') {
            if ($generaleSetting?->commission_type != 'fixed') {
                $commission = $order->total_amount * $generaleSetting->commission / 100;
            } else {
                $commission = $generaleSetting->commission ?? 0;
            }
        }

        if ($commission > 0) {
            $adminWallet = WalletRepository::getAdminWallet();
            EscrowLedgerRepository::store(
                order: $order,
                actorId: $adminWallet->user_id,
                actorType: 'admin',
                amount: $commission,
                type: 'commission',
                description: 'Admin commission',
                walletId: $adminWallet->id
            );
        }

        // 2. Vendor Amount (Total - Commission)
        // Note: Total amount usually includes product price. Logic here simplifies "Order Total" as vendor's share.
        // In a real split, we'd subtract commission from this, but the original logic credits "total_amount" to vendor 
        // and then debits "commission". We will mirror that for "Held" state but split if needed.
        // Consistent with release logic: Credit Total, Debit Commission.
        
        $vendorWallet = $order->shop->user->wallet;
        if (!$vendorWallet) {
             $vendorWallet = WalletRepository::storeByRequest($order->shop->user);
        }

        EscrowLedgerRepository::store(
            order: $order,
            actorId: $order->shop->user_id,
            actorType: 'vendor',
            amount: $order->total_amount,
            type: 'item_price',
            description: 'Order item total',
            walletId: $vendorWallet->id
        );

        // 3. Driver Fee
        // Current logic: Driver fee is `delivery_charge`.
        // At creation, driver might be null. 
        if ($order->delivery_charge > 0) {
             // If driver is assigned later, we might need to update this or create it then.
             // For now, we create it with null actor/wallet if driver not assigned.
             $driverId = $order->driver_id ? $order->driver->user_id : null;
             $driverWalletId = null;
             
             if ($order->driver_id) {
                 $driverWallet = DriverRepository::getWallet($order->driver);
                 $driverWalletId = $driverWallet->id;
             }
             
             EscrowLedgerRepository::store(
                order: $order,
                actorId: $driverId, 
                actorType: 'driver',
                amount: $order->delivery_charge,
                type: 'delivery_fee',
                description: 'Delivery fee',
                walletId: $driverWalletId
            );
        }

        // 4. VAT/Tax
        foreach ($order->vatTaxes as $vatTax) {
             // Assuming VAT goes to Admin or specific Tax Authority wallet (Admin for now)
             $adminWallet = WalletRepository::getAdminWallet();
             EscrowLedgerRepository::store(
                order: $order,
                actorId: $adminWallet->user_id, 
                actorType: 'admin',
                amount: $vatTax->amount,
                type: 'vat',
                description: $vatTax->name,
                walletId: $adminWallet->id
            );
        }
    }
}
