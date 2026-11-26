<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaseJournalEntry extends Model
{
    protected $table = 'case_journal_entries';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = ['entry_date' => 'date:Y-m-d'];
}
