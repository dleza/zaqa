<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogService;
use App\Domain\Identity\Events\ActivationEmailTokenIssued;
use App\Domain\Identity\Events\PhoneOtpIssued;
use App\Models\User;
use App\Models\UserPhoneOtp;
use App\Models\UserVerificationToken;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountActivationService
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function issueActivationChallenges(User $user): void
    {
        // Only issue the challenge(s) for the chosen primary contact method.
        // Fallback (null/unknown) keeps legacy behavior for existing accounts.
        if ($user->login_identifier_type === 'email') {
            if ($user->email) {
                $this->issueEmailActivationToken($user);
            }
            return;
        }

        if ($user->login_identifier_type === 'phone') {
            if ($user->phone_primary) {
                $this->issuePhoneOtp($user);
            }
            return;
        }

        if ($user->email) {
            $this->issueEmailActivationToken($user);
        }
        if ($user->phone_primary) {
            $this->issuePhoneOtp($user);
        }
    }

    public function issueEmailActivationToken(User $user): string
    {
        $token = Str::random(48);
        $tokenHash = hash('sha256', $token);
        $expiresAt = CarbonImmutable::now()->addHours(24);

        DB::transaction(function () use ($user, $tokenHash, $expiresAt) {
            UserVerificationToken::query()
                ->where('user_id', $user->id)
                ->where('type', 'email_activation')
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            UserVerificationToken::create([
                'user_id' => $user->id,
                'type' => 'email_activation',
                'token_hash' => $tokenHash,
                'sent_to' => $user->email,
                'expires_at' => $expiresAt,
                'used_at' => null,
                'attempt_count' => 0,
                'resent_count' => 0,
                'last_sent_at' => now(),
            ]);
        });

        $this->audit->record(
            eventType: 'identity.activation_email_issued',
            module: 'Identity',
            actionName: 'activation_email_issued',
            message: 'Account activation email token issued.',
            entityType: $user::class,
            entityId: $user->id,
            metadata: [
                'sent_to' => $user->email,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            actor: $user,
        );

        event(new ActivationEmailTokenIssued($user, $token, $expiresAt));

        return $token;
    }

    public function verifyEmailActivationToken(string $token): User
    {
        $tokenHash = hash('sha256', $token);

        $record = UserVerificationToken::query()
            ->where('type', 'email_activation')
            ->where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'token' => 'The activation token is invalid or has expired.',
            ]);
        }

        $user = $record->user;

        DB::transaction(function () use ($record, $user) {
            $record->forceFill(['used_at' => now()])->save();

            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        });

        event(new Verified($user));

        $this->audit->record(
            eventType: 'identity.email_activated',
            module: 'Identity',
            actionName: 'email_activated',
            message: 'Email activated via token.',
            entityType: $user::class,
            entityId: $user->id,
            metadata: [
                'email' => $user->email,
            ],
            actor: $user,
        );

        $this->activateIfReady($user);

        return $user;
    }

    public function issuePhoneOtp(User $user): string
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = CarbonImmutable::now()->addMinutes(10);

        DB::transaction(function () use ($user, $code, $expiresAt) {
            UserPhoneOtp::query()
                ->where('user_id', $user->id)
                ->where('purpose', PasswordResetService::OTP_PURPOSE_ACTIVATION)
                ->whereNull('verified_at')
                ->update(['verified_at' => now()]);

            UserPhoneOtp::create([
                'user_id' => $user->id,
                'phone_number' => $user->phone_primary,
                'purpose' => PasswordResetService::OTP_PURPOSE_ACTIVATION,
                'code_hash' => Hash::make($code),
                'expires_at' => $expiresAt,
                'verified_at' => null,
                'attempt_count' => 0,
                'resent_count' => 0,
                'last_sent_at' => now(),
            ]);
        });

        $this->audit->record(
            eventType: 'identity.phone_otp_issued',
            module: 'Identity',
            actionName: 'phone_otp_issued',
            message: 'Phone OTP issued.',
            entityType: $user::class,
            entityId: $user->id,
            metadata: [
                'phone_number' => $user->phone_primary,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            actor: $user,
        );

        event(new PhoneOtpIssued($user, $code, $expiresAt));

        return $code;
    }

    public function verifyPhoneOtp(User $user, string $code): void
    {
        $otp = UserPhoneOtp::query()
            ->where('user_id', $user->id)
            ->where('phone_number', $user->phone_primary)
            ->where('purpose', PasswordResetService::OTP_PURPOSE_ACTIVATION)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp || ! Hash::check($code, $otp->code_hash)) {
            if ($otp) {
                $otp->increment('attempt_count');
            }

            throw ValidationException::withMessages([
                'code' => 'The OTP code is invalid or has expired.',
            ]);
        }

        DB::transaction(function () use ($otp, $user) {
            $otp->forceFill(['verified_at' => now()])->save();

            if (! $user->phone_verified_at) {
                $user->forceFill(['phone_verified_at' => now()])->save();
            }
        });

        $this->audit->record(
            eventType: 'identity.phone_verified',
            module: 'Identity',
            actionName: 'phone_verified',
            message: 'Phone verified via OTP.',
            entityType: $user::class,
            entityId: $user->id,
            metadata: [
                'phone_number' => $user->phone_primary,
            ],
            actor: $user,
        );

        $this->activateIfReady($user);
    }

    public function activateIfReady(User $user): bool
    {
        $user->refresh();

        if ($user->is_active) {
            return true;
        }

        if ($user->login_identifier_type === 'email') {
            if (! $user->email_verified_at) {
                return false;
            }
        } elseif ($user->login_identifier_type === 'phone') {
            if (! $user->phone_verified_at) {
                return false;
            }
        } else {
            // Legacy / admin accounts: require both if present.
            if (! $user->email_verified_at || ! $user->phone_verified_at) {
                return false;
            }
        }

        $user->forceFill(['is_active' => true])->save();

        $this->audit->record(
            eventType: 'identity.account_activated',
            module: 'Identity',
            actionName: 'account_activated',
            message: 'Account activated.',
            entityType: $user::class,
            entityId: $user->id,
            actor: $user,
        );

        return true;
    }
}

