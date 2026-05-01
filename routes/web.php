<?php

use App\Http\Controllers\Admin\AdminApplicantsController;
use App\Http\Controllers\Admin\AdminApplicationsController;
use App\Http\Controllers\Admin\AdminApplicationsTrackController;
use App\Http\Controllers\Admin\AdminCertificatesController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\AdminRolesController;
use App\Http\Controllers\Admin\AdminSlaReportController;
use App\Http\Controllers\Admin\AdminUsersController;
use App\Http\Controllers\Admin\Finance\AdminFinanceDashboardController;
use App\Http\Controllers\Admin\Finance\AdminFinancePaymentProofController;
use App\Http\Controllers\Admin\Finance\AdminFinancePaymentsController;
use App\Http\Controllers\Admin\Settings\AdminAwardingInstitutionsController;
use App\Http\Controllers\Admin\Settings\AdminCertificateSubjectsController;
use App\Http\Controllers\Admin\Settings\AdminCountriesController;
use App\Http\Controllers\Admin\Settings\AdminDepartmentsController;
use App\Http\Controllers\Admin\Settings\AdminFeesController;
use App\Http\Controllers\Admin\Settings\AdminQualificationTypesController;
use App\Http\Controllers\Admin\Verification\AdminVerificationApplicationController;
use App\Http\Controllers\Admin\Verification\AdminVerificationAssignedToMeController;
use App\Http\Controllers\Admin\Verification\AdminVerificationCategoryController;
use App\Http\Controllers\Admin\Verification\AdminVerificationDocumentController;
use App\Http\Controllers\Admin\Verification\AdminVerificationPoolController;
use App\Http\Controllers\Admin\Verification\AdminVerificationQualificationController;
use App\Http\Controllers\Applicant\ApplicantApplicationController;
use App\Http\Controllers\Applicant\ApplicantApplicationTrackingController;
use App\Http\Controllers\Applicant\ApplicantBillingController;
use App\Http\Controllers\Applicant\ApplicantConsentController;
use App\Http\Controllers\Applicant\ApplicantDetailsController;
use App\Http\Controllers\Applicant\ApplicantDocumentController;
use App\Http\Controllers\Applicant\ApplicantPaymentController;
use App\Http\Controllers\Applicant\ApplicantProfileController;
use App\Http\Controllers\Applicant\ApplicantProfileEditController;
use App\Http\Controllers\Applicant\ApplicantProfileIdentityDocumentController;
use App\Http\Controllers\Applicant\ApplicantQualificationController;
use App\Http\Controllers\Applicant\ApplicantReferenceController;
use App\Http\Controllers\Applicant\ApplicantServiceFeedbackController;
use App\Http\Controllers\Applicant\DashboardController;
use App\Http\Controllers\Auth\AccountActivationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredApplicantController;
use App\Http\Controllers\Finance\FinanceApplicationTrackingController;
use App\Http\Controllers\Finance\FinancePaymentProofController;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Authenticated users must not be sent to `login` (guest middleware redirects them using
// RedirectIfAuthenticated, which otherwise matched the old `home` route and caused a redirect loop).
Route::get('/', function () {
    if (! Auth::check()) {
        return redirect()->route('login');
    }

    $user = Auth::user();

    if (! $user->is_active) {
        return redirect()->route('activation.show');
    }

    return $user->can('dashboard.view')
        ? redirect()->route('admin.dashboard')
        : redirect()->route('applicant.dashboard');
})->name('landing');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredApplicantController::class, 'create'])->name('register');
    Route::post('/register/individual', [RegisteredApplicantController::class, 'storeIndividual'])->name('register.individual');
    Route::post('/register/institution', [RegisteredApplicantController::class, 'storeInstitution'])->name('register.institution');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::get('/activate/email', [AccountActivationController::class, 'verifyEmail'])->name('activation.email.verify');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/activate', [AccountActivationController::class, 'show'])->name('activation.show');
    Route::post('/activate/phone-otp', [AccountActivationController::class, 'verifyPhoneOtp'])->name('activation.phone.verify');
    Route::post('/activate/resend-email', [AccountActivationController::class, 'resendEmail'])->name('activation.resend.email');
    Route::post('/activate/resend-otp', [AccountActivationController::class, 'resendOtp'])->name('activation.resend.otp');

    Route::middleware(EnsureAccountIsActive::class)->prefix('applicant')->name('applicant.')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/applications', [ApplicantApplicationController::class, 'index'])->name('applications.index');
        Route::get('/applications/new', [ApplicantApplicationController::class, 'create'])->name('applications.create');
        Route::post('/applications', [ApplicantApplicationController::class, 'store'])->name('applications.store');
        Route::get('/applications/{application}', [ApplicantApplicationController::class, 'show'])->name('applications.show');
        Route::get('/applications/{application}/track', [ApplicantApplicationTrackingController::class, 'show'])->name('applications.track');
        Route::get('/applications/{application}/track-summary', [ApplicantApplicationTrackingController::class, 'summary'])->name('applications.track.summary');
        Route::get('/applications/{application}/edit', [ApplicantApplicationController::class, 'edit'])->name('applications.edit');
        Route::patch('/applications/{application}', [ApplicantApplicationController::class, 'update'])->name('applications.update');
        Route::patch('/applications/{application}/wizard-declarations', [ApplicantApplicationController::class, 'saveWizardDeclarations'])->name('applications.wizard_declarations.update');
        Route::delete('/applications/{application}', [ApplicantApplicationController::class, 'destroy'])->name('applications.destroy');
        Route::post('/applications/{application}/submit', [ApplicantApplicationController::class, 'submit'])->name('applications.submit');
        Route::get('/applications/{application}/feedback', [ApplicantServiceFeedbackController::class, 'show'])->name('applications.feedback.show');
        Route::post('/applications/{application}/feedback', [ApplicantServiceFeedbackController::class, 'store'])->name('applications.feedback.store');
        Route::post('/applications/{application}/feedback/skip', [ApplicantServiceFeedbackController::class, 'skip'])->name('applications.feedback.skip');

        Route::get('/reference/awarding-institutions', [ApplicantReferenceController::class, 'awardingInstitutions'])->name('reference.awarding_institutions');
        Route::get('/reference/awarding-institutions/{awardingInstitution}/consent-form', '\App\Http\Controllers\Applicant\ApplicantAwardingInstitutionConsentFormController@download')
            ->name('reference.awarding_institutions.consent_form')
            ->middleware('signed');

        Route::put('/applications/{application}/applicant-details', [ApplicantDetailsController::class, 'update'])->name('applications.applicant_details.update');

        Route::put('/applications/{application}/qualification', [ApplicantQualificationController::class, 'upsert'])->name('applications.qualification.upsert');
        Route::put('/applications/{application}/qualification/details', [ApplicantQualificationController::class, 'upsertDetails'])->name('applications.qualification.details.upsert');
        Route::put('/applications/{application}/qualification/subject-results', [ApplicantQualificationController::class, 'upsertSubjectResults'])->name('applications.qualification.subject_results.upsert');
        Route::post('/applications/{application}/qualifications', [ApplicantQualificationController::class, 'store'])->name('applications.qualifications.store');
        Route::delete('/applications/{application}/qualifications/{qualification}', [ApplicantQualificationController::class, 'destroy'])->name('applications.qualifications.destroy');

        Route::post('/applications/{application}/documents', [ApplicantDocumentController::class, 'store'])->name('applications.documents.store');
        Route::get('/documents/{document}/preview', [ApplicantDocumentController::class, 'preview'])->name('documents.preview')->middleware('signed');
        Route::get('/documents/{document}/download', [ApplicantDocumentController::class, 'download'])->name('documents.download')->middleware('signed');
        Route::delete('/documents/{document}', [ApplicantDocumentController::class, 'destroy'])->name('documents.destroy');

        Route::post('/applications/{application}/consent/accept', [ApplicantConsentController::class, 'acceptLocal'])->name('applications.consent.accept');
        Route::post('/applications/{application}/consent/foreign-upload', [ApplicantConsentController::class, 'uploadForeign'])->name('applications.consent.foreign_upload');

        Route::post('/applications/{application}/payment/prepare', [ApplicantPaymentController::class, 'prepare'])->name('applications.payment.prepare');
        Route::post('/applications/{application}/payment/select', [ApplicantPaymentController::class, 'selectMethod'])->name('applications.payment.select');
        Route::post('/applications/{application}/payment/initiate-card', [ApplicantPaymentController::class, 'initiateCardForApplication'])->name('applications.payment.initiate_card');
        Route::post('/applications/{application}/payment/initiate-mobile-money', [ApplicantPaymentController::class, 'initiateMobileMoneyForApplication'])->name('applications.payment.initiate_mobile_money');
        Route::post('/applications/{application}/payment/upload-proof', [ApplicantPaymentController::class, 'uploadProofForApplication'])->name('applications.payment.upload_proof');
        Route::post('/payments/{payment}/initiate-card', [ApplicantPaymentController::class, 'initiateCard'])->name('payments.initiate_card');
        Route::post('/payments/{payment}/initiate-mobile-money', [ApplicantPaymentController::class, 'initiateMobileMoney'])->name('payments.initiate_mobile_money');
        Route::post('/payments/{payment}/upload-proof', [ApplicantPaymentController::class, 'uploadProof'])->name('payments.upload_proof');
        Route::get('/payments/{payment}/return', [ApplicantPaymentController::class, 'returnFromProvider'])->name('payments.return');

        Route::get('/invoices', [ApplicantBillingController::class, 'invoices'])->name('invoices');
        Route::get('/invoices/{invoice}', [ApplicantBillingController::class, 'showInvoice'])->name('invoices.show');
        Route::get('/payments', [ApplicantBillingController::class, 'payments'])->name('payments.index');
        Route::get('/payments/{payment}', [ApplicantBillingController::class, 'showPayment'])->name('payments.show');

        Route::get('/profile', [ApplicantProfileController::class, 'show'])->name('profile.show');
        Route::get('/profile/edit', [ApplicantProfileEditController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [ApplicantProfileEditController::class, 'update'])->name('profile.update');
        Route::post('/profile/identity-document', [ApplicantProfileIdentityDocumentController::class, 'store'])->name('profile.identity_document.store');
        Route::delete('/profile/identity-document', [ApplicantProfileIdentityDocumentController::class, 'destroy'])->name('profile.identity_document.destroy');
        Route::get('/change-password', [ApplicantProfileController::class, 'editPassword'])->name('profile.password.edit');
        Route::post('/change-password', [ApplicantProfileController::class, 'updatePassword'])->name('profile.password.update');
    });

    Route::prefix('finance')->name('finance.')->middleware(['auth', 'can:admin.finance.view'])->group(function () {
        Route::get('/payment-proofs', [FinancePaymentProofController::class, 'index'])->name('payment_proofs.index');
        Route::post('/payments/{payment}/approve', [FinancePaymentProofController::class, 'approve'])->name('payments.approve');
        Route::post('/payments/{payment}/reject', [FinancePaymentProofController::class, 'reject'])->name('payments.reject');
        Route::get('/applications/{application}/track', [FinanceApplicationTrackingController::class, 'show'])->name('applications.track');
    });

    Route::prefix('admin')->name('admin.')->middleware(['auth', 'can:dashboard.view'])->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/profile', [AdminProfileController::class, 'show'])->name('profile.show');
        Route::get('/change-password', [AdminProfileController::class, 'editPassword'])->name('profile.password.edit');
        Route::post('/change-password', [AdminProfileController::class, 'updatePassword'])->name('profile.password.update');

        Route::prefix('finance')->name('finance.')->group(function () {
            Route::get('/', [AdminFinanceDashboardController::class, 'index'])
                ->middleware('can:finance.dashboard.view')
                ->name('dashboard');

            Route::get('/payment-proofs', [AdminFinancePaymentProofController::class, 'index'])
                ->middleware('can:finance.payment_proofs.view')
                ->name('payment_proofs.index');
            Route::get('/payment-proofs/{payment}', [AdminFinancePaymentProofController::class, 'show'])
                ->middleware('can:finance.payment_proofs.view')
                ->name('payment_proofs.show');
            Route::post('/payment-proofs/{payment}/approve', [AdminFinancePaymentProofController::class, 'approve'])
                ->middleware('can:finance.payment_proofs.approve')
                ->name('payment_proofs.approve');
            Route::post('/payment-proofs/{payment}/reject', [AdminFinancePaymentProofController::class, 'reject'])
                ->middleware('can:finance.payment_proofs.reject')
                ->name('payment_proofs.reject');

            Route::get('/payments', [AdminFinancePaymentsController::class, 'index'])
                ->middleware('can:finance.payments.view')
                ->name('payments.index');
            Route::get('/payments/{payment}', [AdminFinancePaymentsController::class, 'show'])
                ->middleware('can:finance.payments.detail')
                ->name('payments.show');

            Route::get('/documents/{document}/preview', [AdminFinancePaymentProofController::class, 'preview'])
                ->middleware('can:finance.payment_proofs.view')
                ->name('documents.preview');
            Route::get('/documents/{document}/download', [AdminFinancePaymentProofController::class, 'download'])
                ->middleware('can:finance.payment_proofs.view')
                ->name('documents.download');
        });
        Route::get('/users', [AdminUsersController::class, 'index'])->middleware('can:admin.users.view')->name('users.index');
        Route::get('/users/create', [AdminUsersController::class, 'create'])
            ->middleware('can:admin.users.create')
            ->name('users.create');
        Route::post('/users', [AdminUsersController::class, 'store'])
            ->middleware('can:admin.users.create')
            ->name('users.store');
        Route::get('/users/{user}', [AdminUsersController::class, 'show'])
            ->middleware('can:admin.users.view')
            ->name('users.show');
        Route::post('/users/{user}/block', [AdminUsersController::class, 'block'])
            ->middleware('can:admin.users.disable')
            ->name('users.block');
        Route::post('/users/{user}/unblock', [AdminUsersController::class, 'unblock'])
            ->middleware('can:admin.users.disable')
            ->name('users.unblock');
        Route::get('/applicants', [AdminApplicantsController::class, 'index'])
            ->middleware('can:admin.applicants.view')
            ->name('applicants.index');
        Route::get('/applicants/{user}', [AdminApplicantsController::class, 'show'])
            ->middleware('can:admin.applicants.view')
            ->name('applicants.show');
        Route::get('/roles', [AdminRolesController::class, 'index'])
            ->middleware('can:admin.roles.view')
            ->name('roles.index');
        Route::get('/roles/create', [AdminRolesController::class, 'create'])
            ->middleware('can:admin.roles.manage')
            ->name('roles.create');
        Route::post('/roles', [AdminRolesController::class, 'store'])
            ->middleware('can:admin.roles.manage')
            ->name('roles.store');
        Route::get('/roles/{role}', [AdminRolesController::class, 'edit'])
            ->middleware('can:admin.roles.manage')
            ->name('roles.edit');
        Route::put('/roles/{role}', [AdminRolesController::class, 'update'])
            ->middleware('can:admin.roles.manage')
            ->name('roles.update');
        Route::get('/applications', [AdminApplicationsController::class, 'index'])
            ->middleware('can:admin.applications.view')
            ->name('applications.index');
        Route::get('/applications/track', [AdminApplicationsTrackController::class, 'index'])
            ->middleware('can:admin.applications.view')
            ->name('applications.track.index');
        Route::get('/applications/track/suggest', [AdminApplicationsTrackController::class, 'suggest'])
            ->middleware('can:admin.applications.view')
            ->name('applications.track.suggest');

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/sla', [AdminSlaReportController::class, 'index'])
                ->middleware('can:reports.sla.view')
                ->name('sla');
        });
        Route::get('/certificates', [AdminCertificatesController::class, 'index'])
            ->middleware('can:admin.certificates.view')
            ->name('certificates.index');

        Route::prefix('verification')->name('verification.')->group(function () {
            Route::get('/pool', [AdminVerificationPoolController::class, 'index'])
                ->middleware('can:verification.pool.view')
                ->name('pool.index');
            Route::get('/pool/country', [AdminVerificationCategoryController::class, 'byCountry'])
                ->middleware('can:verification.pool.view')
                ->name('pool.country');
            Route::get('/pool/awarding-body', [AdminVerificationCategoryController::class, 'byAwardingBody'])
                ->middleware('can:verification.pool.view')
                ->name('pool.awarding_body');
            Route::get('/pool/awarding-institution', [AdminVerificationCategoryController::class, 'byAwardingInstitution'])
                ->middleware('can:verification.pool.view')
                ->name('pool.awarding_institution');
            Route::get('/assigned-to-me', [AdminVerificationAssignedToMeController::class, 'index'])
                ->middleware('can:verification.level1.process')
                ->name('assigned_to_me');

            Route::get('/applications/{application}', [AdminVerificationApplicationController::class, 'show'])
                ->middleware('can:verification.pool.view')
                ->name('applications.show');
            Route::post('/applications/{application}/assign', [AdminVerificationApplicationController::class, 'assign'])
                ->middleware('can:verification.assign')
                ->name('applications.assign');
            Route::post('/applications/{application}/send-back', [AdminVerificationApplicationController::class, 'sendBack'])
                ->middleware('can:verification.send_back')
                ->name('applications.send_back');
            Route::post('/applications/{application}/level1-complete', [AdminVerificationApplicationController::class, 'level1Complete'])
                ->middleware('can:verification.level1.process')
                ->name('applications.level1_complete');
            Route::post('/applications/{application}/level2-return-to-level1', [AdminVerificationApplicationController::class, 'level2ReturnToLevel1'])
                ->middleware('can:verification.level2.review')
                ->name('applications.level2_return_to_level1');
            Route::post('/applications/{application}/approve', [AdminVerificationApplicationController::class, 'approve'])
                ->middleware('can:verification.decide.approve')
                ->name('applications.approve');
            Route::post('/applications/{application}/reject', [AdminVerificationApplicationController::class, 'reject'])
                ->middleware('can:verification.decide.reject')
                ->name('applications.reject');
            Route::post('/applications/{application}/issue-certificate', [AdminVerificationApplicationController::class, 'issueCertificate'])
                ->middleware('can:verification.certificate.issue')
                ->name('applications.issue_certificate');
            Route::post('/applications/{application}/comments', [AdminVerificationApplicationController::class, 'storeComment'])
                ->middleware('can:verification.pool.view')
                ->name('applications.comments.store');

            Route::get('/documents/{document}/preview', [AdminVerificationDocumentController::class, 'preview'])
                ->middleware('can:verification.pool.view')
                ->name('documents.preview');
            Route::get('/documents/{document}/download', [AdminVerificationDocumentController::class, 'download'])
                ->middleware('can:verification.pool.view')
                ->name('documents.download');

            Route::get('/qualifications/{qualification}', [AdminVerificationQualificationController::class, 'show'])
                ->middleware('can:verification.pool.view')
                ->name('qualifications.show');
            Route::post('/qualifications/{qualification}/assign', [AdminVerificationQualificationController::class, 'assign'])
                ->middleware('can:verification.assign')
                ->name('qualifications.assign');
        });

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/countries', [AdminCountriesController::class, 'index'])
                ->middleware('can:settings.countries.view')
                ->name('countries.index');
            Route::get('/countries/create', [AdminCountriesController::class, 'create'])
                ->middleware('can:settings.countries.create')
                ->name('countries.create');
            Route::post('/countries', [AdminCountriesController::class, 'store'])
                ->middleware('can:settings.countries.create')
                ->name('countries.store');
            Route::get('/countries/{country}/edit', [AdminCountriesController::class, 'edit'])
                ->middleware('can:settings.countries.edit')
                ->name('countries.edit');
            Route::put('/countries/{country}', [AdminCountriesController::class, 'update'])
                ->middleware('can:settings.countries.edit')
                ->name('countries.update');
            Route::delete('/countries/{country}', [AdminCountriesController::class, 'destroy'])
                ->middleware('can:settings.countries.delete')
                ->name('countries.destroy');

            Route::get('/certificate-subjects', [AdminCertificateSubjectsController::class, 'index'])
                ->middleware('can:settings.certificate_subjects.view')
                ->name('certificate_subjects.index');
            Route::get('/certificate-subjects/create', [AdminCertificateSubjectsController::class, 'create'])
                ->middleware('can:settings.certificate_subjects.create')
                ->name('certificate_subjects.create');
            Route::post('/certificate-subjects', [AdminCertificateSubjectsController::class, 'store'])
                ->middleware('can:settings.certificate_subjects.create')
                ->name('certificate_subjects.store');
            Route::get('/certificate-subjects/{certificate_subject}/edit', [AdminCertificateSubjectsController::class, 'edit'])
                ->middleware('can:settings.certificate_subjects.edit')
                ->name('certificate_subjects.edit');
            Route::put('/certificate-subjects/{certificate_subject}', [AdminCertificateSubjectsController::class, 'update'])
                ->middleware('can:settings.certificate_subjects.edit')
                ->name('certificate_subjects.update');
            Route::delete('/certificate-subjects/{certificate_subject}', [AdminCertificateSubjectsController::class, 'destroy'])
                ->middleware('can:settings.certificate_subjects.delete')
                ->name('certificate_subjects.destroy');

            Route::get('/awarding-institutions', [AdminAwardingInstitutionsController::class, 'index'])
                ->middleware('can:settings.awarding_institutions.view')
                ->name('awarding_institutions.index');
            Route::get('/awarding-institutions/create', [AdminAwardingInstitutionsController::class, 'create'])
                ->middleware('can:settings.awarding_institutions.create')
                ->name('awarding_institutions.create');
            Route::post('/awarding-institutions', [AdminAwardingInstitutionsController::class, 'store'])
                ->middleware('can:settings.awarding_institutions.create')
                ->name('awarding_institutions.store');
            Route::get('/awarding-institutions/{awardingInstitution}/edit', [AdminAwardingInstitutionsController::class, 'edit'])
                ->middleware('can:settings.awarding_institutions.edit')
                ->name('awarding_institutions.edit');
            Route::put('/awarding-institutions/{awardingInstitution}', [AdminAwardingInstitutionsController::class, 'update'])
                ->middleware('can:settings.awarding_institutions.edit')
                ->name('awarding_institutions.update');
            Route::delete('/awarding-institutions/{awardingInstitution}', [AdminAwardingInstitutionsController::class, 'destroy'])
                ->middleware('can:settings.awarding_institutions.delete')
                ->name('awarding_institutions.destroy');

            Route::get('/qualification-types', [AdminQualificationTypesController::class, 'index'])
                ->middleware('can:settings.qualification_types.view')
                ->name('qualification_types.index');
            Route::get('/qualification-types/create', [AdminQualificationTypesController::class, 'create'])
                ->middleware('can:settings.qualification_types.create')
                ->name('qualification_types.create');
            Route::post('/qualification-types', [AdminQualificationTypesController::class, 'store'])
                ->middleware('can:settings.qualification_types.create')
                ->name('qualification_types.store');
            Route::get('/qualification-types/{qualificationType}/edit', [AdminQualificationTypesController::class, 'edit'])
                ->middleware('can:settings.qualification_types.edit')
                ->name('qualification_types.edit');
            Route::put('/qualification-types/{qualificationType}', [AdminQualificationTypesController::class, 'update'])
                ->middleware('can:settings.qualification_types.edit')
                ->name('qualification_types.update');
            Route::delete('/qualification-types/{qualificationType}', [AdminQualificationTypesController::class, 'destroy'])
                ->middleware('can:settings.qualification_types.delete')
                ->name('qualification_types.destroy');

            Route::get('/fees', [AdminFeesController::class, 'index'])
                ->middleware('can:settings.fees.view')
                ->name('fees.index');
            Route::get('/fees/create', [AdminFeesController::class, 'create'])
                ->middleware('can:settings.fees.create')
                ->name('fees.create');
            Route::post('/fees', [AdminFeesController::class, 'store'])
                ->middleware('can:settings.fees.create')
                ->name('fees.store');
            Route::get('/fees/{feeStructure}/edit', [AdminFeesController::class, 'edit'])
                ->middleware('can:settings.fees.edit')
                ->name('fees.edit');
            Route::put('/fees/{feeStructure}', [AdminFeesController::class, 'update'])
                ->middleware('can:settings.fees.edit')
                ->name('fees.update');
            Route::delete('/fees/{feeStructure}', [AdminFeesController::class, 'destroy'])
                ->middleware('can:settings.fees.delete')
                ->name('fees.destroy');

            Route::get('/departments', [AdminDepartmentsController::class, 'index'])
                ->middleware('can:settings.departments.view')
                ->name('departments.index');
            Route::get('/departments/create', [AdminDepartmentsController::class, 'create'])
                ->middleware('can:settings.departments.create')
                ->name('departments.create');
            Route::post('/departments', [AdminDepartmentsController::class, 'store'])
                ->middleware('can:settings.departments.create')
                ->name('departments.store');
            Route::get('/departments/{department}/edit', [AdminDepartmentsController::class, 'edit'])
                ->middleware('can:settings.departments.edit')
                ->name('departments.edit');
            Route::put('/departments/{department}', [AdminDepartmentsController::class, 'update'])
                ->middleware('can:settings.departments.edit')
                ->name('departments.update');
            Route::delete('/departments/{department}', [AdminDepartmentsController::class, 'destroy'])
                ->middleware('can:settings.departments.delete')
                ->name('departments.destroy');
        });
    });
});

Route::middleware('auth')->get('/payments/test/redirect/{payment}', [ApplicantPaymentController::class, 'testRedirect'])
    ->name('payments.test.redirect');
