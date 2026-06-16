<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\LearnerRecordSubmission;
use App\Models\QualificationDocument;
use App\Models\User;
use App\Policies\ApplicationPolicy;
use App\Policies\LearnerRecordSubmissionPolicy;
use App\Policies\QualificationDocumentPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Application::class => ApplicationPolicy::class,
        QualificationDocument::class => QualificationDocumentPolicy::class,
        User::class => UserPolicy::class,
        LearnerRecordSubmission::class => LearnerRecordSubmissionPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
