<?php

namespace App\Http\Controllers\API;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\SMSConfig;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Address;
use App\Models\Shop;
use App\Models\Subcounty;
use App\Models\Ward;
use App\Models\VerifyManage;
use Illuminate\Http\Request;
use App\Services\TwilioService;
use App\Http\Requests\OrderIdRequest;
use App\Http\Requests\OrderRequest;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Http\Resources\OrderResource;
use App\Repositories\OrderRepository;
use Illuminate\Support\Facades\Cache;
use App\Repositories\AddressRepository;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\OrderDetailsResource;

class OrderController extends Controller
{
    /**
     * Display a listing of the orders with status filter and pagination options.
     *
     * @param  Request  $request  The HTTP request
     * @return Some_Return_Value json Response
     *
     * @throws Some_Exception_Class If something goes wrong
     */
    public function index(Request $request)
    {
        $orderStatus = $request->order_status;

        $page = $request->page;
        $perPage = $request->per_page;
        $skip = ($page * $perPage) - $perPage;

        $customer = auth()->user()->customer;

        $orders = $customer->orders()->when($orderStatus, function ($query) use ($orderStatus) {
            return $query->where('order_status', $orderStatus);
        })->latest('id');

        $total = $orders->count();

        // paginate
        $orders = $orders->when($perPage && $page, function ($query) use ($perPage, $skip) {
            return $query->skip($skip)->take($perPage);
        })->get();

        // status wise orders
        $statusWiseOrders = collect(OrderStatus::cases())->mapWithKeys(function ($status) use ($customer) {
            return [$status->value => $customer->orders()->where('order_status', $status->value)->count()];
        });

        // Response
        return $this->json('orders', [
            'total' => $total,
            'status_wise_orders' => [
                'all' => $customer->orders()->count(),
                'pending' => $statusWiseOrders[OrderStatus::PENDING->value],
                'confirm' => $statusWiseOrders[OrderStatus::CONFIRM->value],
                'vendor_preparing' => $statusWiseOrders[OrderStatus::VENDOR_PREPARING->value],
                'pickup_for_processing' => $statusWiseOrders[OrderStatus::PICKUP_FOR_PROCESSING->value],
                'at_processing_room' => $statusWiseOrders[OrderStatus::AT_PROCESSING_ROOM->value],
                'processing' => $statusWiseOrders[OrderStatus::PROCESSING->value],
                'ready_for_delivery' => $statusWiseOrders[OrderStatus::READY_FOR_DELIVERY->value],
                'pickup' => $statusWiseOrders[OrderStatus::PICKUP->value],
                'on_the_way' => $statusWiseOrders[OrderStatus::ON_THE_WAY->value],
                'delivered' => $statusWiseOrders[OrderStatus::DELIVERED->value],
                'cancelled' => $statusWiseOrders[OrderStatus::CANCELLED->value],
            ],
            'orders' => OrderResource::collection($orders),
        ]);
    }

