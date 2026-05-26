<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Response;

class InstitutionApiDocsController extends Controller
{
    public function ui(): HttpResponse
    {
        abort_unless((bool) config('institution_api.docs_enabled', false), 404);

        return response()->view('docs.institution-api');
    }

    public function spec(): HttpResponse
    {
        abort_unless((bool) config('institution_api.docs_enabled', false), 404);

        $path = resource_path('openapi/institution-api.yaml');
        abort_unless(File::exists($path), 404);

        return Response::make(File::get($path), 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
