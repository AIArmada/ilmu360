<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Api\DirectoryReportSubjectProvider;
use App\Features\Directory\Support\AdminResourceUrl;
use App\Platform\Models\MorphModelResolver;
use App\Platform\Models\SlugOrUuidResolver;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectPresentation;
use App\Shared\Reports\ReportSubjectType;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Interim Directory-only report subject provider.
 *
 * Reports composes it with the interim Events provider until Events exposes its
 * own subject API. Querying through morph aliases keeps the adapter free of
 * feature-private imports when the models move.
 *
 * @internal
 */
final readonly class DirectoryReportSubjectResolver implements DirectoryReportSubjectProvider
{
    public function __construct(
        private SlugOrUuidResolver $slugOrUuidResolver,
    ) {}

    public function validKeys(): array
    {
        return [
            ReportSubjectType::Institution->value,
            ReportSubjectType::Person->value,
            ReportSubjectType::Reference->value,
            ReportSubjectType::DonationChannel->value,
        ];
    }

    public function label(string $typeKey): string
    {
        return $this->labelFor($this->type($typeKey));
    }

    public function exists(string $typeKey, string $entityId): bool
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        if (! $type instanceof ReportSubjectType || $type === ReportSubjectType::Event || trim($entityId) === '') {
            return false;
        }

        return $this->modelClass($type)::query()->whereKey($entityId)->exists();
    }

    public function find(string $typeKey, string $entityId): ?ReportSubject
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        if (! $type instanceof ReportSubjectType || $type === ReportSubjectType::Event || trim($entityId) === '') {
            return null;
        }

        $model = $this->modelClass($type)::query()->find($entityId);

        return $model instanceof Model ? $this->subject($type, $model) : null;
    }

    public function resolve(string $typeKey, string $identifier): ?ReportSubject
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        if (! $type instanceof ReportSubjectType || $type === ReportSubjectType::Event || trim($identifier) === '') {
            return null;
        }

        $modelClass = $this->modelClass($type);
        $model = match ($type) {
            ReportSubjectType::Institution => $this->slugOrUuidResolver->first($modelClass::query(), 'institutions.slug', $identifier),
            ReportSubjectType::Person => $this->slugOrUuidResolver->first($modelClass::query(), 'persons.slug', $identifier),
            ReportSubjectType::Reference => $this->slugOrUuidResolver->first($modelClass::query(), 'references.slug', $identifier),
            ReportSubjectType::DonationChannel => $modelClass::query()->find($identifier),
        };

        return $model instanceof Model ? $this->subject($type, $model) : null;
    }

    public function presentation(ReportSubject $subject): ReportSubjectPresentation
    {
        $redirectUrl = $subject->publicUrl;

        if ($redirectUrl === null) {
            throw new InvalidArgumentException("Report subject [{$subject->type->value}] has no public URL.");
        }

        return new ReportSubjectPresentation(
            subjectLabel: $subject->label,
            subjectTitle: $subject->title,
            redirectUrl: $redirectUrl,
            profileImageUrl: $subject->imageUrl,
        );
    }

    private function type(string $typeKey): ReportSubjectType
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        return $type instanceof ReportSubjectType && $type !== ReportSubjectType::Event
            ? $type
            : throw new InvalidArgumentException("Unsupported Directory report subject [{$typeKey}]");
    }

    private function labelFor(ReportSubjectType $type): string
    {
        return match ($type) {
            ReportSubjectType::Institution => __('Institution'),
            ReportSubjectType::Person => __('Speaker'),
            ReportSubjectType::Reference => __('Reference'),
            ReportSubjectType::DonationChannel => __('Donation Channel'),
            ReportSubjectType::Event => throw new InvalidArgumentException('Events are not a Directory report subject.'),
        };
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(ReportSubjectType $type): string
    {
        return MorphModelResolver::resolve(match ($type) {
            ReportSubjectType::Institution => 'institution',
            ReportSubjectType::Person => 'person',
            ReportSubjectType::Reference => 'reference',
            ReportSubjectType::DonationChannel => 'donation_channel',
            ReportSubjectType::Event => throw new InvalidArgumentException('Events are not a Directory report subject.'),
        });
    }

    private function subject(ReportSubjectType $type, Model $model): ReportSubject
    {
        $fallbackTitle = (string) $model->getKey();

        return new ReportSubject(
            type: $type,
            id: $fallbackTitle,
            label: $this->labelFor($type),
            title: $this->title($model, $fallbackTitle),
            slug: $this->slug($model),
            publicUrl: $this->publicUrl($model),
            adminUrl: $this->adminUrl($model),
            imageUrl: $model->getMorphClass() === 'person' ? $model->getAttribute('public_main_url') : null,
        );
    }

    private function title(Model $model, string $fallbackTitle): string
    {
        return match ($model->getMorphClass()) {
            'institution' => filled($model->getAttribute('name')) ? (string) $model->getAttribute('name') : $fallbackTitle,
            'person' => filled($model->getAttribute('formatted_name')) ? (string) $model->getAttribute('formatted_name') : $fallbackTitle,
            'reference' => filled($model->getAttribute('title')) ? (string) $model->getAttribute('title') : $fallbackTitle,
            'donation_channel' => filled($model->getAttribute('label'))
                ? (string) $model->getAttribute('label')
                : (filled($model->getAttribute('account_name')) ? (string) $model->getAttribute('account_name') : $fallbackTitle),
            default => $fallbackTitle,
        };
    }

    private function slug(Model $model): ?string
    {
        return match ($model->getMorphClass()) {
            'institution', 'person', 'reference' => (string) $model->getAttribute('slug'),
            default => null,
        };
    }

    private function publicUrl(Model $model): ?string
    {
        return match ($model->getMorphClass()) {
            'institution' => route('institutions.show', $model),
            'person' => route('persons.show', $model),
            'reference' => route('references.show', $model),
            default => null,
        };
    }

    private function adminUrl(Model $model): ?string
    {
        return match ($model->getMorphClass()) {
            'institution' => AdminResourceUrl::edit('institutions', (string) $model->getKey()),
            'person' => AdminResourceUrl::edit('persons', (string) $model->getKey()),
            'reference' => AdminResourceUrl::edit('references', (string) $model->getKey()),
            'donation_channel' => AdminResourceUrl::edit('donation-channels', (string) $model->getKey()),
            default => null,
        };
    }
}
