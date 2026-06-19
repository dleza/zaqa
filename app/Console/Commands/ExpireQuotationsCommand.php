<?php

namespace App\Console\Commands;

use App\Domain\Finance\QuotationExpiryService;
use Illuminate\Console\Command;

class ExpireQuotationsCommand extends Command
{
    protected $signature = 'quotations:expire';

    protected $description = 'Expire unpaid quotations past their validity date and clean up related applications.';

    public function handle(QuotationExpiryService $service): int
    {
        $result = $service->expireDueQuotations();

        $this->info(sprintf(
            'Quotation expiry complete. Expired: %d, skipped: %d.',
            $result['expired'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
