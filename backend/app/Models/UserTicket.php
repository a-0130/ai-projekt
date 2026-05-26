<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTicket extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'ticket_type_id',
        'discount_code_id',
        'purchase_date',
        'discount_amount',
        'final_price',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'datetime',
            'discount_amount' => 'decimal:2',
            'final_price' => 'decimal:2',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    public function status(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->valid_from !== null && $this->valid_from->isFuture()) {
            return 'inactive';
        }

        if ($this->valid_until !== null && $this->valid_until->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    public function canActivate(): bool
    {
        return ! $this->ticketType->is_long_term
            && ! $this->is_active
            && $this->status() !== 'expired';
    }
}
