<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class BuiltyItem extends Model
{
     /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $table = 'builtyitems';
    protected $guarded = ['id'];

}
