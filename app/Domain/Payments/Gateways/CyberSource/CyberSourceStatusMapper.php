<?php

namespace App\Domain\Payments\Gateways\CyberSource;

use App\Enums\PaymentStatus;

final class CyberSourceStatusMapper
{
    public function toNormalizedStatus(mixed $gatewayStatus, ?string $reasonCode = null): string
    {
        $status = $this->normalizeToken($this->extractStatus($gatewayStatus));
        $reason = $this->normalizeToken($reasonCode ?? $this->extractReason($gatewayStatus));

        if ($this->isExpired($status, $reason)) {
            return PaymentStatus::Expired->value;
        }

        if ($this->isRejected($status, $reason)) {
            return PaymentStatus::Rejected->value;
        }

        if ($this->isPending($status, $reason)) {
            return PaymentStatus::PendingConfirmation->value;
        }

        if ($this->isConfirmed($status, $reason)) {
            return PaymentStatus::Confirmed->value;
        }

        return PaymentStatus::Failed->value;
    }

    public function toGatewayVerificationStatus(mixed $gatewayStatus, ?string $reasonCode = null): string
    {
        $status = $this->toNormalizedStatus($gatewayStatus, $reasonCode);

        return $status === PaymentStatus::PendingConfirmation->value ? 'pending' : $status;
    }

    private function extractStatus(mixed $value): ?string
    {
        return $this->extractString($value, 'status', 'getStatus');
    }

    private function extractReason(mixed $value): ?string
    {
        if (is_string($value)) {
            return null;
        }

        $reason = $this->extractString($value, 'reason', 'getReason');
        if ($reason !== null) {
            return $reason;
        }

        if (is_array($value) && isset($value['errorInformation'])) {
            return $this->extractString($value['errorInformation'], 'reason', 'getReason');
        }

        if (is_object($value) && method_exists($value, 'getErrorInformation')) {
            return $this->extractString($value->getErrorInformation(), 'reason', 'getReason');
        }

        return null;
    }

    private function extractString(mixed $value, string $arrayKey, string $getter): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value) && array_key_exists($arrayKey, $value)) {
            $extracted = trim((string) $value[$arrayKey]);

            return $extracted !== '' ? $extracted : null;
        }

        if (is_object($value) && method_exists($value, $getter)) {
            $extracted = trim((string) $value->{$getter}());

            return $extracted !== '' ? $extracted : null;
        }

        return null;
    }

    private function normalizeToken(?string $value): string
    {
        return strtoupper(str_replace([' ', '-'], '_', trim((string) $value)));
    }

    private function isConfirmed(string $status, string $reason): bool
    {
        if ($reason !== '') {
            return false;
        }

        return in_array($status, [
            'AUTHORIZED',
            'CAPTURED',
            'SETTLED',
            'ACCEPTED',
        ], true);
    }

    private function isPending(string $status, string $reason): bool
    {
        return in_array($status, [
            'PENDING',
            'PENDING_REVIEW',
            'AUTHORIZED_PENDING_REVIEW',
            'PENDING_AUTHENTICATION',
            'REVIEW',
            'SUBMITTED',
        ], true) || in_array($reason, [
            'PENDING_AUTHENTICATION',
            'DECISION_PROFILE_REVIEW',
            'CONSUMER_AUTHENTICATION_REQUIRED',
        ], true);
    }

    private function isRejected(string $status, string $reason): bool
    {
        return in_array($status, [
            'DECLINED',
            'REJECTED',
            'AUTHORIZED_RISK_DECLINED',
        ], true) || in_array($reason, [
            'AVS_FAILED',
            'BLOCKED_BY_CARDHOLDER',
            'CV_FAILED',
            'CVN_NOT_MATCH',
            'DECLINED_CHECK',
            'DECISION_PROFILE_REJECT',
            'EXCEEDS_CREDIT_LIMIT',
            'GENERAL_DECLINE',
            'INSUFFICIENT_FUND',
            'INVALID_ACCOUNT',
            'INVALID_CVN',
            'PAYMENT_REFUSED',
            'PROCESSOR_DECLINED',
            'SCORE_EXCEEDS_THRESHOLD',
            'STOLEN_LOST_CARD',
            'UNAUTHORIZED_CARD',
        ], true);
    }

    private function isExpired(string $status, string $reason): bool
    {
        return in_array($status, [
            'EXPIRED',
            'TOKEN_EXPIRED',
            'SESSION_EXPIRED',
        ], true) || in_array($reason, [
            'TOKEN_EXPIRED',
            'SESSION_EXPIRED',
        ], true);
    }
}
