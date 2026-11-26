<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MinuteStat extends Model
{
    protected $table = 'minute_stats';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['minute_at' => 'datetime'];
}
