<?php

namespace App\Domain\Documents;

use App\Enums\DocumentType;
use App\Models\QualificationDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Rules for which qualification documents count as active review evidence.
 */
final class QualificationDocumentEvidence
{
    /**
     * @return list<DocumentType>
     */
    public static function applicantEvidenceTypes(): array
    {
        return [
            DocumentType::NrcCopy,
            DocumentType::PassportCopy,
            DocumentType::CertificateCopy,
            DocumentType::Transcript,
            DocumentType::ConsentFormSigned,
            DocumentType::ZaqaConsentFormSigned,
            DocumentType::OtherSupportingDocument,
            DocumentType::PaymentProof,
        ];
    }

    public static function isApplicantEvidenceType(DocumentType|string|null $type): bool
    {
        if ($type === null) {
            return false;
        }

        $value = $type instanceof DocumentType ? $type->value : (string) $type;

        foreach (self::applicantEvidenceTypes() as $candidate) {
            if ($candidate->value === $value) {
                return true;
            }
        }

        return false;
    }

    public static function isActiveEvidence(QualificationDocument $document): bool
    {
        return (bool) $document->is_current_version && $document->deleted_at === null;
    }

    /**
     * @param  Builder<QualificationDocument>  $query
     * @return Builder<QualificationDocument>
     */
    public static function applyActiveEvidenceScope(Builder $query): Builder
    {
        return $query
            ->where('is_current_version', true)
            ->whereNull('deleted_at');
    }

    /**
     * @param  Collection<int, QualificationDocument>  $documents
     * @return Collection<int, QualificationDocument>
     */
    public static function filterActiveEvidence(Collection $documents): Collection
    {
        return $documents
            ->filter(fn (QualificationDocument $document) => self::isActiveEvidence($document))
            ->values();
    }

    /**
     * Officer review evidence list: latest active applicant-uploaded documents only.
     *
     * @param  Collection<int, QualificationDocument>  $documents
     * @return Collection<int, QualificationDocument>
     */
    public static function filterOfficerApplicantEvidence(Collection $documents): Collection
    {
        return self::filterActiveEvidence($documents)
            ->filter(fn (QualificationDocument $document) => self::isApplicantEvidenceType($document->document_type))
            ->sortByDesc('id')
            ->values();
    }

    /**
     * All active documents for review UIs (applicant + officer internal attachments).
     *
     * @param  Collection<int, QualificationDocument>  $documents
     * @return Collection<int, QualificationDocument>
     */
    public static function filterActiveForReview(Collection $documents): Collection
    {
        return self::filterActiveEvidence($documents)
            ->sortByDesc('id')
            ->values();
    }
}
