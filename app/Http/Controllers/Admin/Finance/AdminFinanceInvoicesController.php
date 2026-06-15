<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Domain\Finance\InvoicePdfService;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminFinanceInvoicesController extends Controller
{
    public function download(Request $request, Invoice $invoice, InvoicePdfService $pdf): Response
    {
        if (! $request->user()?->can('finance.payments.view')) {
            abort(403);
        }

        return $pdf->downloadResponse($invoice);
    }
}
