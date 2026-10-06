<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Money paid to an investor from their share (not an expense, so it does not change profit / loss).
 */
class InvestorPayout extends Model
{
    protected $guarded = ['id'];

    public function investor()
    {
        return $this->belongsTo(\App\Investor::class);
    }

    /**
     * Period this payout was made for (null = paid against the balance in general)
     */
    public function settlement()
    {
        return $this->belongsTo(\App\InvestorSettlement::class);
    }
}
