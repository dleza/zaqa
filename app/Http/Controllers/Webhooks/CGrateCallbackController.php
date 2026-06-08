<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Payments\PaymentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CGrateCallbackController extends Controller
{
    public function handle(Request $request, PaymentService $payments): Response
    {
        if (! (bool) config('payments.cgrate.callback_enabled')) {
            return response('Callbacks disabled', 404);
        }

        $expectedToken = (string) config('payments.cgrate.callback_token', '');
        if ($expectedToken === '') {
            return response('Callback token not configured', 503);
        }

        $token = (string) ($request->header('X-CGrate-Callback-Token') ?? $request->input('token', ''));
        if (! hash_equals($expectedToken, $token)) {
            return response('Unauthorized', 401);
        }

        $allowedIps = (array) config('payments.cgrate.callback_allowed_ips', []);
        if ($allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            return response('Forbidden', 403);
        }

        $reference = trim((string) (
            $request->input('payment_reference')
            ?? $request->input('paymentReference')
            ?? $request->input('ref')
            ?? ''
        ));

        if ($reference === '') {
            return response('Missing payment reference', 422);
        }

        $processed = $payments->processCGrateCallback($reference, $request->all());

        return $processed
            ? response('OK', 200)
            : response('Not found', 404);
    }
}
