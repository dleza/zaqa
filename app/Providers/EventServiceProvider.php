<?php

namespace App\Providers;

use App\Domain\Applications\Events\ApplicationSubmitted;
use App\Domain\Applications\Listeners\SendApplicationSubmittedNotification;
use App\Domain\Audit\Listeners\LogEmailVerified;
use App\Domain\Audit\Listeners\LogPasswordReset;
use App\Domain\Audit\Listeners\LogUserLoggedIn;
use App\Domain\Audit\Listeners\LogUserLoggedOut;
use App\Domain\Audit\Listeners\LogUserLoginFailed;
use App\Domain\Audit\Listeners\LogUserRegistered;
use App\Domain\Finance\Events\PaymentProofApproved;
use App\Domain\Finance\Events\PaymentProofRejected;
use App\Domain\Finance\Listeners\SendPaymentProofApprovedNotification;
use App\Domain\Finance\Listeners\SendPaymentProofRejectedNotification;
use App\Domain\Identity\Events\ActivationEmailTokenIssued;
use App\Domain\Identity\Events\PhoneOtpIssued;
use App\Domain\Identity\Listeners\SendActivationEmail;
use App\Domain\Identity\Listeners\SendPhoneOtpSms;
use App\Domain\Verification\Events\ApplicationAssignedToLevel1;
use App\Domain\Verification\Events\ApplicationLevel1Completed;
use App\Domain\Verification\Events\ApplicationSentBackToApplicant;
use App\Domain\Verification\Events\QualificationAssignedToVerifier;
use App\Domain\Verification\Events\QualificationLevel1Completed;
use App\Domain\Verification\Events\QualificationSentBackToApplicant;
use App\Domain\Verification\Listeners\CreateQualificationAssignmentPortalNotification;
use App\Domain\Verification\Listeners\CreateQualificationLevel1CompletedPortalNotification;
use App\Domain\Verification\Listeners\CreateQualificationSendBackApplicantPortalNotification;
use App\Domain\Verification\Listeners\SendAssignmentNotification;
use App\Domain\Verification\Listeners\SendLevel1CompletedNotification;
use App\Domain\Verification\Listeners\SendQualificationLevel1CompletedNotification;
use App\Domain\Verification\Listeners\SendQualificationSendBackNotification;
use App\Domain\Verification\Listeners\SendSendBackNotification;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        ActivationEmailTokenIssued::class => [
            SendActivationEmail::class,
        ],
        PhoneOtpIssued::class => [
            SendPhoneOtpSms::class,
        ],
        ApplicationSubmitted::class => [
            SendApplicationSubmittedNotification::class,
        ],
        ApplicationAssignedToLevel1::class => [
            SendAssignmentNotification::class,
        ],
        ApplicationLevel1Completed::class => [
            SendLevel1CompletedNotification::class,
        ],
        ApplicationSentBackToApplicant::class => [
            SendSendBackNotification::class,
        ],
        QualificationAssignedToVerifier::class => [
            SendAssignmentNotification::class,
            CreateQualificationAssignmentPortalNotification::class,
        ],
        QualificationSentBackToApplicant::class => [
            SendQualificationSendBackNotification::class,
            CreateQualificationSendBackApplicantPortalNotification::class,
        ],
        QualificationLevel1Completed::class => [
            SendQualificationLevel1CompletedNotification::class,
            CreateQualificationLevel1CompletedPortalNotification::class,
        ],
        PaymentProofApproved::class => [
            SendPaymentProofApprovedNotification::class,
        ],
        PaymentProofRejected::class => [
            SendPaymentProofRejectedNotification::class,
        ],
        Registered::class => [
            LogUserRegistered::class,
        ],
        Login::class => [
            LogUserLoggedIn::class,
        ],
        Failed::class => [
            LogUserLoginFailed::class,
        ],
        Logout::class => [
            LogUserLoggedOut::class,
        ],
        PasswordReset::class => [
            LogPasswordReset::class,
        ],
        Verified::class => [
            LogEmailVerified::class,
        ],
    ];
}
