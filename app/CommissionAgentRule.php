<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Brand or product wise commission of a sales commission agent.
 * type 'percentage': value % of the net sale; type 'fixed': value per unit sold.
 * Product rule beats brand rule, brand rule beats the agent's cmmsn_percent.
 */
class CommissionAgentRule extends Model
{
    protected $guarded = ['id'];

    public function brand()
    {
        return $this->belongsTo(\App\Brands::class, 'brand_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Product::class, 'product_id');
    }

    /**
     * Rules of the agents keyed by agent id, then 'p{product_id}' / 'b{brand_id}'
     *
     * @return array
     */
    public static function forAgents($business_id, $agent_ids)
    {
        $rules = [];
        foreach (static::where('business_id', $business_id)->whereIn('user_id', $agent_ids)->get() as $rule) {
            $key = ! empty($rule->product_id) ? 'p'.$rule->product_id : 'b'.$rule->brand_id;
            $rules[$rule->user_id][$key] = $rule;
        }

        return $rules;
    }
}
