<?php

namespace App\Repositories;

use Abedin\Maker\Repositories\Repository;
use App\Models\EscrowLedger;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\User;

class EscrowLedgerRepository extends Repository
{
    public static function model()
    {
        return EscrowLedger::class;
    }

    public static function store(Order $order, $actorId, $actorType, $amount, $type, $description, $walletId = null)
    {
        return self::create([
            'order_id' => $order->id,
            'wallet_id' => $walletId,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'amount' => $amount,
            'type' => $type,
            'status' => 'held',
            'description' => $description,
        ]);
    }

    public static function release(EscrowLedger $ledger, $transactionRef = null)
    {
        $ledger->update([
            'status' => 'released',
            'transaction_ref' => $transactionRef,
        ]);

        return $ledger;
    }
}
