<?php

namespace App\Domain\Fees;

use App\Models\Application;
use App\Models\BillingCategory;
use App\Models\FeeStructure;
use App\Models\QualificationType;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class QualificationFeeResolver
{
    /**
     * @return array{
     *   qualification_type: QualificationType,
     *   billing_category: BillingCategory,
     *   fee_structure: FeeStructure,
     *   currency: string,
     *   fee_cents: int,
     *   processing_days: int|null
     * }
     */
    public function resolve(int $qualificationTypeId, bool $isForeign, Carbon $at): array
    {
        $qualificationType = QualificationType::query()
            ->with('billingCategory')
            ->where('is_active', true)
            ->findOrFail($qualificationTypeId);

        $category = $qualificationType->billingCategory;
        if (! $category) {
            throw ValidationException::withMessages([
                'fee' => 'Qualification type has no billing category configured.',
            ]);
        }
        if ($isForeign) {
            $foreignCategory = BillingCategory::query()
                ->where('code', BillingCategory::CODE_FOREIGN_QUALIFICATIONS)
                ->where('is_active', true)
                ->first();
            if ($foreignCategory) {
                $category = $foreignCategory;
            }
        }

        $feeStructure = FeeStructure::query()
            ->where('billing_category_id', $category->id)
            ->where('is_active', true)
            ->where('effective_from', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $at);
            })
            ->orderByDesc('effective_from')
            ->first();

        if (! $feeStructure) {
            throw ValidationException::withMessages([
                'fee' => 'No active fee structure is configured for the selected qualification category.',
            ]);
        }

        $feeCents = $isForeign ? $feeStructure->foreign_fee_cents : $feeStructure->local_fee_cents;
        if ($feeCents === null) {
            throw ValidationException::withMessages([
                'fee' => 'No fee is configured for the selected qualification and locality.',
            ]);
        }

        $processingDays = $isForeign ? $category->foreign_processing_days : $category->local_processing_days;

        return [
            'qualification_type' => $qualificationType,
            'billing_category' => $category,
            'fee_structure' => $feeStructure,
            'currency' => $feeStructure->currency,
            'fee_cents' => (int) $feeCents,
            'processing_days' => $processingDays !== null ? (int) $processingDays : null,
        ];
    }

    /**
     * Total verification fee for all qualifications on the application (current ZQF + locality).
     */
    public function totalVerificationFeesCents(Application $application, ?Carbon $at = null): int
    {
        $at ??= now();
        $application->loadMissing('qualifications');

        $sum = 0;
        foreach ($application->qualifications as $q) {
            $typeId = (int) ($q->qualification_type_id ?? 0);
            if ($typeId < 1) {
                continue;
            }
            $resolved = $this->resolve($typeId, (bool) $q->is_foreign_qualification, $at);
            $sum += (int) $resolved['fee_cents'];
        }

        return $sum;
    }
}
