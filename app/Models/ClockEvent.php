<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClockEvent extends Model
{
    protected $table = 'clock_events';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['observed_at' => 'datetime'];
}
