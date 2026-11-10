<?php

declare(strict_types=1);

namespace App\Support\Directory;

use App\Features\Directory\Api\DirectoryReportSubjectProvider;
use App\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\Persons\PersonResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Models\DonationChannel;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectPresentation;
use App\Shared\Reports\ReportSubjectType;
use App\Support\Models\SlugOrUuidResolver;
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
            imageUrl: $model instanceof Person ? $model->public_main_url : null,
        );
    }

    private function title(Model $model, string $fallbackTitle): string
    {
        return match (true) {
            $model instanceof Institution => filled($model->name) ? (string) $model->name : $fallbackTitle,
            $model instanceof Person => filled($model->formatted_name) ? (string) $model->formatted_name : $fallbackTitle,
            $model instanceof Reference => filled($model->title) ? (string) $model->title : $fallbackTitle,
            $model instanceof DonationChannel => filled($model->label)
                ? (string) $model->label
                : (filled($model->account_name) ? (string) $model->account_name : $fallbackTitle),
            default => $fallbackTitle,
        };
    }

    private function slug(Model $model): ?string
    {
        return match (true) {
            $model instanceof Institution => (string) $model->slug,
            $model instanceof Person => (string) $model->slug,
            $model instanceof Reference => (string) $model->slug,
            default => null,
        };
    }

    private function publicUrl(Model $model): ?string
    {
        return match (true) {
            $model instanceof Institution => route('institutions.show', $model),
            $model instanceof Person => route('persons.show', $model),
            $model instanceof Reference => route('references.show', $model),
            default => null,
        };
    }

    private function adminUrl(Model $model): ?string
    {
        return match (true) {
            $model instanceof Institution => InstitutionResource::getUrl('edit', ['record' => $model], panel: 'admin'),
            $model instanceof Person => PersonResource::getUrl('edit', ['record' => $model], panel: 'admin'),
            $model instanceof Reference => ReferenceResource::getUrl('edit', ['record' => $model], panel: 'admin'),
            $model instanceof DonationChannel => DonationChannelResource::getUrl('edit', ['record' => $model], panel: 'admin'),
            default => null,
        };
    }
}
