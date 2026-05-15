<?php

namespace App\Models\Concerns;

use App\Enums\ContactCategory;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasContacts
{
    /**
     * @return MorphMany<Contact, $this>
     */
    public function contacts(): MorphMany
    {
        return $this->morphMany(Contact::class, 'contactable')
            ->orderBy('order_column')
            ->orderBy('created_at');
    }

    public function getEmailAttribute(): ?string
    {
        return $this->contacts()->where('category', ContactCategory::Email->value)->value('value');
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->contacts()->where('category', ContactCategory::Phone->value)->value('value');
    }
}
