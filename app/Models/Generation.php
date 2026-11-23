<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model {
    use HasUlids;

    public const SUBMITTING = 'submitting';
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';

    public const RANK = [
        self::SUBMITTING => 0,
        self::QUEUED => 1,
        self::PROCESSING => 2,
        self::SUCCEEDED => 3,
        self::FAILED => 3,
    ];

    protected $guarded = [];

    protected $casts = [
        'reference_images' => 'array',
        'rates' => 'array',
        'usage' => 'array',
        'settled_at' => 'datetime',
        'image_url_expires_at' => 'datetime',
        'provider_created_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function uniqueIds(): array {
        return ['uuid'];
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool {
        return in_array($this->status, [self::SUCCEEDED, self::FAILED], true);
    }

    public function toFrontend(): array {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images ?? [],
            'estimate' => [
                'min' => Money::display($this->estimate_min_nanos),
                'max' => Money::display($this->estimate_max_nanos),
                'expected' => Money::display($this->estimate_expected_nanos),
            ],
            'charge' => $this->charge_nanos !== null ? Money::display($this->charge_nanos) : null,
            'charge_exact' => $this->charge_nanos !== null ? Money::format($this->charge_nanos) : null,
            'usage' => $this->usage,
            'image_url' => $this->image_url && $this->image_url_expires_at?->isFuture() ? $this->image_url : null,
            'image_expired' => $this->image_url && ! $this->image_url_expires_at?->isFuture(),
            'error' => $this->error_code ? ['code' => $this->error_code, 'message' => $this->error_message] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
