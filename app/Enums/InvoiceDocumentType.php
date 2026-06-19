<?php

namespace App\Enums;

enum InvoiceDocumentType: string
{
    case Quotation = 'quotation';
    case Invoice = 'invoice';
}
