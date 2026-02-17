<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'Pending';
    case CONFIRM = 'Confirm';
    case VENDOR_PREPARING = 'Vendor Preparing';
    case PICKUP_FOR_PROCESSING = 'Pickup For Processing';
    case AT_PROCESSING_ROOM = 'At Processing Room';
    case PROCESSING = 'Processing';
    case READY_FOR_DELIVERY = 'Ready For Delivery';
    case PICKUP = 'Pickup';
    case ON_THE_WAY = 'On The Way';
    case DELIVERED = 'Delivered';
    case CANCELLED = 'Cancelled';
}
