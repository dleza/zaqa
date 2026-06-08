<?php

namespace App\Support;

class RegistrationOptions
{
    public static function allowsEmail(): bool
    {
        return (bool) config('registration.with_email', true);
    }

    public static function allowsSms(): bool
    {
        return (bool) config('registration.with_sms', true);
    }

    /**
     * @return list<string>
     */
    public static function allowedLoginIdentifierTypes(): array
    {
        $types = [];

        if (self::allowsEmail()) {
            $types[] = 'email';
        }

        if (self::allowsSms()) {
            $types[] = 'phone';
        }

        return $types;
    }

    public static function defaultLoginIdentifierType(): string
    {
        return self::allowsEmail() ? 'email' : 'phone';
    }

    /**
     * @return array{registerWithEmail: bool, registerWithSms: bool, defaultContactMethod: string}
     */
    public static function inertiaProps(): array
    {
        return [
            'registerWithEmail' => self::allowsEmail(),
            'registerWithSms' => self::allowsSms(),
            'defaultContactMethod' => self::defaultLoginIdentifierType(),
        ];
    }
}