    /**
     * Store a newly created order in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(OrderRequest $request)
    {
        // dd($request->all());
        $tokens = cartAccessToken(request());
        $customer =Customer::firstWhere('id', $tokens['customer_id']) ?? null;

        $address = null;
        if (!$request->address_id) {
            $validated = Validator::make(
                $request->all(),
                (new AddressRequest())->rules()
            )->validate();

            $countyId = $validated['county_id'] ?? null;
            $subcountyId = $validated['subcounty_id'] ?? null;
            $wardId = $validated['ward_id'] ?? null;

            if ($countyId && $subcountyId) {
                $subcounty = Subcounty::find($subcountyId);
                if ($subcounty && $subcounty->county_id != $countyId) {
                    return $this->json('Selected sub-county does not belong to the selected county', [], 422);
                }
            }

            if ($subcountyId && $wardId) {
                $ward = Ward::find($wardId);
                if ($ward && $ward->subcounty_id != $subcountyId) {
                    return $this->json('Selected ward does not belong to the selected sub-county', [], 422);
                }
            }
            $validatedRequest = new Request($validated);

            $address = AddressRepository::storeByGuestUser($validatedRequest);
            $request->merge(['address_id' => $address->id]);
            userCart($request)->update([
                'customer_id' => $address->customer_id,
            ]);
            $customer =Customer::firstWhere('id', $address->customer_id) ?? null;
        } else {
            $address = Address::find($request->address_id);
        }
        $isBuyNow = $request->is_buy_now ?? false;

        // dd($customer);

        $user = $customer->user ?? null;

        if (! $user) {
            return $this->json('User not found', [], 422);
        }

        if (! $address) {
            return $this->json('Address not found', [], 422);
        }

        if (! $address->subcounty_id) {
            return $this->json('Address must be linked to a sub-county', [], 422);
        }

        if ($address->subcounty_id && ! $address->county_id) {
            return $this->json('Address county is required when sub-county is selected', [], 422);
        }

        if ($address->ward_id && ! $address->subcounty_id) {
            return $this->json('Address sub-county is required when ward is selected', [], 422);
        }

        if ($address->county_id && $address->subcounty_id) {
            $addressSubcounty = Subcounty::find($address->subcounty_id);
            if (! $addressSubcounty || (int) $addressSubcounty->county_id !== (int) $address->county_id) {
                return $this->json('Address sub-county does not belong to the selected county', [], 422);
            }
        }

        if ($address->subcounty_id && $address->ward_id) {
            $addressWard = Ward::find($address->ward_id);
            if (! $addressWard || (int) $addressWard->subcounty_id !== (int) $address->subcounty_id) {
                return $this->json('Address ward does not belong to the selected sub-county', [], 422);
            }
        }

        $requestedShopIds = collect($request->shop_ids ?? [])
            ->filter()
            ->map(fn($shopId) => (int) $shopId)
            ->unique()
            ->values();

        if ($requestedShopIds->isEmpty()) {
            return $this->json('No shops were selected for this order', [], 422);
        }

        $eligibleShopQuery = Shop::query()
            ->whereIn('id', $requestedShopIds)
            ->whereNotNull('subcounty_id')
            ->where('subcounty_id', $address->subcounty_id);

        if ($address->county_id) {
            $eligibleShopQuery->whereNotNull('county_id')->where('county_id', $address->county_id);
        }

        $eligibleShopIds = $eligibleShopQuery->pluck('id')->map(fn($id) => (int) $id)->values();

        if ($eligibleShopIds->isEmpty()) {
            return $this->json('No eligible sellers found in the selected sub-county', [], 422);
        }

        $request->merge([
            'shop_ids' => $eligibleShopIds->all(),
        ]);
        // $user = auth()->user();

        $verifyManage = Cache::rememberForever('verify_manage', function () {
            return VerifyManage::first();
        });

        $accountVerified = false;
        if ($user->email_verified_at || $user->phone_verified_at) {
            $accountVerified = true;
        }

        if ($verifyManage?->order_place_account_verify && ! $accountVerified) {
            return $this->json('Please verify your account first. without verify account you can not place order', [], 422);
        }

        // $carts = $user->customer?->carts()->whereIn('shop_id', $request->shop_ids)->where('is_buy_now', $isBuyNow)->get();
        // $carts = $customer?->carts()->whereIn('shop_id', $request->shop_ids)->where('is_buy_now', $isBuyNow)->get();
        $carts = userCart(request())
            ->whereIn('shop_id', $request->shop_ids)
            ->where('is_buy_now', $isBuyNow)
            ->get();
        // dd($carts);
        if ($carts->isEmpty()) {
            return $this->json('Sorry shop cart is empty', [], 422);
        }

        $toUpper = strtoupper($request->payment_method);
        $paymentMethods = PaymentMethod::cases();

        $paymentMethod = $paymentMethods[array_search($toUpper, array_column(PaymentMethod::cases(), 'name'))];

        try {
            // Store the order
            [$payment, $order] = OrderRepository::storeByRequestFromCart($request, $paymentMethod, $carts);
        } catch (\RuntimeException $e) {
            return $this->json($e->getMessage(), [], 422);
        }

        $paymentUrl = null;
        if ($paymentMethod->name != 'CASH') {
            $paymentUrl = route('order.payment', ['payment' => $payment, 'gateway' => $request->payment_method]);
        }

        // Send WhatsApp template message

        $template =[
            '1' => $order->order_code,
            '2' => $order->customer->user->name ?? 'Guest',
            '3' => $order->address->address_line ?? 'address not found',
            '4' => $this->formatProductList($order),
            '5' => "$order->total_amount"
        ];
        $to = $order->customer->user->phone_code . $order->customer->user->phone ?? '';

        // Fetch Twilio config
        $twilioConfig = SMSConfig::where('provider', 'twilio')->first();
        $data = $twilioConfig ? json_decode($twilioConfig->data, true) : null;

        if ( $to &&
            $twilioConfig &&
            $twilioConfig->status == 1 &&
            !empty($data['twilio_sid']) &&
            !empty($data['twilio_token']) &&
            !empty($data['twilio_from'])
        ) {
            try {
                $twilioService = new TwilioService($data);
                $twilioService->sendWhatsAppMessage($to, $template, "HXa3be8bac06c35b2a9f8276dc3ff37d59");
            } catch (\Exception $e) {
            }
        }

        return $this->json('Order created successfully', [
            'order_payment_url' => $paymentUrl,
            'eligible_shop_ids' => $request->shop_ids,
        ]);
    }

    /**
     * Eligible sellers/farmers by county + sub-county.
     */
    public function eligibleSellers(Request $request)
    {
        $location = $this->resolveLocationContext($request);
        if (! $location['subcounty_id']) {
            return $this->json('Sub-county is required to get eligible sellers', [], 422);
        }

        $shops = Shop::query()
            ->where('subcounty_id', $location['subcounty_id'])
            ->when($location['county_id'], function ($query) use ($location) {
                $query->where('county_id', $location['county_id']);
            })
            ->whereHas('user', function ($query) {
                $query->where('is_active', true);
            })
            ->get([
                'id',
                'name',
                'seller_type',
                'processing_supported',
                'approval_status',
                'county_id',
                'subcounty_id',
                'ward_id',
            ]);

        return $this->json('Eligible sellers', [
            'county_id' => $location['county_id'],
            'subcounty_id' => $location['subcounty_id'],
            'sellers' => $shops,
        ]);
    }

