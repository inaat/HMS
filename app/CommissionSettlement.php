<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A locked period of one commission agent. Paid by expenses (category "Sales commission"), linked in
 * commission_settlement_payments; an expense deleted from the Expenses list no longer counts as paid.
 */
class CommissionSettlement extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function agent()
    {
        return $this->belongsTo(\App\User::class, 'agent_id');
    }

    public function lockedBy()
    {
        return $this->belongsTo(\App\User::class, 'locked_by');
    }

    /**
     * Expenses paying this settlement (only ones that still exist)
     */
    public function expenses()
    {
        return $this->belongsToMany(\App\Transaction::class, 'commission_settlement_payments', 'settlement_id', 'transaction_id')
            ->where('transactions.type', 'expense');
    }

    public function paidAmount()
    {
        return (float) $this->expenses()->sum('transactions.final_total');
    }
}
