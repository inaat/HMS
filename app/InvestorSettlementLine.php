<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InvestorSettlementLine extends Model
{
    protected $guarded = ['id'];

    public function settlement()
    {
        return $this->belongsTo(\App\InvestorSettlement::class, 'settlement_id');
    }

    public function investor()
    {
        return $this->belongsTo(\App\Investor::class);
    }

    public function deal()
    {
        return $this->belongsTo(\App\InvestorDeal::class, 'deal_id');
    }
}
