<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $table = 'incidents';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime', 'states_seen' => 'array', 'evidence' => 'array', 'ended_by_gap' => 'boolean', 'started_after_gap' => 'boolean', 'is_open' => 'boolean'];
}
