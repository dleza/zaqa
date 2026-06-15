<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogService;
use App\Domain\Identity\Events\PhoneOtpIssued;
use App\Models\User;
use App\Models\UserPhoneOtp;
use App\Support\Phone\ZambianPrimaryPhone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    public const OTP_PURPOSE_ACTIVATION = 'activation';

    public const OTP_PURPOSE_PASSWORD_RESET = 'password_reset';

    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function findUserByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return User::query()->where('email', $identifier)->first();
        }

        $normalized = ZambianPrimaryPhone::tryNormalize($identifier);
        if ($normalized !== null) {
            return User::query()
                ->whereIn('phone_primary', ZambianPrimaryPhone::equivalentStorageValues($normalized))
                ->first();
        }

        return User::query()->where('phone_primary', $identifier)->first();
    }

    public function resolveResetChannel(User $user, string $identifier): string
    {
        if ($user->login_identifier_type === 'phone') {
            return 'phone';
        }

        if ($user->login_identifier_type === 'email') {
            return 'email';
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)
            && $user->email
            && strcasecmp(trim((string) $user->email), trim($identifier)) === 0) {
            return 'email';
        }

        return 'phone';
    }

    /**
     * @return array{channel: string, message: string, phone_hint: string|null}
     */
    public function requestReset(User $user, string $identifier): array
    {
        if ($user->disabled_at) {
            throw ValidationException::withMessages([
                'identifier' => 'This account is disabled. Please contact support.',
            ]);
        }

        $channel = $this->resolveResetChannel($user, $identifier);

        if ($channel === 'email') {
            if (! $user->email) {
                throw ValidationException::withMessages([
                    'identifier' => 'This account does not have an email address configured for password reset.',
                ]);
            }

            $status = Password::sendResetLink(['email' => $user->email]);

            if ($status !== Password::RESET_LINK_SENT) {
                throw ValidationException::withMessages([
                    'identifier' => 'Unable to send a password reset email right now. Please try again later.',
                ]);
            }

            $this->audit->record(
                eventType: 'identity.password_reset_requested',
                module: 'Identity',
                actionName: 'password_reset_requested',
                message: 'Password reset email sent.',
                entityType: $user::class,
                entityId: $user->id,
                metadata: [
                    'channel' => 'email',
                    'email' => $user->email,
                ],
                actor: $user,
            );

            return [
                'channel' => 'email',
                'message' => 'Password reset link sent to your email address.',
                'phone_hint' => null,
            ];
        }

        if (! $user->phone_primary) {
            throw ValidationException::withMessages([
                'identifier' => 'This account does not have a phone number configured for password reset.',
            ]);
        }

        $this->issuePasswordResetOtp($user);

        $this->audit->record(
            eventType: 'identity.password_reset_requested',
            module: 'Identity',
            actionName: 'password_reset_requested',
            message: 'Password reset OTP sent.',
            entityType: $user::class,
            entityId: $user->id,
            metadata: [
                'channel' => 'phone',
                'phone_number' => $user->phone_primary,
            ],
            actor: $user,
        );

        return [
            'channel' => 'phone',
            'message' => 'A verification code has been sent to your phone number.',
            'phone_hint' => $this->maskPhone((string) $user->phone_primary),
        ];
    }

    public function issuePasswordResetOtp(User $user): string
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = CarbonImmutable::now()->addMinutes(10);

        DB::transaction(function () use ($user, $code, $expiresAt) {
            UserPhoneOtp::query()
                ->where('user_id', $user->id)
                ->where('purpose', self::OTP_PURPOSE_PASSWORD_RESET)
                ->whereNull('verified_at')
                ->update(['verified_at' => now()]);

            UserPhoneOtp::create([
                'user_id' => $user->id,
                'phone_number' => $user->phone_primary,
                'purpose' => self::OTP_PURPOSE_PASSWORD_RESET,
                'code_hash' => Hash::make($code),
                'expires_at' => $expiresAt,
                'verified_at' => null,
                'attempt_count' => 0,
                'resent_count' => 0,
                'last_sent_at' => now(),
            ]);
        });

        $this->audit->record(
            eventType: 'identity.password_reset_otp_issued',
            module: 'Identity',
            actionName: 'password_reset_otp_issued',
            message: 'Password reset phone OTP issued.',
            entityType: $user::class,
            entityId: $user->id,
            metadata: [
                'phone_number' => $user->phone_primary,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            actor: $user,
        );

        event(new PhoneOtpIssued($user, $code, $expiresAt, self::OTP_PURPOSE_PASSWORD_RESET));

        return $code;
    }

    public function resetPasswordWithOtp(User $user, string $code, string $password): void
    {
        $otp = UserPhoneOtp::query()
            ->where('user_id', $user->id)
            ->where('phone_number', $user->phone_primary)
            ->where('purpose', self::OTP_PURPOSE_PASSWORD_RESET)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp || ! Hash::check($code, $otp->code_hash)) {
            if ($otp) {
                $otp->increment('attempt_count');
            }

            throw ValidationException::withMessages([
                'code' => 'The verification code is invalid or has expired.',
            ]);
        }

        DB::transaction(function () use ($user, $otp, $password) {
            $otp->forceFill(['verified_at' => now()])->save();
            $user->forceFill(['password' => $password])->save();
        });

        $this->audit->record(
            eventType: 'identity.password_reset',
            module: 'Identity',
            actionName: 'password_reset',
            message: 'User password reset via phone OTP.',
            entityType: $user::class,
            entityId: $user->id,
            metadata: [
                'channel' => 'phone',
            ],
            actor: $user,
        );
    }

    public function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 4) {
            return '****';
        }

        return '***'.substr($digits, -4);
    }

    public function userFromPasswordResetSession(?int $userId): ?User
    {
        if (! $userId || $userId <= 0) {
            return null;
        }

        return User::query()->find($userId);
    }
}
