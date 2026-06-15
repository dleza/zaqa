<?php

namespace App\Console\Commands;

use App\Models\LearnerRecord;
use App\Models\Qualification;
use App\Models\QualificationTitle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillQualificationTitlesCommand extends Command
{
    protected $signature = 'qualification-titles:backfill {--dry-run : Report only, do not write}';

    protected $description = 'Backfill qualification_titles from existing qualifications and learner records';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $titlesCreated = 0;
        $titlesSkipped = 0;
        $linksCreated = 0;
        $qualificationsLinked = 0;
        $errors = [];

        $titleNames = collect();

        Qualification::query()
            ->whereNotNull('title_of_qualification')
            ->where('title_of_qualification', '!=', '')
            ->select('title_of_qualification')
            ->distinct()
            ->pluck('title_of_qualification')
            ->each(fn ($t) => $titleNames->push(trim((string) $t)));

        LearnerRecord::query()
            ->whereNotNull('program_of_study')
            ->where('program_of_study', '!=', '')
            ->select('program_of_study')
            ->distinct()
            ->pluck('program_of_study')
            ->each(fn ($t) => $titleNames->push(trim((string) $t)));

        $uniqueByNormalized = [];
        foreach ($titleNames->filter(fn ($t) => $t !== '') as $name) {
            $normalized = QualificationTitle::normalizeName($name);
            if ($normalized === '') {
                continue;
            }
            if (! isset($uniqueByNormalized[$normalized])) {
                $uniqueByNormalized[$normalized] = $name;
            }
        }

        DB::transaction(function () use (
            $dryRun,
            $uniqueByNormalized,
            &$titlesCreated,
            &$titlesSkipped,
            &$linksCreated,
            &$qualificationsLinked,
            &$errors,
        ) {
            foreach ($uniqueByNormalized as $normalized => $name) {
                $existing = QualificationTitle::query()->where('name_normalized', $normalized)->first();
                if ($existing) {
                    $titlesSkipped++;

                    continue;
                }

                if ($dryRun) {
                    $titlesCreated++;

                    continue;
                }

                try {
                    QualificationTitle::query()->create([
                        'name' => $name,
                        'is_active' => true,
                        'sort_order' => 0,
                    ]);
                    $titlesCreated++;
                } catch (\Throwable $e) {
                    $errors[] = "Title \"{$name}\": {$e->getMessage()}";
                }
            }

            if ($dryRun) {
                $pairCount = LearnerRecord::query()
                    ->whereNotNull('awarding_institution_id')
                    ->whereNotNull('program_of_study')
                    ->where('program_of_study', '!=', '')
                    ->select('awarding_institution_id', 'program_of_study')
                    ->distinct()
                    ->count();
                $linksCreated = $pairCount;

                $qualificationsLinked = Qualification::query()
                    ->whereNull('qualification_title_id')
                    ->whereNotNull('title_of_qualification')
                    ->count();

                return;
            }

            $pairs = LearnerRecord::query()
                ->whereNotNull('awarding_institution_id')
                ->whereNotNull('program_of_study')
                ->where('program_of_study', '!=', '')
                ->select('awarding_institution_id', 'program_of_study')
                ->distinct()
                ->get();

            foreach ($pairs as $pair) {
                $normalized = QualificationTitle::normalizeName((string) $pair->program_of_study);
                if ($normalized === '') {
                    continue;
                }
                $title = QualificationTitle::query()->where('name_normalized', $normalized)->first();
                if (! $title) {
                    continue;
                }
                $attached = $title->awardingInstitutions()->syncWithoutDetaching([(int) $pair->awarding_institution_id]);
                if ($attached['attached'] !== []) {
                    $linksCreated += count($attached['attached']);
                }
            }

            Qualification::query()
                ->whereNull('qualification_title_id')
                ->whereNotNull('title_of_qualification')
                ->where('title_of_qualification', '!=', '')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (&$qualificationsLinked, &$errors) {
                    foreach ($rows as $qualification) {
                        $normalized = QualificationTitle::normalizeName((string) $qualification->title_of_qualification);
                        if ($normalized === '') {
                            continue;
                        }
                        $matches = QualificationTitle::query()->where('name_normalized', $normalized)->get();
                        if ($matches->count() !== 1) {
                            continue;
                        }
                        try {
                            $qualification->forceFill(['qualification_title_id' => $matches->first()->id])->save();
                            $qualificationsLinked++;
                        } catch (\Throwable $e) {
                            $errors[] = "Qualification #{$qualification->id}: {$e->getMessage()}";
                        }
                    }
                });
        });

        $this->info($dryRun ? 'Dry run complete.' : 'Backfill complete.');
        $this->line("Titles created: {$titlesCreated}");
        $this->line("Titles skipped (already exist): {$titlesSkipped}");
        $this->line("Institution links created: {$linksCreated}");
        $this->line("Qualifications linked: {$qualificationsLinked}");

        if ($errors !== []) {
            $this->warn(count($errors).' error(s):');
            foreach (array_slice($errors, 0, 20) as $error) {
                $this->line(" - {$error}");
            }
        }

        return self::SUCCESS;
    }
}
