<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A WhatsApp gateway session owned by a business. `instance` is the key the
 * gateway knows it by, so it must be unique across every app on that gateway.
 */
class WhatsappDevice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'access_token' => 'encrypted',
        'is_default' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    // microsecond precision so last_used_at can break ties for round-robin device
    // rotation; Eloquent's default 'Y-m-d H:i:s' format silently truncates it away
    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function business()
    {
        return $this->belongsTo(\App\Business::class);
    }

    /**
     * The business's first device; one is created if it has none yet.
     */
    public static function forBusiness($business_id): self
    {
        $device = static::where('business_id', $business_id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if (empty($device)) {
            $device = static::createFor($business_id, Business::where('id', $business_id)->value('name') ?: 'WhatsApp');
        }

        return $device;
    }

    public static function createFor($business_id, $name, $instance = null): self
    {
        return static::create([
            'business_id' => $business_id,
            // random by default so two devices, or two apps on the same gateway,
            // never share one WhatsApp session by accident
            'instance' => $instance ?: 'pos_'.$business_id.'_'.Str::lower(Str::random(8)),
            'name' => $name,
            'is_default' => ! static::where('business_id', $business_id)->exists(),
        ]);
    }

    /**
     * Picks the device to send through: the connected one used least recently,
     * so messages spread evenly over every linked number and no single number
     * sends enough to get flagged. Falls back to the first device when none is
     * connected, so the gateway still reports the error.
     */
    public static function instanceFor($business_id): string
    {
        $device = static::where('business_id', $business_id)
            ->where('status', 'connected')
            ->orderByRaw('last_used_at IS NOT NULL')
            ->orderBy('last_used_at')
            ->orderBy('id')
            ->first();

        if (empty($device)) {
            return static::forBusiness($business_id)->instance;
        }

        $device->update(['last_used_at' => now()]);

        return $device->instance;
    }
}
