<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

use App\Shared\Reports\ReportSubjectType;

/**
 * Stable Directory record vocabulary shared across feature boundaries.
 *
 * The value doubles as the registered morph alias so cross-feature read paths
 * can resolve an owning model without importing a Directory-private class.
 */
enum DirectoryRecordType: string
{
    case Institution = 'institution';
    case Person = 'person';
    case Reference = 'reference';
    case Venue = 'venue';
    case Space = 'space';
    case DonationChannel = 'donation_channel';

    public function morphAlias(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Institution => __('Institution'),
            self::Person => __('Speaker'),
            self::Reference => __('Reference'),
            self::Venue => __('Venue'),
            self::Space => __('Space'),
            self::DonationChannel => __('Donation Channel'),
        };
    }

    public function reportSubjectType(): ?ReportSubjectType
    {
        return match ($this) {
            self::Institution => ReportSubjectType::Institution,
            self::Person => ReportSubjectType::Person,
            self::Reference => ReportSubjectType::Reference,
            self::DonationChannel => ReportSubjectType::DonationChannel,
            self::Venue, self::Space => null,
        };
    }

    public static function fromReportSubjectType(ReportSubjectType $type): ?self
    {
        return match ($type) {
            ReportSubjectType::Institution => self::Institution,
            ReportSubjectType::Person => self::Person,
            ReportSubjectType::Reference => self::Reference,
            ReportSubjectType::DonationChannel => self::DonationChannel,
            ReportSubjectType::Event => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }
}