    /**
     * Eligible drivers by county + sub-county.
     */
    public function eligibleDrivers(Request $request)
    {
        $location = $this->resolveLocationContext($request);
        if (! $location['subcounty_id']) {
            return $this->json('Sub-county is required to get eligible drivers', [], 422);
        }

        $drivers = Driver::query()
            ->where('status', 'available')
            ->whereHas('user', function ($query) use ($location) {
                $query->where('is_active', true)
                    ->where('subcounty_id', $location['subcounty_id']);
                if ($location['county_id']) {
                    $query->where('county_id', $location['county_id']);
                }
            })
            ->with(['user:id,name,last_name,phone,county_id,subcounty_id,actor_unique_id'])
            ->get();

        return $this->json('Eligible drivers', [
            'county_id' => $location['county_id'],
            'subcounty_id' => $location['subcounty_id'],
            'drivers' => $drivers->map(function ($driver) {
                return [
                    'id' => $driver->id,
                    'status' => $driver->status,
                    'user_id' => $driver->user_id,
                    'name' => $driver->user?->fullName,
                    'phone' => $driver->user?->phone,
                    'actor_unique_id' => $driver->user?->actor_unique_id,
                    'county_id' => $driver->user?->county_id,
                    'subcounty_id' => $driver->user?->subcounty_id,
                ];
            })->values(),
        ]);
    }

