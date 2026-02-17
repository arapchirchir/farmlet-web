<?php

namespace App\Http\Controllers\API\ProcessingManager;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderIdRequest;
use App\Repositories\OrderRepository;

class OrderController extends Controller
{
    public function confirmReceipt(OrderIdRequest $request)
    {
        $order = OrderRepository::find($request->order_id);

        if (! $order) {
            return $this->json('Sorry, this order is not found', [], 422);
        }

        if ($message = $this->validateProcessingManagerAccess($order)) {
            return $this->json($message, [], 422);
        }

        try {
            OrderRepository::OrderStatusUpdateFromProcessingManager($order, OrderStatus::PROCESSING->value);
        } catch (\RuntimeException $exception) {
            return $this->json($exception->getMessage(), [], 422);
        }

        $order->refresh();

        return $this->json('Processing room receipt confirmed successfully', [
            'order' => [
                'id' => $order->id,
                'order_type' => $order->order_type ?? 'raw',
                'processing_room_id' => $order->processing_room_id,
                'order_status' => $order->order_status->value,
            ],
        ]);
    }

    public function readyForDelivery(OrderIdRequest $request)
    {
        $order = OrderRepository::find($request->order_id);

        if (! $order) {
            return $this->json('Sorry, this order is not found', [], 422);
        }

        if ($message = $this->validateProcessingManagerAccess($order)) {
            return $this->json($message, [], 422);
        }

        try {
            OrderRepository::OrderStatusUpdateFromProcessingManager($order, OrderStatus::READY_FOR_DELIVERY->value);
        } catch (\RuntimeException $exception) {
            return $this->json($exception->getMessage(), [], 422);
        }

        $order->refresh();

        return $this->json('Order marked as ready for delivery', [
            'order' => [
                'id' => $order->id,
                'order_type' => $order->order_type ?? 'raw',
                'processing_room_id' => $order->processing_room_id,
                'order_status' => $order->order_status->value,
            ],
        ]);
    }

    private function validateProcessingManagerAccess($order): ?string
    {
        if (($order->order_type ?? 'raw') !== 'processed') {
            return 'Only processed orders can be managed in this workflow';
        }

        if (! $order->processing_room_id) {
            return 'This processed order is missing a processing room assignment';
        }

        $manager = auth()->user();

        if (! $manager?->county_id || ! $manager?->subcounty_id) {
            return 'Processing manager account must be bound to county and sub-county';
        }

        if (! $order->county_id || ! $order->subcounty_id) {
            return 'Order location is incomplete for processing workflow';
        }

        if ((int) $manager->county_id !== (int) $order->county_id) {
            return 'This order is outside your county';
        }

        if ((int) $manager->subcounty_id !== (int) $order->subcounty_id) {
            return 'This order is outside your sub-county';
        }

        return null;
    }
}
