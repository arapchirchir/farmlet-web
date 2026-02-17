<?php

namespace App\Listeners;

use App\Events\OrderNotificationEvent;
use App\Models\NotificationLog;
use App\Models\SMSConfig;
use App\Models\User;
use App\Repositories\NotificationRepository;
use App\Services\NotificationServices;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Mail;

class OrderNotificationListener
{
    public function handle(OrderNotificationEvent $event): void
    {
        $order = $event->order->fresh(['customer.user.devices', 'shop.user.devices', 'vendor.devices', 'driver.user.devices']);
        if (! $order) {
            return;
        }

        $eventKey = $this->resolveEventKey($event);
        [$title, $message] = $this->buildMessage($order, $event);

        $recipients = $this->resolveRecipients($order, $event);
        if (empty($recipients)) {
            $this->storeLog(
                orderId: $order->id,
                eventKey: $eventKey,
                channel: 'system',
                recipientId: null,
                recipientRole: null,
                destination: null,
                status: 'skipped',
                title: $title,
                message: $message,
                payload: ['reason' => 'no_recipients'],
            );

            return;
        }

        $twilio = $this->resolveTwilioService();

        foreach ($recipients as $recipient) {
            $this->dispatchAppChannel($order->id, $eventKey, $recipient, $title, $message);
            $this->dispatchEmailChannel($order->id, $eventKey, $recipient, $title, $message);
            $this->dispatchSmsChannel($order->id, $eventKey, $recipient, $message, $twilio);
            $this->dispatchWhatsAppChannel($order->id, $eventKey, $recipient, $message, $twilio);
        }
    }

    private function resolveEventKey(OrderNotificationEvent $event): string
    {
        if ($event->eventType === 'order_created') {
            return 'order.created';
        }

        if ($event->eventType === 'order_status_updated') {
            $statusKey = str($event->toStatus ?? 'unknown')->lower()->replace(' ', '_')->value();

            return "order.status.{$statusKey}";
        }

        return "order.{$event->eventType}";
    }

    private function buildMessage($order, OrderNotificationEvent $event): array
    {
        $orderRef = "{$order->prefix}{$order->order_code}";

        if ($event->eventType === 'order_created') {
            return [
                'Order Created',
                "Order {$orderRef} has been placed successfully.",
            ];
        }

        if ($event->eventType === 'order_status_updated') {
            return [
                'Order Status Updated',
                "Order {$orderRef} status changed to {$event->toStatus}.",
            ];
        }

        return [
            'Order Notification',
            "Order {$orderRef} has a new update.",
        ];
    }

    private function resolveRecipients($order, OrderNotificationEvent $event): array
    {
        $recipients = [];

        $customer = $order->customer?->user;
        if ($customer) {
            $recipients[] = ['user' => $customer, 'role' => 'customer'];
        }

        $vendor = $order->vendor ?: $order->shop?->user;
        if ($vendor) {
            $recipients[] = ['user' => $vendor, 'role' => 'vendor'];
        }

        $driverUser = $order->driver?->user;
        if ($driverUser) {
            $recipients[] = ['user' => $driverUser, 'role' => 'driver'];
        }

        $shouldNotifyProcessingManagers = ($order->order_type ?? 'raw') === 'processed';
        if ($shouldNotifyProcessingManagers && $event->eventType === 'order_status_updated') {
            $managerStatuses = [
                'Pickup For Processing',
                'At Processing Room',
                'Processing',
                'Ready For Delivery',
            ];
            $shouldNotifyProcessingManagers = in_array($event->toStatus, $managerStatuses, true);
        }

        if ($shouldNotifyProcessingManagers) {
            $processingManagers = User::query()
                ->role('processing_manager')
                ->where('is_active', true)
                ->where('county_id', $order->county_id)
                ->where('subcounty_id', $order->subcounty_id)
                ->get();

            foreach ($processingManagers as $manager) {
                $recipients[] = ['user' => $manager, 'role' => 'processing_manager'];
            }
        }

        $unique = collect($recipients)
            ->filter(fn($recipient) => $recipient['user'] instanceof User)
            ->unique(fn($recipient) => $recipient['user']->id.'-'.$recipient['role'])
            ->values()
            ->all();

        return $unique;
    }

    private function dispatchAppChannel(int $orderId, string $eventKey, array $recipient, string $title, string $message): void
    {
        $user = $recipient['user'];
        $role = $recipient['role'];

        try {
            NotificationRepository::storeByRequest((object) [
                'title' => $title,
                'content' => $message,
                'user_id' => $user->id,
                'type' => 'order',
                'url' => $orderId,
            ]);

            $tokens = $user->devices->pluck('key')->filter()->values()->all();
            if (! empty($tokens)) {
                NotificationServices::sendNotification($message, $tokens, $title);
            }

            $this->storeLog(
                orderId: $orderId,
                eventKey: $eventKey,
                channel: 'app',
                recipientId: $user->id,
                recipientRole: $role,
                destination: 'user:'.$user->id,
                status: 'success',
                title: $title,
                message: $message,
                payload: ['device_tokens' => count($tokens)],
            );
        } catch (\Throwable $exception) {
            $this->storeLog(
                orderId: $orderId,
                eventKey: $eventKey,
                channel: 'app',
                recipientId: $user->id,
                recipientRole: $role,
                destination: 'user:'.$user->id,
                status: 'failed',
                title: $title,
                message: $message,
                payload: null,
                errorMessage: $exception->getMessage(),
            );
        }
    }

