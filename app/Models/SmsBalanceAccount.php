<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsBalanceAccount extends Model
{
    protected $fillable = [
        'balance',
        'low_balance_threshold',
        'critical_balance_threshold',
        'low_balance_alert_sent_at',
        'critical_balance_alert_sent_at',
        'zero_balance_alert_sent_at',
    ];

    protected $casts = [
        'balance' => 'int',
        'low_balance_threshold' => 'int',
        'critical_balance_threshold' => 'int',
        'low_balance_alert_sent_at' => 'datetime',
        'critical_balance_alert_sent_at' => 'datetime',
        'zero_balance_alert_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function adjustments(): HasMany
    {
        return $this->hasMany(SmsBalanceAdjustment::class);
    }

    public static function current(): self
    {
        /** @var self $account */
        $account = self::query()->lockForUpdate()->findOrFail(1);

        return $account;
    }

    public static function currentReadOnly(): self
    {
        /** @var self $account */
        $account = self::query()->findOrFail(1);

        return $account;
    }
}
