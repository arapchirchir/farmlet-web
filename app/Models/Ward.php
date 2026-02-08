<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ward extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function subcounty(): BelongsTo
    {
        return $this->belongsTo(Subcounty::class);
    }
}
