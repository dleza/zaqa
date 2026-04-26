<?php

namespace App\Domain\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Money\MoneyNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PaymentSearchService
{
    public function proofQueue(Request $request): LengthAwarePaginator
    {
        $query = Payment::query()
            ->with(['application.applicant', 'invoice', 'proofDocument', 'reviewedBy'])
            ->whereIn('method', [PaymentMethod::BankDeposit, PaymentMethod::BankTransfer]);

        $this->applyCommonFilters($query, $request);

        $uploadedFrom = trim((string) $request->query('uploaded_from', ''));
        $uploadedTo = trim((string) $request->query('uploaded_to', ''));
        if ($uploadedFrom !== '') {
            $query->whereDate('awaiting_finance_review_at', '>=', $uploadedFrom);
        }
        if ($uploadedTo !== '') {
            $query->whereDate('awaiting_finance_review_at', '<=', $uploadedTo);
        }

        // Default: only items awaiting review.
        $status = trim((string) $request->query('status', 'awaiting_finance_review'));
        if ($status !== '') {
            $query->where('status', $status);
        }

        return $query
            ->orderByRaw("status = 'awaiting_finance_review' desc")
            ->orderByDesc('awaiting_finance_review_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    public function payments(Request $request): LengthAwarePaginator
    {
        $query = Payment::query()
            ->with(['application.applicant', 'invoice', 'proofDocument', 'reviewedBy'])
            ->whereIn('status', [
                PaymentStatus::Initiated,
                PaymentStatus::PendingConfirmation,
                PaymentStatus::AwaitingFinanceReview,
                PaymentStatus::Confirmed,
                PaymentStatus::Rejected,
                PaymentStatus::Failed,
                PaymentStatus::Expired,
                PaymentStatus::Draft,
            ]);

        $this->applyCommonFilters($query, $request);

        $confirmedFrom = trim((string) $request->query('confirmed_from', ''));
        $confirmedTo = trim((string) $request->query('confirmed_to', ''));
        if ($confirmedFrom !== '') {
            $query->whereDate('confirmed_at', '>=', $confirmedFrom);
        }
        if ($confirmedTo !== '') {
            $query->whereDate('confirmed_at', '<=', $confirmedTo);
        }

        $initiatedFrom = trim((string) $request->query('initiated_from', ''));
        $initiatedTo = trim((string) $request->query('initiated_to', ''));
        if ($initiatedFrom !== '') {
            $query->whereDate('initiated_at', '>=', $initiatedFrom);
        }
        if ($initiatedTo !== '') {
            $query->whereDate('initiated_at', '<=', $initiatedTo);
        }

        return $query
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    private function applyCommonFilters(Builder $query, Request $request): void
    {
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function (Builder $qq) use ($q) {
                $qq->where('provider_reference', 'like', '%'.$q.'%')
                    ->orWhere('provider_transaction_id', 'like', '%'.$q.'%')
                    ->orWhereHas('application', fn (Builder $a) => $a->where('application_number', 'like', '%'.$q.'%'))
                    ->orWhereHas('invoice', fn (Builder $i) => $i->where('invoice_number', 'like', '%'.$q.'%'))
                    ->orWhereHas('application.applicant', function (Builder $u) use ($q) {
                        $u->where('name', 'like', '%'.$q.'%')
                            ->orWhere('email', 'like', '%'.$q.'%')
                            ->orWhere('phone_primary', 'like', '%'.$q.'%');
                    });
            });
        }

        $method = trim((string) $request->query('method', ''));
        if ($method !== '') {
            $query->where('method', $method);
        }

        $provider = trim((string) $request->query('provider', ''));
        if ($provider !== '') {
            $query->where('provider', $provider);
        }

        $status = trim((string) $request->query('status', ''));
        if ($status !== '' && $request->route()?->getName() !== 'admin.finance.payment_proofs.index') {
            $query->where('status', $status);
        }

        $currency = trim((string) $request->query('currency', ''));
        if ($currency !== '') {
            $query->where('currency', strtoupper($currency));
        }

        $reviewedBy = trim((string) $request->query('reviewed_by', ''));
        if ($reviewedBy !== '') {
            $query->where('reviewed_by_user_id', (int) $reviewedBy);
        }

        $amountMin = trim((string) $request->query('amount_min', ''));
        $amountMax = trim((string) $request->query('amount_max', ''));
        if ($amountMin !== '') {
            try {
                $cents = MoneyNormalizer::toMinorUnits($amountMin);
            } catch (\Throwable) {
                $cents = (int) $amountMin;
            }
            if ($cents !== null) {
                $query->where('amount_cents', '>=', $cents);
            }
        }
        if ($amountMax !== '') {
            try {
                $cents = MoneyNormalizer::toMinorUnits($amountMax);
            } catch (\Throwable) {
                $cents = (int) $amountMax;
            }
            if ($cents !== null) {
                $query->where('amount_cents', '<=', $cents);
            }
        }

        $localForeign = trim((string) $request->query('is_foreign', ''));
        if ($localForeign === '1' || $localForeign === '0') {
            $isForeign = $localForeign === '1';
            $query->whereHas('application', fn (Builder $a) => $a->where('is_foreign', $isForeign));
        }
    }
}
