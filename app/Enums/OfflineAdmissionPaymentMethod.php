<?php

declare(strict_types=1);

namespace App\Enums;

enum OfflineAdmissionPaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Complimentary = 'complimentary';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => __('Cash'),
            self::BankTransfer => __('Bank transfer'),
            self::Complimentary => __('Complimentary'),
        };
    }

    public function gateway(): string
    {
        return 'offline_'.$this->value;
    }
}
