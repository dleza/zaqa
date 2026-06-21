<?php

namespace App\Http\Controllers\Admin\Verification\Concerns;

use App\Support\Search\ReferenceSearch;
use Illuminate\Http\Request;

trait ProvidesVerificationReferenceFilters
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function referenceSearchFilters(Request $request, array $extra = []): array
    {
        return array_merge(ReferenceSearch::filterPayloadFromRequest($request), $extra);
    }
}
