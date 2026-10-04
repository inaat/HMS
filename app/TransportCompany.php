<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransportCompany extends Model
{
    use SoftDeletes;

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['deleted_at'];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $table = 'goodstransportcompany';
    protected $guarded = ['id'];

    /**
     * Return list of brands for a business
     *
     * @param int $business_id
     * @param boolean $show_none = false
     *
     * @return array
     */
    public static function forDropdown($business_id, $show_none = false)
    {
        $TransportCompany = TransportCompany::where('business_id', $business_id)
                    ->pluck('name', 'id');

        if ($show_none) {
            $TransportCompany->prepend(__('lang_v1.none'), '');
        }

        return $TransportCompany;
    }

}
