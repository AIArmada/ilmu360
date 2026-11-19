<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

use AIArmada\Membership\Enums\MemberRole;
use App\Features\Institutions\Api\Enums\InstitutionType;

final readonly class MembershipApplicationFormRules
{
    public const string OTHER_RELATIONSHIP = 'other';

    public const string FAMILY_RELATIONSHIP = 'family_member';

    private function __construct() {}

    public static function acceptsRelationshipDetail(string $relationship): bool
    {
        return in_array($relationship, [self::OTHER_RELATIONSHIP, self::FAMILY_RELATIONSHIP], true);
    }

    /**
     * @return array<string, string>
     */
    public static function applicationRoleOptions(): array
    {
        return [
            MemberRole::Owner->value => __('Owner'),
            MemberRole::Admin->value => __('Administrator'),
            MemberRole::Editor->value => __('Editor'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function relationshipOptions(
        ?MemberSubjectType $subjectType = null,
        ?InstitutionType $institutionType = null,
    ): array {
        if ($subjectType !== MemberSubjectType::Institution) {
            return [
                'self' => __('Yourself'),
                self::FAMILY_RELATIONSHIP => __('Family member'),
                'student' => __('Anak murid'),
                'personal_assistant' => __('Personal assistant'),
                'team_member' => __('Team member'),
                self::OTHER_RELATIONSHIP => __('Other'),
            ];
        }

        return match (self::institutionRelationshipTemplate($institutionType)) {
            'mosque' => [
                'imam' => __('Imam'),
                'bilal' => __('Bilal'),
                'chairman' => __('Chairman'),
                'deputy_chairman' => __('Deputy chairman'),
                'secretary' => __('Secretary'),
                'assistant_secretary' => __('Assistant secretary'),
                'committee_member' => __('Committee member'),
                'officer' => __('Officer'),
                'kariah' => __('Kariah'),
                self::OTHER_RELATIONSHIP => __('Other'),
            ],
            'school' => [
                'principal' => __('Principal'),
                'teacher' => __('Teacher'),
                'employee' => __('Employee'),
                self::OTHER_RELATIONSHIP => __('Other'),
            ],
            default => [
                'chairman' => __('Chairman'),
                'committee_member' => __('Committee member'),
                'employee' => __('Employee'),
                self::OTHER_RELATIONSHIP => __('Other'),
            ],
        };
    }

    public static function relationshipLabel(string $relationship, ?string $detail = null): string
    {
        $labels = [
            ...self::relationshipOptions(),
            ...self::relationshipOptions(MemberSubjectType::Institution, InstitutionType::Masjid),
            ...self::relationshipOptions(MemberSubjectType::Institution, InstitutionType::Sekolah),
            ...self::relationshipOptions(MemberSubjectType::Institution),
        ];
        $label = $labels[$relationship] ?? $relationship;

        if (self::acceptsRelationshipDetail($relationship) && is_string($detail) && $detail !== '') {
            return sprintf('%s (%s)', $label, $detail);
        }

        return $label;
    }

    private static function institutionRelationshipTemplate(?InstitutionType $institutionType): string
    {
        return match ($institutionType) {
            InstitutionType::Masjid, InstitutionType::Surau => 'mosque',
            InstitutionType::Sekolah,
            InstitutionType::Kolej,
            InstitutionType::Universiti,
            InstitutionType::Madrasah,
            InstitutionType::Maahad,
            InstitutionType::Pondok => 'school',
            default => 'generic',
        };
    }
}
