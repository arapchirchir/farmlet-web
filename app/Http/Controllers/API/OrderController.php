<?php

namespace App\Http\Controllers\API;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Customer;
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
                'processing' => $statusWiseOrders[OrderStatus::PROCESSING->value],
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

        $shops = Shop::query()
            ->whereIn('id', $request->shop_ids)
            ->get(['id', 'county_id', 'subcounty_id']);

        if ($shops->whereNull('subcounty_id')->count() > 0) {
            return $this->json('Selected shop(s) are missing sub-county assignment', [], 422);
        }

        $shopSubcounties = $shops->pluck('subcounty_id')->filter()->unique();
        if ($shopSubcounties->count() !== 1) {
            return $this->json('Cross-sub-county orders are not allowed', [], 422);
        }

        if ($shopSubcounties->first() !== $address->subcounty_id) {
            return $this->json('Selected shop(s) are not in the same sub-county as the delivery address', [], 422);
        }

        if ($address->county_id && $shops->whereNull('county_id')->count() > 0) {
            return $this->json('Selected shop(s) are missing county assignment', [], 422);
        }

        $shopCounties = $shops->pluck('county_id')->filter()->unique();
        if ($address->county_id && $shopCounties->count() === 1 && $shopCounties->first() !== $address->county_id) {
            return $this->json('Selected shop(s) are not in the same county as the delivery address', [], 422);
        }
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
        $carts = userCart(request())->whereIn('shop_id', $request->shop_ids)->where('is_buy_now', $isBuyNow)->get();
        // dd($carts);
        if ($carts->isEmpty()) {
            return $this->json('Sorry shop cart is empty', [], 422);
        }

        $toUpper = strtoupper($request->payment_method);
        $paymentMethods = PaymentMethod::cases();

        $paymentMethod = $paymentMethods[array_search($toUpper, array_column(PaymentMethod::cases(), 'name'))];

        // Store the order
        [$payment, $order] = OrderRepository::storeByRequestFromCart($request, $paymentMethod, $carts);

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
}
