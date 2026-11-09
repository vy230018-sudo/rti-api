<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'ecom_sub_id', 'type', 'category', 'product_name', 'quantity', 'price', 'image', 'description', 'city', 'conatct', 'start_date', 'end_date', 'status', 'in_stock', 'is_deleted'])]
class EcomDetail extends Model
{
    protected $table = 'ecom_deatils';

    protected $casts = [
        'user_id' => 'integer',
        'ecom_sub_id' => 'integer',
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => 'integer',
        'in_stock' => 'boolean',
        'is_deleted' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(EcomSubscription::class, 'ecom_sub_id', 'id');
    }

    /**
     * Product ki saari images ke full URLs. `image` column JSON array ya comma-separated ho sakta hai.
     *
     * @return array<int, string>
     */
    public function imageUrls(): array
    {
        $raw = $this->image;

        if (! $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        $items = json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : array_map('trim', explode(',', $raw));

        return array_values(array_map(
            fn (string $image) => filter_var($image, FILTER_VALIDATE_URL)
                ? $image
                : asset('storage/'.ltrim($image, '/')),
            array_filter($items, fn ($image) => is_string($image) && $image !== '')
        ));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1)->where('is_deleted', 1);
    }
}
