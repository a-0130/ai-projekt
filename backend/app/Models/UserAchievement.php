<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UserAchievement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'achievement_key',
        'variant_key',
        'name',
        'description',
        'threshold',
        'earned_at',
    ];

    protected function casts(): array
    {
        return [
            'earned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discountCode(): HasOne
    {
        return $this->hasOne(DiscountCode::class);
    }
}
