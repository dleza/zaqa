<?php

use App\Models\BillingCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->timestamp('service_started_at')->nullable()->after('assigned_at')->index();
            $table->timestamp('service_deadline_at')->nullable()->after('service_started_at')->index();
        });

        $foreignProcessingDays = DB::table('billing_categories')
            ->where('code', BillingCategory::CODE_FOREIGN_QUALIFICATIONS)
            ->where('is_active', true)
            ->value('foreign_processing_days');

        $rows = DB::table('qualifications as q')
            ->join('applications as a', 'a.id', '=', 'q.application_id')
            ->leftJoin('qualification_types as qt', 'qt.id', '=', 'q.qualification_type_id')
            ->leftJoin('billing_categories as bc', 'bc.id', '=', 'qt.billing_category_id')
            ->orderBy('q.id')
            ->get([
                'q.id',
                'q.is_foreign_qualification',
                'a.submitted_at',
                DB::raw('a.service_deadline_at as application_service_deadline_at'),
                'bc.local_processing_days',
                'bc.foreign_processing_days',
            ]);

        foreach ($rows as $row) {
            $startedAt = $row->submitted_at ? Carbon::parse((string) $row->submitted_at) : null;
            $deadlineAt = null;

            if ($startedAt) {
                $days = $row->is_foreign_qualification
                    ? ($foreignProcessingDays ?? $row->foreign_processing_days)
                    : $row->local_processing_days;

                if ($days !== null) {
                    $deadlineAt = $startedAt->copy()->addDays((int) $days);
                } elseif ($row->application_service_deadline_at) {
                    $deadlineAt = Carbon::parse((string) $row->application_service_deadline_at);
                } else {
                    $deadlineAt = $startedAt->copy()->addDays($row->is_foreign_qualification ? 60 : 14);
                }
            } elseif ($row->application_service_deadline_at) {
                $deadlineAt = Carbon::parse((string) $row->application_service_deadline_at);
            }

            DB::table('qualifications')
                ->where('id', $row->id)
                ->update([
                    'service_started_at' => $startedAt,
                    'service_deadline_at' => $deadlineAt,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('qualifications', function (Blueprint $table) {
            $table->dropColumn(['service_started_at', 'service_deadline_at']);
        });
    }
};
