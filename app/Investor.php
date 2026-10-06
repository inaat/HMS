<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * A person who put money into the business and gets a share of profit through deals.
 * Kept separate from the Accounts module.
 */
class Investor extends Model
{
    protected $guarded = ['id'];

    public function capitals()
    {
        return $this->hasMany(\App\InvestorCapital::class);
    }

    public function deals()
    {
        return $this->hasMany(\App\InvestorDeal::class);
    }

    public function payouts()
    {
        return $this->hasMany(\App\InvestorPayout::class);
    }

    public function settlementLines()
    {
        return $this->hasMany(\App\InvestorSettlementLine::class);
    }
}
