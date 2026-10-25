<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasUuids;

    protected $guarded = [];

    public const SYSTEM = 'system';

    public const USER = 'user';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** Balance is always derived from entries, never stored, so it cannot drift. */
    public function balance(): int
    {
        return (int) $this->entries()->sum('amount');
    }

    public static function system(): self
    {
        return static::firstOrCreate(['kind' => self::SYSTEM, 'user_id' => null], ['currency' => 'NGN']);
    }
}
