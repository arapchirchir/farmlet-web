<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\Roles;
use App\Http\Controllers\Controller;
use App\Http\Requests\RiderRequest;
use App\Models\Driver;
use App\Models\Order;
use App\Models\User;
use App\Models\County;
use App\Repositories\DriverRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;

class RiderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $status = request('status');

        $riders = User::role(Roles::DRIVER->value)->when($status, function ($query) use ($status) {
            $status = $status == 'approved' ? true : false;

            return $query->where('is_active', $status);
        })->paginate(20);

        return view('admin.rider.index', compact('riders'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $counties = County::query()->orderBy('name')->get();

        return view('admin.rider.create', compact('counties'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(RiderRequest $request)
    {
        $user = UserRepository::storeByRequest($request);
        DriverRepository::ensureDriverAccess($user);

        $user->update(['is_active' => true]);

        return to_route('admin.rider.index')->withSuccess(__('Rider created successfully'));
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        $driver = $user->driver;

        $totalDelivery = $driver->driverOrders()->where('is_completed', true)->count();
        $totalPending = $driver->driverOrders()->where('is_completed', false)->count();

        $allCashCollected = $driver->orders()->where('order_status', OrderStatus::DELIVERED->value)->where('payment_method', PaymentMethod::CASH->value)->sum('payable_amount');

        $wallet = DriverRepository::getWallet($driver);

        $alreadyWithdraw = $driver->user->withdraws()->where('status', 'approved')->sum('amount');

        $pendingWithdraw = $driver->user->withdraws()->where('status', 'pending')->sum('amount');

        $deniedWithdraw = $driver->user->withdraws()->where('status', 'denied')->sum('amount');

        return view('admin.rider.show', compact('user', 'driver', 'totalDelivery', 'totalPending', 'allCashCollected', 'alreadyWithdraw', 'pendingWithdraw', 'deniedWithdraw', 'wallet'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user)
    {
        $counties = County::query()->orderBy('name')->get();

        return view('admin.rider.edit', compact('user', 'counties'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(RiderRequest $request, User $user)
    {
        UserRepository::updateByRequest($request, $user);
        DriverRepository::ensureDriverAccess($user);

        return to_route('admin.rider.index')->withSuccess(__('Rider updated successfully'));
    }

    /**
     * toggle the status of the specified resource.
     */
    public function statusToggle(User $user)
    {
        $user->update([
            'is_active' => ! $user->is_active,
        ]);

        return back()->withSuccess(__('Rider status updated successfully'));
    }

    /**
     * assign order to rider
     */
    public function assignOrder(Order $order, Request $request)
    {
        $request->validate([
            'rider' => ['required', 'exists:drivers,id'],
        ]);

        $driver = Driver::find($request->rider);

        if (! $driver) {
            return back()->withError(__('Rider not found, please try again'));
        }

        $driverUser = $driver->user;
        if (! $driverUser?->county_id || ! $driverUser?->subcounty_id) {
            return back()->withError(__('Selected rider is missing county or sub-county assignment'));
        }

        if (! $order->county_id || ! $order->subcounty_id) {
            return back()->withError(__('Order is missing county or sub-county assignment'));
        }

        if ((int) $driverUser->county_id !== (int) $order->county_id || (int) $driverUser->subcounty_id !== (int) $order->subcounty_id) {
            return back()->withError(__('Rider must be in the same county and sub-county as the order'));
        }

        DriverRepository::assignOrder($order, $driver);

        return back()->withSuccess(__('Rider assigned successfully'));
    }
}
