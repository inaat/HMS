<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Capital ledger of an investor: type 'invest' adds capital, 'withdraw' takes it back.
 */
class InvestorCapital extends Model
{
    protected $guarded = ['id'];

    public function investor()
    {
        return $this->belongsTo(\App\Investor::class);
    }
}