    private function dispatchEmailChannel(int $orderId, string $eventKey, array $recipient, string $title, string $message): void
    {
        $user = $recipient['user'];
        $role = $recipient['role'];
        $email = $user->email;

        if (! $email) {
            $this->storeLog(
                orderId: $orderId,
                eventKey: $eventKey,
                channel: 'email',
                recipientId: $user->id,
                recipientRole: $role,
                destination: null,
                status: 'skipped',
                title: $title,
                message: $message,
                payload: ['reason' => 'missing_email'],
            );

            return;
        }

        try {
            Mail::raw($message, function ($mail) use ($email, $title) {
                $mail->to($email)->subject($title);
            });

            $this->storeLog(
                orderId: $orderId,
                eventKey: $eventKey,
                channel: 'email',
                recipientId: $user->id,
                recipientRole: $role,
                destination: $email,
                status: 'success',
                title: $title,
                message: $message,
                payload: null,
            );
        } catch (\Throwable $exception) {
            $this->storeLog(
                orderId: $orderId,
                eventKey: $eventKey,
                channel: 'email',
                recipientId: $user->id,
                recipientRole: $role,
                destination: $email,
                status: 'failed',
                title: $title,
                message: $message,
                payload: null,
                errorMessage: $exception->getMessage(),
            );
        }
    }

    private function dispatchSmsChannel(int $orderId, string $eventKey, array $recipient, string $message, ?TwilioService $twilio): void
    {
        $user = $recipient['user'];
        $role = $recipient['role'];
        $destination = $this->resolvePhoneDestination($user);

        if (! $twilio || ! $destination) {
            $this->storeLog(
                orderId: $orderId,
                eventKey: $eventKey,
                channel: 'sms',
                recipientId: $user->id,
                recipientRole: $role,
                destination: $destination,
                status: 'skipped',
                title: 'Order SMS',
                message: $message,
                payload: ['reason' => $twilio ? 'missing_phone' : 'sms_gateway_not_configured'],
            );

            return;
        }

        try {
            $twilio->sendSms($destination, $message);

            $this->storeLog(
                orderId: $orderId,
                eventKey: $eventKey,
                channel: 'sms',
                recipientId: $user->id,
                recipientRole: $role,
                destination: $destination,
                status: 'success',
                title: 'Order SMS',
                message: $message,
                payload: null,
            );
        } catch (\Throwable $exception) {
            $this->storeLog(
                orderId: $orderId,
                eventKey: $eventKey,
                channel: 'sms',
                recipientId: $user->id,
                recipientRole: $role,
                destination: $destination,
                status: 'failed',
                title: 'Order SMS',
                message: $message,
                payload: null,
                errorMessage: $exception->getMessage(),
            );
        }
    }

    private function dispatchWhatsAppChannel(int $orderId, string $eventKey, array $recipient, string $message, ?TwilioService $twilio): void
    {
        $user = $recipient['user'];
        $role = $recipient['role'];
        $destination = $this->resolvePhoneDestination($user);

        if (! $twilio || ! $destination) {
            $this->storeLog(
                orderId: $orderId,
                eventKey: $eventKey,
                channel: 'whatsapp',
                recipientId: $user->id,
                recipientRole: $role,
                destination: $destination,
                status: 'skipped',
                title: 'Order WhatsApp',
                message: $message,
                payload: ['reason' => $twilio ? 'missing_phone' : 'whatsapp_gateway_not_configured'],
            );

            return;
        }

        try {
            $twilio->sendWhatsAppText($destination, $message);

            $this->storeLog(
                orderId: $orderId,
                eventKey: $eventKey,
                channel: 'whatsapp',
                recipientId: $user->id,
                recipientRole: $role,
                destination: $destination,
                status: 'success',
                title: 'Order WhatsApp',
                message: $message,
                payload: null,
            );
        } catch (\Throwable $exception) {
            $this->storeLog(
                orderId: $orderId,
                eventKey: $eventKey,
                channel: 'whatsapp',
                recipientId: $user->id,
                recipientRole: $role,
                destination: $destination,
                status: 'failed',
                title: 'Order WhatsApp',
                message: $message,
                payload: null,
                errorMessage: $exception->getMessage(),
            );
        }
    }

    private function resolveTwilioService(): ?TwilioService
    {
        $twilioConfig = SMSConfig::query()->where('provider', 'twilio')->where('status', 1)->first();
        $data = $twilioConfig ? json_decode($twilioConfig->data, true) : null;

        if (
            ! $data
            || empty($data['twilio_sid'])
            || empty($data['twilio_token'])
            || empty($data['twilio_from'])
        ) {
            return null;
        }

        return new TwilioService($data);
    }

    private function resolvePhoneDestination(User $user): ?string
    {
        $phone = $user->phone;

        if (! $phone) {
            return null;
        }

        $phoneCode = $user->phone_code ?? '+';
        $fullPhone = $phoneCode.$phone;

        return str_starts_with($fullPhone, '+') ? $fullPhone : '+'.$fullPhone;
    }

    private function storeLog(
        int $orderId,
        string $eventKey,
        string $channel,
        ?int $recipientId,
        ?string $recipientRole,
        ?string $destination,
        string $status,
        ?string $title,
        ?string $message,
        ?array $payload,
        ?string $errorMessage = null,
    ): void {
        NotificationLog::query()->create([
            'order_id' => $orderId,
            'recipient_user_id' => $recipientId,
            'recipient_role' => $recipientRole,
            'event_key' => $eventKey,
            'channel' => $channel,
            'destination' => $destination,
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'error_message' => $errorMessage,
            'payload' => $payload,
            'sent_at' => now(),
        ]);
    }
}
