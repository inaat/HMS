<?php


namespace App;

use Illuminate\Database\Eloquent\Model;

class Builty extends Model
{
     /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $table = 'builty';
    protected $guarded = ['id'];
    protected $dates = ['date_begin', 'date_end'];

    public function builtyitems()
    {
        return $this->hasMany(\App\BuiltyItem::class);
    }
    public function transport()
    {

            return $this->belongsTo(\App\TransportCompany::class, 'goodstransportcompany_id');

    }
}