    private function formatProductList($order)
    {
        $products = $order->products()->get()->toArray();

        $lines = [];
        foreach ($products as $p) {
            $name = $p['name'] ?? 'Unnamed';
            $quantity = $p['pivot']['quantity'] ?? 0;
            $price = $p['pivot']['price'] ?? 0;

            $line = "- {$name} ({$quantity} X {$price}) = " . ($quantity * $price);
            $lines[] = preg_replace('/[\x{200B}-\x{200D}\x{2060}]/u', '', $line);
        }
        return implode("", $lines);
    }

    /**
     * Again order
     */
    public function reOrder(Request $request)
    {
        // Validate the request
        $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        $user = auth()->user();

        $verifyManage = Cache::rememberForever('verify_manage', function () {
            return VerifyManage::first();
        });

        $accountVerified = false;
        if ($user->email_verified_at || $user->phone_verified_at) {
            $accountVerified = true;
        }

        if ($verifyManage?->order_place_account_verify && ! $accountVerified) {
            return $this->json('Please verify your account first. without verify account you can not place order', [], 422);
        }

        // Find the order
        $order = Order::find($request->order_id);

        if ((int) ($order?->customer?->user_id ?? 0) !== (int) $user->id) {
            return $this->json('You are not allowed to reorder this order', [], 403);
        }

        $orderShop = $order?->shop;
        $orderAddress = $order?->address;

        if (! $orderShop || ! $orderAddress) {
            return $this->json('Order is missing shop or address data', [], 422);
        }

        if (! $orderShop->subcounty_id || ! $orderAddress->subcounty_id) {
            return $this->json('Cross-sub-county orders are not allowed', [], 422);
        }

        if ((int) $orderShop->subcounty_id !== (int) $orderAddress->subcounty_id) {
            return $this->json('Cross-sub-county orders are not allowed', [], 422);
        }

        if ($orderShop->county_id && $orderAddress->county_id && (int) $orderShop->county_id !== (int) $orderAddress->county_id) {
            return $this->json('Selected shop is not in the same county as the delivery address', [], 422);
        }

        $subscription = null;

        if (! $order->shop->user->hasRole('root')) {
            $generalSetting = generaleSetting('setting');

            if ($generalSetting?->business_based_on == 'subscription') {
                $subscription = $order->shop->currentSubscription;

                if (! $subscription) {
                    return $this->json('Sorry, the shop is not available now', [], 422);
                }
            }
        }

        if ($order->order_status->value == OrderStatus::DELIVERED->value) {

            // Check product quantity
            foreach ($order->products as $product) {
                if ($product->quantity < $product->pivot->quantity) {
                    return $this->json('Sorry, your product quantity out of stock', [], 422);
                }
            }

            // create payment
            $toUpper = strtoupper($request->payment_method ?? $order->payment_method);
            $paymentMethods = PaymentMethod::cases();
            $paymentMethod = $paymentMethods[array_search($toUpper, array_column(PaymentMethod::cases(), 'name'))];

            $payment = Payment::create([
                'amount' => $order->payable_amount,
                'payment_method' => $paymentMethod?->value,
            ]);

            // re-order
            $order = OrderRepository::reOrder($order, $payment);

            if ($subscription) {
                $subscription->update([
                    'remaining_sales' => $subscription->remaining_sales - 1,
                ]);
            }

            // attach payment to order
            $payment->orders()->attach($order->id);

            // payment url
            $paymentUrl = null;
            if ($paymentMethod->name != 'CASH') {
                $paymentUrl = route('order.payment', ['payment' => $payment, 'gateway' => $payment->payment_method]);
            }

            // return
            return $this->json('Re-order created successfully', [
                'order_payment_url' => $paymentUrl,
                'order' => OrderResource::make($order),
            ]);
        }

        return $this->json('Sorry, You can not  re-order because order is not delivered', [], 422);
    }

    /**
     * Show the order details.
     *
     * @param  Request  $request  The request object
     */
    public function show(Request $request)
    {
        // Validate the request
        $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        // Find the order
        $order = Order::find($request->order_id);

        return $this->json('order details', [
            'order' => OrderDetailsResource::make($order),
        ]);
    }

