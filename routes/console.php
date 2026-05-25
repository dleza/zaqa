<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use App\Domain\Payments\Gateways\CGrate\CGrateClient;
use App\Enums\VerificationState;
use App\Models\ApplicationComment;
use App\Models\Qualification;
use App\Notifications\Verification\QualificationSentBackToApplicantPortalNotification;
use App\Support\Phone\ZambiaMsisdnNormalizer;

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

Artisan::command('cgrate:test-payment {mobile} {amount} {--query : Query status after initiation} {--wait=10 : Seconds to wait before querying}', function (CGrateClient $client, string $mobile, string $amount) {
    if (app()->environment('production') && ! (bool) config('cgrate.allow_test_command_in_production')) {
        $this->error('Refusing to run cGrate test payment in production.');
        $this->error('Set CGRATE_ALLOW_TEST_COMMAND_IN_PRODUCTION=true to override.');

        return 1;
    }

    if (! (bool) config('cgrate.enabled')) {
        $this->error('cGrate is disabled (CGRATE_ENABLED=false).');
        return 1;
    }

    $format = (string) config('cgrate.msisdn_format', 'local');
    try {
        $msisdn = ZambiaMsisdnNormalizer::normalizeForCGrate($mobile, $format);
    } catch (\InvalidArgumentException $e) {
        $this->error('Invalid mobile number: '.$e->getMessage());
        return 1;
    }

    $paymentReference = 'ZAQA-TEST-'.Str::upper(Str::random(12));

    $this->info('Initiating...');
    $resp = $client->processCustomerPayment(transactionAmount: (string) $amount, customerMobile: $msisdn, paymentReference: $paymentReference);

    $this->line('paymentReference: '.$paymentReference);
    $this->line('responseCode: '.($resp->responseCode ?? 'null'));
    $this->line('responseMessage: '.$resp->responseMessage);
    if ($resp->paymentId) {
        $this->line('paymentID: '.$resp->paymentId);
    }

    if (! (bool) $this->option('query')) {
        return 0;
    }

    $wait = (int) $this->option('wait');
    $wait = max(0, $wait);
    if ($wait > 0) {
        $this->info("Waiting {$wait}s before query...");
        sleep($wait);
    }

    $this->info('Querying...');
    $q = $client->queryCustomerPayment(paymentReference: $paymentReference);
    $this->line('query.responseCode: '.($q->responseCode ?? 'null'));
    $this->line('query.responseMessage: '.$q->responseMessage);
    if ($q->paymentId) {
        $this->line('query.paymentID: '.$q->paymentId);
    }

    return 0;
})->purpose('Initiate a cGrate test Customer Payment (UAT tooling).');
