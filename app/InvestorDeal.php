<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * What an investor gets a share of.
 * scope 'overall': business net profit; 'brand' / 'product': gross profit of that brand / product.
 * share_type 'percentage': share_percent of the profit; 'capital': investor's average capital / pool_capital.
 */
class InvestorDeal extends Model
{
    protected $guarded = ['id'];

    public function investor()
    {
        return $this->belongsTo(\App\Investor::class);
    }

    public function brand()
    {
        return $this->belongsTo(\App\Brands::class, 'brand_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Product::class, 'product_id');
    }

    public function location()
    {
        return $this->belongsTo(\App\BusinessLocation::class, 'location_id');
    }

    /**
     * e.g. "Brand: GCP (Fine Corporation)", "Overall business"
     */
    public function scopeLabel()
    {
        if ($this->scope == 'brand') {
            $label = 'Brand: '.optional($this->brand)->name;
        } elseif ($this->scope == 'product') {
            $label = 'Product: '.optional($this->product)->name;
        } else {
            $label = 'Overall business';
        }

        return $label.(! empty($this->location_id) ? ' ('.optional($this->location)->name.')' : '');
    }
}
