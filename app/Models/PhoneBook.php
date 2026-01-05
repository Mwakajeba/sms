<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneBook extends Model
{
    protected $fillable = [
        'name',
        'phone_number',
        'user_id',
    ];

    /**
     * Get the user that owns the phone book entry
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
