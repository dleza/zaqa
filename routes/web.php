<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'))->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/register', [\App\Http\Controllers\Auth\RegisteredApplicantController::class, 'create'])->name('register');
    Route::post('/register/individual', [\App\Http\Controllers\Auth\RegisteredApplicantController::class, 'storeIndividual'])->name('register.individual');
    Route::post('/register/institution', [\App\Http\Controllers\Auth\RegisteredApplicantController::class, 'storeInstitution'])->name('register.institution');

    Route::get('/login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::get('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [\App\Http\Controllers\Auth\PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('/reset-password/{token}', [\App\Http\Controllers\Auth\NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [\App\Http\Controllers\Auth\NewPasswordController::class, 'store'])->name('password.update');
});

Route::get('/activate/email', [\App\Http\Controllers\Auth\AccountActivationController::class, 'verifyEmail'])->name('activation.email.verify');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\Auth\AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/activate', [\App\Http\Controllers\Auth\AccountActivationController::class, 'show'])->name('activation.show');
    Route::post('/activate/phone-otp', [\App\Http\Controllers\Auth\AccountActivationController::class, 'verifyPhoneOtp'])->name('activation.phone.verify');
    Route::post('/activate/resend-email', [\App\Http\Controllers\Auth\AccountActivationController::class, 'resendEmail'])->name('activation.resend.email');
    Route::post('/activate/resend-otp', [\App\Http\Controllers\Auth\AccountActivationController::class, 'resendOtp'])->name('activation.resend.otp');

    Route::middleware(\App\Http\Middleware\EnsureAccountIsActive::class)->prefix('applicant')->name('applicant.')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Applicant\DashboardController::class, 'index'])->name('dashboard');

        Route::get('/applications', [\App\Http\Controllers\Applicant\ApplicantApplicationController::class, 'index'])->name('applications.index');
        Route::get('/applications/new', [\App\Http\Controllers\Applicant\ApplicantApplicationController::class, 'create'])->name('applications.create');
        Route::post('/applications', [\App\Http\Controllers\Applicant\ApplicantApplicationController::class, 'store'])->name('applications.store');
        Route::get('/applications/{application}', [\App\Http\Controllers\Applicant\ApplicantApplicationController::class, 'show'])->name('applications.show');
        Route::get('/applications/{application}/track', [\App\Http\Controllers\Applicant\ApplicantApplicationTrackingController::class, 'show'])->name('applications.track');
        Route::get('/applications/{application}/track-summary', [\App\Http\Controllers\Applicant\ApplicantApplicationTrackingController::class, 'summary'])->name('applications.track.summary');
        Route::get('/applications/{application}/edit', [\App\Http\Controllers\Applicant\ApplicantApplicationController::class, 'edit'])->name('applications.edit');
        Route::patch('/applications/{application}', [\App\Http\Controllers\Applicant\ApplicantApplicationController::class, 'update'])->name('applications.update');
        Route::delete('/applications/{application}', [\App\Http\Controllers\Applicant\ApplicantApplicationController::class, 'destroy'])->name('applications.destroy');
        Route::post('/applications/{application}/submit', [\App\Http\Controllers\Applicant\ApplicantApplicationController::class, 'submit'])->name('applications.submit');
        Route::get('/applications/{application}/feedback', [\App\Http\Controllers\Applicant\ApplicantServiceFeedbackController::class, 'show'])->name('applications.feedback.show');
        Route::post('/applications/{application}/feedback', [\App\Http\Controllers\Applicant\ApplicantServiceFeedbackController::class, 'store'])->name('applications.feedback.store');
        Route::post('/applications/{application}/feedback/skip', [\App\Http\Controllers\Applicant\ApplicantServiceFeedbackController::class, 'skip'])->name('applications.feedback.skip');

        Route::get('/reference/awarding-institutions', [\App\Http\Controllers\Applicant\ApplicantReferenceController::class, 'awardingInstitutions'])->name('reference.awarding_institutions');

        Route::put('/applications/{application}/applicant-details', [\App\Http\Controllers\Applicant\ApplicantDetailsController::class, 'update'])->name('applications.applicant_details.update');

        Route::put('/applications/{application}/qualification', [\App\Http\Controllers\Applicant\ApplicantQualificationController::class, 'upsert'])->name('applications.qualification.upsert');
        Route::put('/applications/{application}/qualification/details', [\App\Http\Controllers\Applicant\ApplicantQualificationController::class, 'upsertDetails'])->name('applications.qualification.details.upsert');
        Route::put('/applications/{application}/qualification/subject-results', [\App\Http\Controllers\Applicant\ApplicantQualificationController::class, 'upsertSubjectResults'])->name('applications.qualification.subject_results.upsert');

        Route::post('/applications/{application}/documents', [\App\Http\Controllers\Applicant\ApplicantDocumentController::class, 'store'])->name('applications.documents.store');
        Route::get('/documents/{document}/preview', [\App\Http\Controllers\Applicant\ApplicantDocumentController::class, 'preview'])->name('documents.preview')->middleware('signed');
        Route::get('/documents/{document}/download', [\App\Http\Controllers\Applicant\ApplicantDocumentController::class, 'download'])->name('documents.download')->middleware('signed');
        Route::delete('/documents/{document}', [\App\Http\Controllers\Applicant\ApplicantDocumentController::class, 'destroy'])->name('documents.destroy');

        Route::post('/applications/{application}/consent/accept', [\App\Http\Controllers\Applicant\ApplicantConsentController::class, 'acceptLocal'])->name('applications.consent.accept');
        Route::post('/applications/{application}/consent/foreign-upload', [\App\Http\Controllers\Applicant\ApplicantConsentController::class, 'uploadForeign'])->name('applications.consent.foreign_upload');

        Route::post('/applications/{application}/payment/prepare', [\App\Http\Controllers\Applicant\ApplicantPaymentController::class, 'prepare'])->name('applications.payment.prepare');
        Route::post('/applications/{application}/payment/select', [\App\Http\Controllers\Applicant\ApplicantPaymentController::class, 'selectMethod'])->name('applications.payment.select');
        Route::post('/payments/{payment}/initiate-card', [\App\Http\Controllers\Applicant\ApplicantPaymentController::class, 'initiateCard'])->name('payments.initiate_card');
        Route::post('/payments/{payment}/initiate-mobile-money', [\App\Http\Controllers\Applicant\ApplicantPaymentController::class, 'initiateMobileMoney'])->name('payments.initiate_mobile_money');
        Route::post('/payments/{payment}/upload-proof', [\App\Http\Controllers\Applicant\ApplicantPaymentController::class, 'uploadProof'])->name('payments.upload_proof');
        Route::get('/payments/{payment}/return', [\App\Http\Controllers\Applicant\ApplicantPaymentController::class, 'returnFromProvider'])->name('payments.return');

        Route::get('/invoices', [\App\Http\Controllers\Applicant\ApplicantBillingController::class, 'invoices'])->name('invoices');
        Route::get('/statement', [\App\Http\Controllers\Applicant\ApplicantBillingController::class, 'statement'])->name('statement');

        Route::get('/profile', [\App\Http\Controllers\Applicant\ApplicantProfileController::class, 'show'])->name('profile.show');
        Route::get('/profile/edit', [\App\Http\Controllers\Applicant\ApplicantProfileEditController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [\App\Http\Controllers\Applicant\ApplicantProfileEditController::class, 'update'])->name('profile.update');
        Route::get('/change-password', [\App\Http\Controllers\Applicant\ApplicantProfileController::class, 'editPassword'])->name('profile.password.edit');
        Route::post('/change-password', [\App\Http\Controllers\Applicant\ApplicantProfileController::class, 'updatePassword'])->name('profile.password.update');
    });

    Route::prefix('finance')->name('finance.')->middleware(['auth', 'can:admin.finance.view'])->group(function () {
        Route::get('/payment-proofs', [\App\Http\Controllers\Finance\FinancePaymentProofController::class, 'index'])->name('payment_proofs.index');
        Route::post('/payments/{payment}/approve', [\App\Http\Controllers\Finance\FinancePaymentProofController::class, 'approve'])->name('payments.approve');
        Route::post('/payments/{payment}/reject', [\App\Http\Controllers\Finance\FinancePaymentProofController::class, 'reject'])->name('payments.reject');
        Route::get('/applications/{application}/track', [\App\Http\Controllers\Finance\FinanceApplicationTrackingController::class, 'show'])->name('applications.track');
    });

    Route::prefix('admin')->name('admin.')->middleware(['auth', 'can:dashboard.view'])->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/users', [\App\Http\Controllers\Admin\AdminUsersController::class, 'index'])->middleware('can:admin.users.view')->name('users.index');
        Route::get('/users/create', [\App\Http\Controllers\Admin\AdminUsersController::class, 'create'])
            ->middleware('can:admin.users.create')
            ->name('users.create');
        Route::post('/users', [\App\Http\Controllers\Admin\AdminUsersController::class, 'store'])
            ->middleware('can:admin.users.create')
            ->name('users.store');
        Route::get('/users/{user}', [\App\Http\Controllers\Admin\AdminUsersController::class, 'show'])
            ->middleware('can:admin.users.view')
            ->name('users.show');
        Route::post('/users/{user}/block', [\App\Http\Controllers\Admin\AdminUsersController::class, 'block'])
            ->middleware('can:admin.users.disable')
            ->name('users.block');
        Route::post('/users/{user}/unblock', [\App\Http\Controllers\Admin\AdminUsersController::class, 'unblock'])
            ->middleware('can:admin.users.disable')
            ->name('users.unblock');
        Route::get('/applicants', [\App\Http\Controllers\Admin\AdminApplicantsController::class, 'index'])
            ->middleware('can:admin.applicants.view')
            ->name('applicants.index');
        Route::get('/applicants/{user}', [\App\Http\Controllers\Admin\AdminApplicantsController::class, 'show'])
            ->middleware('can:admin.applicants.view')
            ->name('applicants.show');
        Route::get('/roles', [\App\Http\Controllers\Admin\AdminRolesController::class, 'index'])
            ->middleware('can:admin.roles.view')
            ->name('roles.index');
        Route::get('/roles/create', [\App\Http\Controllers\Admin\AdminRolesController::class, 'create'])
            ->middleware('can:admin.roles.manage')
            ->name('roles.create');
        Route::post('/roles', [\App\Http\Controllers\Admin\AdminRolesController::class, 'store'])
            ->middleware('can:admin.roles.manage')
            ->name('roles.store');
        Route::get('/roles/{role}', [\App\Http\Controllers\Admin\AdminRolesController::class, 'edit'])
            ->middleware('can:admin.roles.manage')
            ->name('roles.edit');
        Route::put('/roles/{role}', [\App\Http\Controllers\Admin\AdminRolesController::class, 'update'])
            ->middleware('can:admin.roles.manage')
            ->name('roles.update');
        Route::get('/applications', [\App\Http\Controllers\Admin\AdminApplicationsController::class, 'index'])
            ->middleware('can:admin.applications.view')
            ->name('applications.index');
        Route::get('/applications/track', [\App\Http\Controllers\Admin\AdminApplicationsTrackController::class, 'index'])
            ->middleware('can:admin.applications.view')
            ->name('applications.track.index');
        Route::get('/applications/track/suggest', [\App\Http\Controllers\Admin\AdminApplicationsTrackController::class, 'suggest'])
            ->middleware('can:admin.applications.view')
            ->name('applications.track.suggest');

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/sla', [\App\Http\Controllers\Admin\AdminSlaReportController::class, 'index'])
                ->middleware('can:reports.sla.view')
                ->name('sla');
        });
        Route::get('/certificates', [\App\Http\Controllers\Admin\AdminCertificatesController::class, 'index'])
            ->middleware('can:admin.certificates.view')
            ->name('certificates.index');

        Route::prefix('verification')->name('verification.')->group(function () {
            Route::get('/pool', [\App\Http\Controllers\Admin\Verification\AdminVerificationPoolController::class, 'index'])
                ->middleware('can:verification.pool.view')
                ->name('pool.index');
            Route::get('/pool/country', [\App\Http\Controllers\Admin\Verification\AdminVerificationCategoryController::class, 'byCountry'])
                ->middleware('can:verification.pool.view')
                ->name('pool.country');
            Route::get('/pool/awarding-body', [\App\Http\Controllers\Admin\Verification\AdminVerificationCategoryController::class, 'byAwardingBody'])
                ->middleware('can:verification.pool.view')
                ->name('pool.awarding_body');
            Route::get('/pool/awarding-institution', [\App\Http\Controllers\Admin\Verification\AdminVerificationCategoryController::class, 'byAwardingInstitution'])
                ->middleware('can:verification.pool.view')
                ->name('pool.awarding_institution');
            Route::get('/assigned-to-me', [\App\Http\Controllers\Admin\Verification\AdminVerificationAssignedToMeController::class, 'index'])
                ->middleware('can:verification.level1.process')
                ->name('assigned_to_me');

            Route::get('/applications/{application}', [\App\Http\Controllers\Admin\Verification\AdminVerificationApplicationController::class, 'show'])
                ->middleware('can:verification.pool.view')
                ->name('applications.show');
            Route::post('/applications/{application}/assign', [\App\Http\Controllers\Admin\Verification\AdminVerificationApplicationController::class, 'assign'])
                ->middleware('can:verification.assign')
                ->name('applications.assign');
            Route::post('/applications/{application}/send-back', [\App\Http\Controllers\Admin\Verification\AdminVerificationApplicationController::class, 'sendBack'])
                ->middleware('can:verification.send_back')
                ->name('applications.send_back');
            Route::post('/applications/{application}/level1-complete', [\App\Http\Controllers\Admin\Verification\AdminVerificationApplicationController::class, 'level1Complete'])
                ->middleware('can:verification.level1.process')
                ->name('applications.level1_complete');
            Route::post('/applications/{application}/level2-return-to-level1', [\App\Http\Controllers\Admin\Verification\AdminVerificationApplicationController::class, 'level2ReturnToLevel1'])
                ->middleware('can:verification.level2.review')
                ->name('applications.level2_return_to_level1');
            Route::post('/applications/{application}/approve', [\App\Http\Controllers\Admin\Verification\AdminVerificationApplicationController::class, 'approve'])
                ->middleware('can:verification.decide.approve')
                ->name('applications.approve');
            Route::post('/applications/{application}/reject', [\App\Http\Controllers\Admin\Verification\AdminVerificationApplicationController::class, 'reject'])
                ->middleware('can:verification.decide.reject')
                ->name('applications.reject');
            Route::post('/applications/{application}/issue-certificate', [\App\Http\Controllers\Admin\Verification\AdminVerificationApplicationController::class, 'issueCertificate'])
                ->middleware('can:verification.certificate.issue')
                ->name('applications.issue_certificate');

            Route::get('/documents/{document}/preview', [\App\Http\Controllers\Admin\Verification\AdminVerificationDocumentController::class, 'preview'])
                ->middleware('can:verification.pool.view')
                ->name('documents.preview');
            Route::get('/documents/{document}/download', [\App\Http\Controllers\Admin\Verification\AdminVerificationDocumentController::class, 'download'])
                ->middleware('can:verification.pool.view')
                ->name('documents.download');
        });

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/countries', [\App\Http\Controllers\Admin\Settings\AdminCountriesController::class, 'index'])
                ->middleware('can:settings.countries.view')
                ->name('countries.index');
            Route::get('/countries/create', [\App\Http\Controllers\Admin\Settings\AdminCountriesController::class, 'create'])
                ->middleware('can:settings.countries.create')
                ->name('countries.create');
            Route::post('/countries', [\App\Http\Controllers\Admin\Settings\AdminCountriesController::class, 'store'])
                ->middleware('can:settings.countries.create')
                ->name('countries.store');
            Route::get('/countries/{country}/edit', [\App\Http\Controllers\Admin\Settings\AdminCountriesController::class, 'edit'])
                ->middleware('can:settings.countries.edit')
                ->name('countries.edit');
            Route::put('/countries/{country}', [\App\Http\Controllers\Admin\Settings\AdminCountriesController::class, 'update'])
                ->middleware('can:settings.countries.edit')
                ->name('countries.update');
            Route::delete('/countries/{country}', [\App\Http\Controllers\Admin\Settings\AdminCountriesController::class, 'destroy'])
                ->middleware('can:settings.countries.delete')
                ->name('countries.destroy');

            Route::get('/awarding-institutions', [\App\Http\Controllers\Admin\Settings\AdminAwardingInstitutionsController::class, 'index'])
                ->middleware('can:settings.awarding_institutions.view')
                ->name('awarding_institutions.index');
            Route::get('/awarding-institutions/create', [\App\Http\Controllers\Admin\Settings\AdminAwardingInstitutionsController::class, 'create'])
                ->middleware('can:settings.awarding_institutions.create')
                ->name('awarding_institutions.create');
            Route::post('/awarding-institutions', [\App\Http\Controllers\Admin\Settings\AdminAwardingInstitutionsController::class, 'store'])
                ->middleware('can:settings.awarding_institutions.create')
                ->name('awarding_institutions.store');
            Route::get('/awarding-institutions/{awardingInstitution}/edit', [\App\Http\Controllers\Admin\Settings\AdminAwardingInstitutionsController::class, 'edit'])
                ->middleware('can:settings.awarding_institutions.edit')
                ->name('awarding_institutions.edit');
            Route::put('/awarding-institutions/{awardingInstitution}', [\App\Http\Controllers\Admin\Settings\AdminAwardingInstitutionsController::class, 'update'])
                ->middleware('can:settings.awarding_institutions.edit')
                ->name('awarding_institutions.update');
            Route::delete('/awarding-institutions/{awardingInstitution}', [\App\Http\Controllers\Admin\Settings\AdminAwardingInstitutionsController::class, 'destroy'])
                ->middleware('can:settings.awarding_institutions.delete')
                ->name('awarding_institutions.destroy');

            Route::get('/qualification-types', [\App\Http\Controllers\Admin\Settings\AdminQualificationTypesController::class, 'index'])
                ->middleware('can:settings.qualification_types.view')
                ->name('qualification_types.index');
            Route::get('/qualification-types/create', [\App\Http\Controllers\Admin\Settings\AdminQualificationTypesController::class, 'create'])
                ->middleware('can:settings.qualification_types.create')
                ->name('qualification_types.create');
            Route::post('/qualification-types', [\App\Http\Controllers\Admin\Settings\AdminQualificationTypesController::class, 'store'])
                ->middleware('can:settings.qualification_types.create')
                ->name('qualification_types.store');
            Route::get('/qualification-types/{qualificationType}/edit', [\App\Http\Controllers\Admin\Settings\AdminQualificationTypesController::class, 'edit'])
                ->middleware('can:settings.qualification_types.edit')
                ->name('qualification_types.edit');
            Route::put('/qualification-types/{qualificationType}', [\App\Http\Controllers\Admin\Settings\AdminQualificationTypesController::class, 'update'])
                ->middleware('can:settings.qualification_types.edit')
                ->name('qualification_types.update');
            Route::delete('/qualification-types/{qualificationType}', [\App\Http\Controllers\Admin\Settings\AdminQualificationTypesController::class, 'destroy'])
                ->middleware('can:settings.qualification_types.delete')
                ->name('qualification_types.destroy');

            Route::get('/fees', [\App\Http\Controllers\Admin\Settings\AdminFeesController::class, 'index'])
                ->middleware('can:settings.fees.view')
                ->name('fees.index');
            Route::get('/fees/create', [\App\Http\Controllers\Admin\Settings\AdminFeesController::class, 'create'])
                ->middleware('can:settings.fees.create')
                ->name('fees.create');
            Route::post('/fees', [\App\Http\Controllers\Admin\Settings\AdminFeesController::class, 'store'])
                ->middleware('can:settings.fees.create')
                ->name('fees.store');
            Route::get('/fees/{feeStructure}/edit', [\App\Http\Controllers\Admin\Settings\AdminFeesController::class, 'edit'])
                ->middleware('can:settings.fees.edit')
                ->name('fees.edit');
            Route::put('/fees/{feeStructure}', [\App\Http\Controllers\Admin\Settings\AdminFeesController::class, 'update'])
                ->middleware('can:settings.fees.edit')
                ->name('fees.update');
            Route::delete('/fees/{feeStructure}', [\App\Http\Controllers\Admin\Settings\AdminFeesController::class, 'destroy'])
                ->middleware('can:settings.fees.delete')
                ->name('fees.destroy');

            Route::get('/departments', [\App\Http\Controllers\Admin\Settings\AdminDepartmentsController::class, 'index'])
                ->middleware('can:settings.departments.view')
                ->name('departments.index');
            Route::get('/departments/create', [\App\Http\Controllers\Admin\Settings\AdminDepartmentsController::class, 'create'])
                ->middleware('can:settings.departments.create')
                ->name('departments.create');
            Route::post('/departments', [\App\Http\Controllers\Admin\Settings\AdminDepartmentsController::class, 'store'])
                ->middleware('can:settings.departments.create')
                ->name('departments.store');
            Route::get('/departments/{department}/edit', [\App\Http\Controllers\Admin\Settings\AdminDepartmentsController::class, 'edit'])
                ->middleware('can:settings.departments.edit')
                ->name('departments.edit');
            Route::put('/departments/{department}', [\App\Http\Controllers\Admin\Settings\AdminDepartmentsController::class, 'update'])
                ->middleware('can:settings.departments.edit')
                ->name('departments.update');
            Route::delete('/departments/{department}', [\App\Http\Controllers\Admin\Settings\AdminDepartmentsController::class, 'destroy'])
                ->middleware('can:settings.departments.delete')
                ->name('departments.destroy');
        });
    });
});

Route::middleware('auth')->get('/payments/test/redirect/{payment}', [\App\Http\Controllers\Applicant\ApplicantPaymentController::class, 'testRedirect'])
    ->name('payments.test.redirect');
