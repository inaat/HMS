<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A closed period. Once locked its lines are frozen and used for loss carry forward of the next period.
 */
class InvestorSettlement extends Model
{
    protected $guarded = ['id'];

    public function lines()
    {
        return $this->hasMany(\App\InvestorSettlementLine::class, 'settlement_id');
    }

    public function payouts()
    {
        return $this->hasMany(\App\InvestorPayout::class, 'settlement_id');
    }
}
