<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Card = 'card';
    case MobileMoney = 'mobile_money';
    case BankDeposit = 'bank_deposit';
    case BankTransfer = 'bank_transfer';
}

