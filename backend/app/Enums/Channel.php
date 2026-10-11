<?php

namespace App\Enums;

enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Telegram = 'telegram';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::Telegram => 'Telegram',
        };
    }

    /** Messenger channels are paced per sender to avoid provider bans. */
    public function isThrottled(): bool
    {
        return (int) config("messaging.throttle_seconds.{$this->value}", 0) > 0;
    }
}