    /**
     * Cancel the order.
     */
    public function cancel(Request $request)
    {
        // Validate the request
        $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        // Find the order
        $order = Order::find($request->order_id);

        if ($order->order_status->value == OrderStatus::PENDING->value) {

            // update order status
            $order->update([
                'order_status' => OrderStatus::CANCELLED->value,
            ]);

            foreach ($order->products as $product) {
                $qty = $product->pivot->quantity;

                $product->update(['quantity' => $product->quantity + $qty]);

                $flashSale = $product->flashSales?->first();
                $flashSaleProduct = null;

                if ($flashSale) {
                    $flashSaleProduct = $flashSale?->products()->where('id', $product->id)->first();

                    if ($flashSaleProduct && $product->pivot?->price) {
                        if ($flashSaleProduct->pivot->sale_quantity >= $qty && ($product->pivot?->price == $flashSaleProduct->pivot->price)) {
                            $flashSale->products()->updateExistingPivot($product->id, [
                                'sale_quantity' => $flashSaleProduct->pivot->sale_quantity - $qty,
                            ]);
                        }
                    }
                }
            }

            return $this->json('Order cancelled successfully', [
                'order' => OrderResource::make($order),
            ]);
        }

        return $this->json('Sorry, order cannot be cancelled because it is not pending', [], 422);
    }

    public function confirmDelivery(OrderIdRequest $request)
    {
        $user = auth()->user();

        $order = Order::query()
            ->whereKey($request->order_id)
            ->whereHas('customer', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->first();

        if (! $order) {
            return $this->json('Order not found for this customer', [], 422);
        }

        try {
            OrderRepository::confirmCustomerDelivery($order);
        } catch (\RuntimeException $exception) {
            return $this->json($exception->getMessage(), [], 422);
        }

        $order->refresh();

        return $this->json('Delivery confirmed successfully', [
            'order' => OrderDetailsResource::make($order),
        ]);
    }

    public function payment(Order $order, $paymentMethod = null)
    {
        if ($paymentMethod != 'cash' && $paymentMethod != null) {

            $payment = Payment::create([
                'amount' => $order->payable_amount,
                'payment_method' => $paymentMethod,
            ]);

            $payment->orders()->attach($order->id);

            $paymentUrl = route('order.payment', ['payment' => $payment, 'gateway' => $payment->payment_method]);

            return $this->json('Payment created', [
                'order_payment_url' => $paymentUrl,
            ]);

            // $payment = $order->payments()?->first();

            // if ($payment->payment_method != $paymentMethod) {

            //     $order->update([
            //         'payment_method' => $paymentMethod,
            //     ]);

            //     $orders = $payment->orders()->where('order_status', '!=', OrderStatus::CANCELLED->value)->where('payment_status', PaymentStatus::PENDING->value)->get();

            //     $payment->update([
            //         'payment_method' => $paymentMethod,
            //         'amount' => $orders->sum('payable_amount'),
            //     ]);

            //     $payment->orders()->sync($orders);

            //     $paymentUrl = route('order.payment', ['payment' => $payment, 'gateway' => $payment->payment_method]);

            //     return $this->json('Payment created', [
            //         'order_payment_url' => $paymentUrl,
            //         'order' => OrderResource::make($order),
            //     ]);
            // }

            // $payment = Payment::create([
            //     'amount' => $order->payable_amount,
            //     'payment_method' => $paymentMethod,
            // ]);
        }

        return $this->json('Sorry, You can not  re-payment because payment is CASH', [], 422);
    }

    private function resolveLocationContext(Request $request): array
    {
        $address = null;
        if ($request->filled('address_id')) {
            $address = Address::query()->find($request->integer('address_id'));
        }

        $countyId = $request->integer('county_id') ?: $address?->county_id ?: auth()->user()?->county_id;
        $subcountyId = $request->integer('subcounty_id') ?: $address?->subcounty_id ?: auth()->user()?->subcounty_id;

        return [
            'county_id' => $countyId ? (int) $countyId : null,
            'subcounty_id' => $subcountyId ? (int) $subcountyId : null,
        ];
    }
}
