<?php

namespace App\Domain\Applications;

use App\Models\ApplicationComment;
use App\Models\Qualification;
use Illuminate\Support\Collection;

final class ApplicantQualificationAmendmentComments
{
    /**
     * @return list<array{body: string, created_at: string|null, stage: string|null, author_label: string|null}>
     */
    public static function historyForQualification(Qualification $qualification): array
    {
        return self::queryForQualification((int) $qualification->id)
            ->get()
            ->map(fn (ApplicationComment $comment) => self::mapComment($comment))
            ->values()
            ->all();
    }

    /**
     * @return array{body: string, created_at: string|null, stage: string|null, author_label: string|null}|null
     */
    public static function latestForQualification(Qualification $qualification): ?array
    {
        $comment = self::queryForQualification((int) $qualification->id)
            ->first();

        return $comment ? self::mapComment($comment) : null;
    }

    /**
     * @param  Collection<int, Qualification>  $qualifications
     * @return array<int, list<array{body: string, created_at: string|null, stage: string|null, author_label: string|null}>>
     */
    public static function historyGroupedByQualification(Collection $qualifications): array
    {
        if ($qualifications->isEmpty()) {
            return [];
        }

        $comments = ApplicationComment::query()
            ->whereIn('qualification_id', $qualifications->pluck('id'))
            ->where('type', 'send_back')
            ->where('visibility', 'applicant_visible')
            ->with('author')
            ->orderByDesc('id')
            ->get()
            ->groupBy('qualification_id');

        $grouped = [];
        foreach ($qualifications as $qualification) {
            $qid = (int) $qualification->id;
            $grouped[$qid] = ($comments->get($qid) ?? collect())
                ->map(fn (ApplicationComment $comment) => self::mapComment($comment))
                ->values()
                ->all();
        }

        return $grouped;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<ApplicationComment>
     */
    private static function queryForQualification(int $qualificationId)
    {
        return ApplicationComment::query()
            ->where('qualification_id', $qualificationId)
            ->where('type', 'send_back')
            ->where('visibility', 'applicant_visible')
            ->with('author')
            ->orderByDesc('id');
    }

    /**
     * @return array{body: string, created_at: string|null, stage: string|null, author_label: string|null}
     */
    private static function mapComment(ApplicationComment $comment): array
    {
        $stage = self::stageLabelForComment($comment);

        return [
            'body' => (string) $comment->body,
            'created_at' => optional($comment->created_at)?->toIso8601String(),
            'stage' => $stage,
            'author_label' => $stage,
        ];
    }

    private static function stageLabelForComment(ApplicationComment $comment): ?string
    {
        $author = $comment->author;
        if (! $author) {
            return 'ZAQA reviewer';
        }

        if ($author->can('verification.level2.review')) {
            return 'Level 2 review';
        }

        if ($author->can('verification.level1.process')) {
            return 'Level 1 review';
        }

        return 'ZAQA reviewer';
    }
}
