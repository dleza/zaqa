<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Enums\VerificationState;
use App\Models\ApplicationComment;
use App\Models\Qualification;
use App\Notifications\Verification\QualificationSentBackToApplicantPortalNotification;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:backfill-qualification-sendbacks {email?}', function (?string $email = null) {
    $email = trim((string) ($email ?? ''));
    $email = $email !== '' ? $email : null;

    $query = Qualification::query()
        ->where('verification_state', VerificationState::ReturnedToApplicant->value)
        ->whereNotNull('returned_to_applicant_at')
        ->with(['application.applicant', 'awardingInstitution', 'sendBackBy'])
        ->orderByDesc('returned_to_applicant_at');

    if ($email) {
        $query->whereHas('application.applicant', fn ($q) => $q->where('email', $email));
    }

    $qualifications = $query->get();
    if ($qualifications->count() === 0) {
        $this->info('No returned qualifications found.');
        return;
    }

    $created = 0;
    $skipped = 0;
    $existingByUser = [];

    foreach ($qualifications as $qualification) {
        $application = $qualification->application;
        $applicant = $application?->applicant;
        if (! $application || ! $applicant) {
            $skipped++;
            continue;
        }

        $uid = (int) $applicant->id;
        if (! array_key_exists($uid, $existingByUser)) {
            $existingByUser[$uid] = $applicant->notifications()
                ->where('type', 'verification.qualification_sent_back_to_applicant')
                ->get()
                ->map(fn ($n) => (int) ($n->data['qualification_id'] ?? 0))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $qid = (int) $qualification->id;
        if (in_array($qid, $existingByUser[$uid], true)) {
            $skipped++;
            continue;
        }

        $commentRow = ApplicationComment::query()
            ->where('qualification_id', $qid)
            ->where('type', 'send_back')
            ->where('visibility', 'applicant_visible')
            ->orderByDesc('id')
            ->first();
        $comment = (string) ($commentRow?->body ?? '');

        $applicationRef = (string) ($application->application_number ?? '—');
        $qualificationTitle = (string) ($qualification->title_of_qualification ?? 'Qualification');

        $awardingInstitution = $qualification->awardingInstitution?->name
            ?? $qualification->awarding_institution_name_other
            ?? $qualification->awarding_institution_name;
        $awardingInstitution = trim((string) $awardingInstitution) !== '' ? (string) $awardingInstitution : null;

        $actorName = (string) ($qualification->sendBackBy?->name ?? 'ZAQA officer');

        $applicant->notify(new QualificationSentBackToApplicantPortalNotification(
            qualificationId: $qid,
            applicationId: (int) $application->id,
            applicationReference: $applicationRef,
            qualificationTitle: $qualificationTitle,
            awardingInstitution: $awardingInstitution,
            actorName: $actorName,
            comment: $comment,
        ));

        $existingByUser[$uid][] = $qid;
        $created++;
    }

    $this->info("Backfill completed: created={$created}, skipped={$skipped}.");
})->purpose('Backfill applicant portal notifications for returned qualifications.');
