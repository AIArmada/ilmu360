<?php

declare(strict_types=1);

namespace App\Support\Reports;

use AIArmada\FilamentEvents\Resources\EventResource;
use App\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\Persons\PersonResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectPresentation;
use App\Shared\Reports\ReportSubjectResolver;
use App\Shared\Reports\ReportSubjectType;
use App\Support\Models\SlugOrUuidResolver;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @internal Interim global adapter until Directory and Events expose their own
 * report subject APIs. Reports depend only on the Shared contract, so this
 * model-aware implementation is reused as a temporary seam rather than a
 * feature exception.
 */
final readonly class GlobalReportSubjectResolver implements ReportSubjectResolver
{
    public function __construct(
        private SlugOrUuidResolver $slugOrUuidResolver,
    ) {}

    /**
     * @return list<string>
     */
    public function validKeys(): array
    {
        return ReportSubjectType::keys();
    }

    public function label(string $typeKey): string
    {
        return $this->labelFor($this->type($typeKey));
    }

    public function exists(string $typeKey, string $entityId): bool
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        if (! $type instanceof ReportSubjectType || trim($entityId) === '') {
            return false;
        }

        return $this->modelClass($type)::query()->whereKey($entityId)->exists();
    }

    public function find(string $typeKey, string $entityId): ?ReportSubject
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        if (! $type instanceof ReportSubjectType || trim($entityId) === '') {
            return null;
        }

        $model = $this->modelClass($type)::query()->find($entityId);

        return $model instanceof Model ? $this->subject($type, $model) : null;
    }

    public function resolve(string $typeKey, string $identifier): ?ReportSubject
    {
        $type = ReportSubjectType::tryFrom($typeKey);

        if (! $type instanceof ReportSubjectType || trim($identifier) === '') {
            return null;
        }

        $model = match ($type) {
            ReportSubjectType::Event => $this->slugOrUuidResolver->first(Event::query(), 'events.slug', $identifier),
            ReportSubjectType::Institution => $this->slugOrUuidResolver->first(Institution::query(), 'institutions.slug', $identifier),
            ReportSubjectType::Person => $this->slugOrUuidResolver->first(Person::query(), 'persons.slug', $identifier),
            ReportSubjectType::Reference => $this->slugOrUuidResolver->first(Reference::query(), 'references.slug', $identifier),
            ReportSubjectType::DonationChannel => DonationChannel::query()->find($identifier),
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
        return ReportSubjectType::tryFrom($typeKey)
            ?? throw new InvalidArgumentException("Unsupported entity type [{$typeKey}]");
    }

    private function labelFor(ReportSubjectType $type): string
    {
        return match ($type) {
            ReportSubjectType::Event => __('Event'),
            ReportSubjectType::Institution => __('Institution'),
            ReportSubjectType::Person => __('Speaker'),
            ReportSubjectType::Reference => __('Reference'),
            ReportSubjectType::DonationChannel => __('Donation Channel'),
        };
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(ReportSubjectType $type): string
    {
        return match ($type) {
            ReportSubjectType::Event => Event::class,
            ReportSubjectType::Institution => Institution::class,
            ReportSubjectType::Person => Person::class,
            ReportSubjectType::Reference => Reference::class,
            ReportSubjectType::DonationChannel => DonationChannel::class,
        };
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
            imageUrl: $this->imageUrl($model),
        );
    }

    private function title(Model $model, string $fallbackTitle): string
    {
        return match (true) {
            $model instanceof Event => filled($model->title) ? (string) $model->title : $fallbackTitle,
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
            $model instanceof Event => (string) $model->slug,
            $model instanceof Institution => (string) $model->slug,
            $model instanceof Person => (string) $model->slug,
            $model instanceof Reference => (string) $model->slug,
            default => null,
        };
    }

    private function publicUrl(Model $model): ?string
    {
        return match (true) {
            $model instanceof Event => route('events.show', $model),
            $model instanceof Institution => route('institutions.show', $model),
            $model instanceof Person => route('persons.show', $model),
            $model instanceof Reference => route('references.show', $model),
            default => null,
        };
    }

    private function adminUrl(Model $model): ?string
    {
        return match (true) {
            $model instanceof Event => EventResource::getUrl('view', ['record' => $model], panel: 'admin'),
            $model instanceof Institution => InstitutionResource::getUrl('edit', ['record' => $model], panel: 'admin'),
            $model instanceof Person => PersonResource::getUrl('edit', ['record' => $model], panel: 'admin'),
            $model instanceof Reference => ReferenceResource::getUrl('edit', ['record' => $model], panel: 'admin'),
            $model instanceof DonationChannel => DonationChannelResource::getUrl('edit', ['record' => $model], panel: 'admin'),
            default => null,
        };
    }

    private function imageUrl(Model $model): ?string
    {
        return $model instanceof Person ? $model->public_main_url : null;
    }
}
