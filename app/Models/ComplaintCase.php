<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplaintCase extends Model
{
    protected $table = 'complaint_cases';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = ['event_date' => 'date:Y-m-d', 'submitted_at' => 'date:Y-m-d', 'responded_at' => 'date:Y-m-d', 'regulator_filed_at' => 'date:Y-m-d'];
}
