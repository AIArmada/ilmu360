# Person Identity System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename `speakers` → `persons`, replace flat JSON title/credential columns with a normalized relational system (titles, credentials, affiliations, roles, names), and refactor every layer of the application.

**Architecture:** `persons` is the canonical identity table (renamed from `speakers`). Titles, credentials, and affiliations use polymorphic assignment tables so any model can participate. Event-specific roles (Speaker, Moderator, Imam) stay on `event_involvements.role_code` unchanged.

**Tech Stack:** Laravel 13, Filament v5, Livewire v4, PostgreSQL, Spatie Media Library v11, Scout/Typesense, PHP 8.5

**Spec:** `docs/superpowers/specs/2026-07-24-person-identity-system-design.md`

## Global Constraints

- UUID primary keys on all tables (`uuid('id')->primary()`)
- No DB-level FK constraints or cascades (application-enforced)
- No SoftDeletes (uses `spatie/laravel-deleted-models`)
- `timestampTz` for lifecycle timestamps
- PostgreSQL with JSONB
- Indexes on morph column pairs, FK columns, and status columns
- `EventKeyPersonRole::Speaker = 'speaker'` stays unchanged — it's an event role, not the entity
- Public-facing label stays "Penceramah" — only internal identifiers change
- No backward compatibility, no legacy code, no shims
- Run `vendor/bin/pint --dirty --format agent` after any PHP file changes
- Run `vendor/bin/phpstan analyse --ansi` — must pass level 6
- Run `vendor/bin/pest --parallel` for tests

---

## Task Dependency Graph

```
Phase 1: New Tables & Models (Tasks 1-12)
    ↓
Phase 2: Seed Reference Data (Task 13)
    ↓
Phase 3: Rename speakers → persons (Tasks 14-15)
    ↓
Phase 4: Migrate Data (Task 16)
    ↓
Phase 5: Update Person Model (Task 17)
    ↓
Phase 6: Full Code Refactor (Tasks 18-27)
    ↓
Phase 7: Cleanup (Task 28)
```

---

## Phase 1: New Tables & Models

### Task 1: Create new enums

**Files:**
- Create: `app/Enums/TitleUsagePosition.php`
- Create: `app/Enums/AssignmentStatus.php`
- Create: `app/Enums/IssuerType.php`
- Create: `app/Enums/CredentialType.php`
- Create: `app/Enums/AffiliationType.php`
- Create: `app/Enums/PersonNameType.php`

**Interfaces:**
- Produces: 6 string-backed enums implementing `Filament\Support\Contracts\HasLabel`

- [ ] **Step 1: Create `TitleUsagePosition` enum**

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TitleUsagePosition: string implements HasLabel
{
    case BeforeName = 'before_name';
    case AfterName = 'after_name';

    public function getLabel(): string
    {
        return match ($this) {
            self::BeforeName => __('Before Name'),
            self::AfterName => __('After Name'),
        };
    }
}
```

- [ ] **Step 2: Create `AssignmentStatus` enum**

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AssignmentStatus: string implements HasLabel
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Revoked => __('Revoked'),
            self::Expired => __('Expired'),
        };
    }
}
```

- [ ] **Step 3: Create `IssuerType` enum**

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IssuerType: string implements HasLabel
{
    case Government = 'government';
    case Royal = 'royal';
    case ReligiousBody = 'religious_body';
    case University = 'university';
    case ProfessionalBoard = 'professional_board';
    case Organization = 'organization';

    public function getLabel(): string
    {
        return match ($this) {
            self::Government => __('Government'),
            self::Royal => __('Royal'),
            self::ReligiousBody => __('Religious Body'),
            self::University => __('University'),
            self::ProfessionalBoard => __('Professional Board'),
            self::Organization => __('Organization'),
        };
    }
}
```

- [ ] **Step 4: Create `CredentialType` enum**

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CredentialType: string implements HasLabel
{
    case AcademicDegree = 'academic_degree';
    case ProfessionalLicense = 'professional_license';
    case Certification = 'certification';

    public function getLabel(): string
    {
        return match ($this) {
            self::AcademicDegree => __('Academic Degree'),
            self::ProfessionalLicense => __('Professional License'),
            self::Certification => __('Certification'),
        };
    }
}
```

- [ ] **Step 5: Create `AffiliationType` enum**

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AffiliationType: string implements HasLabel
{
    case Member = 'member';
    case Employee = 'employee';
    case Advisor = 'advisor';
    case Partner = 'partner';
    case ResidentScholar = 'resident_scholar';

    public function getLabel(): string
    {
        return match ($this) {
            self::Member => __('Member'),
            self::Employee => __('Employee'),
            self::Advisor => __('Advisor'),
            self::Partner => __('Partner'),
            self::ResidentScholar => __('Resident Scholar'),
        };
    }
}
```

- [ ] **Step 6: Create `PersonNameType` enum**

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PersonNameType: string implements HasLabel
{
    case Legal = 'legal';
    case Display = 'display';
    case Birth = 'birth';
    case Religious = 'religious';
    case Professional = 'professional';
    case Previous = 'previous';

    public function getLabel(): string
    {
        return match ($this) {
            self::Legal => __('Legal'),
            self::Display => __('Display'),
            self::Birth => __('Birth'),
            self::Religious => __('Religious'),
            self::Professional => __('Professional'),
            self::Previous => __('Previous'),
        };
    }
}
```

- [ ] **Step 7: Format and verify**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add app/Enums/TitleUsagePosition.php app/Enums/AssignmentStatus.php app/Enums/IssuerType.php app/Enums/CredentialType.php app/Enums/AffiliationType.php app/Enums/PersonNameType.php
git commit -m "feat: add enums for person identity system"
```

---

### Task 2: Create `title_categories` table and model

**Files:**
- Create: `database/migrations/2026_07_24_000010_create_title_categories_table.php`
- Create: `app/Models/TitleCategory.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration create_title_categories_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_categories');
    }
};
```

- [ ] **Step 2: Create model**

```bash
php artisan make:model TitleCategory --no-interaction
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TitleCategory extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'sort_order',
    ];

    /**
     * @return HasMany<Title, $this>
     */
    public function titles(): HasMany
    {
        return $this->hasMany(Title::class);
    }
}
```

- [ ] **Step 3: Run migration**

Run: `php artisan migrate --no-interaction`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*_create_title_categories_table.php app/Models/TitleCategory.php
git commit -m "feat: add title_categories table and model"
```

---

### Task 3: Create `titles` table and model

**Files:**
- Create: `database/migrations/*_create_titles_table.php`
- Create: `app/Models/Title.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration create_titles_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('titles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->index();
            $table->string('name', 100);
            $table->string('short_form', 50)->nullable();
            $table->foreignUuid('country_id')->nullable();
            $table->string('language_code', 10)->nullable();
            $table->string('usage_position', 20);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->index(['usage_position', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('titles');
    }
};
```

- [ ] **Step 2: Create model**

```bash
php artisan make:model Title --no-interaction
```

```php
<?php

namespace App\Models;

use App\Enums\TitleUsagePosition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Title extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'short_form',
        'country_id',
        'language_code',
        'usage_position',
        'sort_order',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'usage_position' => TitleUsagePosition::class,
        ];
    }

    /**
     * @return BelongsTo<TitleCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TitleCategory::class, 'category_id');
    }

    /**
     * @return HasMany<TitleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TitleAssignment::class);
    }
}
```

- [ ] **Step 3: Run migration**

Run: `php artisan migrate --no-interaction`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*_create_titles_table.php app/Models/Title.php
git commit -m "feat: add titles table and model"
```

---

### Task 4: Create `title_issuers` table and model

**Files:**
- Create: `database/migrations/*_create_title_issuers_table.php`
- Create: `app/Models/TitleIssuer.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration create_title_issuers_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_issuers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('country_id')->nullable();
            $table->foreignUuid('institution_id')->nullable()->index();
            $table->string('issuer_name');
            $table->string('issuer_type', 50);
            $table->timestampsTz();

            $table->index('issuer_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_issuers');
    }
};
```

- [ ] **Step 2: Create model**

```bash
php artisan make:model TitleIssuer --no-interaction
```

```php
<?php

namespace App\Models;

use App\Enums\IssuerType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitleIssuer extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'country_id',
        'institution_id',
        'issuer_name',
        'issuer_type',
    ];

    protected function casts(): array
    {
        return [
            'issuer_type' => IssuerType::class,
        ];
    }
}
```

- [ ] **Step 3: Run migration and commit**

```bash
php artisan migrate --no-interaction
git add database/migrations/*_create_title_issuers_table.php app/Models/TitleIssuer.php
git commit -m "feat: add title_issuers table and model"
```

---

### Task 5: Create `title_assignments` table and model

**Files:**
- Create: `database/migrations/*_create_title_assignments_table.php`
- Create: `app/Models/TitleAssignment.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration create_title_assignments_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('titleable_type');
            $table->foreignUuid('titleable_id');
            $table->foreignUuid('title_id')->index();
            $table->foreignUuid('issuer_id')->nullable();
            $table->date('date_awarded')->nullable();
            $table->date('date_expired')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampsTz();

            $table->index(['titleable_type', 'titleable_id'], 'title_assignments_titleable_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_assignments');
    }
};
```

- [ ] **Step 2: Create model**

```bash
php artisan make:model TitleAssignment --no-interaction
```

```php
<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TitleAssignment extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'titleable_type',
        'titleable_id',
        'title_id',
        'issuer_id',
        'date_awarded',
        'date_expired',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'date_awarded' => 'immutable_date',
            'date_expired' => 'immutable_date',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function titleable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Title, $this>
     */
    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }
}
```

- [ ] **Step 3: Run migration and commit**

```bash
php artisan migrate --no-interaction
git add database/migrations/*_create_title_assignments_table.php app/Models/TitleAssignment.php
git commit -m "feat: add title_assignments table and model"
```

---

### Task 6: Create `credential_definitions` table and model

**Files:**
- Create: `database/migrations/*_create_credential_definitions_table.php`
- Create: `app/Models/CredentialDefinition.php`

- [ ] **Step 1: Create migration and model**

```bash
php artisan make:migration create_credential_definitions_table --no-interaction
```

Migration:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('short_form', 50)->nullable();
            $table->string('field', 100)->nullable();
            $table->string('credential_type', 50);
            $table->string('language_code', 10)->nullable();
            $table->timestampsTz();

            $table->index('credential_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_definitions');
    }
};
```

Model:
```php
<?php

namespace App\Models;

use App\Enums\CredentialType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CredentialDefinition extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'short_form',
        'field',
        'credential_type',
        'language_code',
    ];

    protected function casts(): array
    {
        return [
            'credential_type' => CredentialType::class,
        ];
    }
}
```

- [ ] **Step 2: Run migration and commit**

```bash
php artisan migrate --no-interaction
git add database/migrations/*_create_credential_definitions_table.php app/Models/CredentialDefinition.php
git commit -m "feat: add credential_definitions table and model"
```

---

### Task 7: Create `credential_assignments` table and model

**Files:**
- Create: `database/migrations/*_create_credential_assignments_table.php`
- Create: `app/Models/CredentialAssignment.php`

- [ ] **Step 1: Create migration and model**

```bash
php artisan make:migration create_credential_assignments_table --no-interaction
```

Migration:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('credentialable_type');
            $table->foreignUuid('credentialable_id');
            $table->foreignUuid('credential_id')->index();
            $table->foreignUuid('issuing_institution_id')->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->date('date_obtained')->nullable();
            $table->date('date_expired')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampsTz();

            $table->index(['credentialable_type', 'credentialable_id'], 'credential_assignments_cred_index');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_assignments');
    }
};
```

Model:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CredentialAssignment extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'credentialable_type',
        'credentialable_id',
        'credential_id',
        'issuing_institution_id',
        'registration_number',
        'date_obtained',
        'date_expired',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_obtained' => 'immutable_date',
            'date_expired' => 'immutable_date',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function credentialable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<CredentialDefinition, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(CredentialDefinition::class);
    }
}
```

- [ ] **Step 2: Run migration and commit**

```bash
php artisan migrate --no-interaction
git add database/migrations/*_create_credential_assignments_table.php app/Models/CredentialAssignment.php
git commit -m "feat: add credential_assignments table and model"
```

---

### Task 8: Create `affiliations` table and model

**Files:**
- Create: `database/migrations/*_create_affiliations_table.php`
- Create: `app/Models/Affiliation.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration create_affiliations_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('affiliatable_type');
            $table->foreignUuid('affiliatable_id');
            $table->foreignUuid('institution_id')->index();
            $table->string('affiliation_type', 50);
            $table->date('joined_at')->nullable();
            $table->date('left_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index(['affiliatable_type', 'affiliatable_id'], 'affiliations_affiliatable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliations');
    }
};
```

- [ ] **Step 2: Create model**

```bash
php artisan make:model Affiliation --no-interaction
```

```php
<?php

namespace App\Models;

use App\Enums\AffiliationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Affiliation extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'affiliatable_type',
        'affiliatable_id',
        'institution_id',
        'affiliation_type',
        'joined_at',
        'left_at',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'affiliation_type' => AffiliationType::class,
            'joined_at' => 'immutable_date',
            'left_at' => 'immutable_date',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function affiliatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<AffiliationRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(AffiliationRole::class);
    }
}
```

- [ ] **Step 3: Run migration and commit**

```bash
php artisan migrate --no-interaction
git add database/migrations/*_create_affiliations_table.php app/Models/Affiliation.php
git commit -m "feat: add affiliations table and model"
```

---

### Task 9: Create `affiliation_roles` table and model

**Files:**
- Create: `database/migrations/*_create_affiliation_roles_table.php`
- Create: `app/Models/AffiliationRole.php`

- [ ] **Step 1: Create migration and model**

```bash
php artisan make:migration create_affiliation_roles_table --no-interaction
```

Migration:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliation_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('affiliation_id')->index();
            $table->string('role_name', 150);
            $table->string('department', 150)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliation_roles');
    }
};
```

Model:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliationRole extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'affiliation_id',
        'role_name',
        'department',
        'start_date',
        'end_date',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'is_current' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Affiliation, $this>
     */
    public function affiliation(): BelongsTo
    {
        return $this->belongsTo(Affiliation::class);
    }
}
```

- [ ] **Step 2: Run migration and commit**

```bash
php artisan migrate --no-interaction
git add database/migrations/*_create_affiliation_roles_table.php app/Models/AffiliationRole.php
git commit -m "feat: add affiliation_roles table and model"
```

---

### Task 10: Create `person_names` table and model

**Files:**
- Create: `database/migrations/*_create_person_names_table.php`
- Create: `app/Models/PersonName.php`

- [ ] **Step 1: Create migration and model**

```bash
php artisan make:migration create_person_names_table --no-interaction
```

Migration:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_names', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('person_id')->index();
            $table->string('name_type', 50);
            $table->string('full_name');
            $table->string('language_code', 10);
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index(['person_id', 'name_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_names');
    }
};
```

Model:
```php
<?php

namespace App\Models;

use App\Enums\PersonNameType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonName extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'person_id',
        'name_type',
        'full_name',
        'language_code',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'name_type' => PersonNameType::class,
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
```

- [ ] **Step 2: Run migration and commit**

```bash
php artisan migrate --no-interaction
git add database/migrations/*_create_person_names_table.php app/Models/PersonName.php
git commit -m "feat: add person_names table and model"
```

---

### Task 11: Add new columns to speakers table

**Files:**
- Create: `database/migrations/*_add_person_identity_columns_to_speakers_table.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_person_identity_columns_to_speakers_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->string('family_name', 100)->nullable()->after('name');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->foreignUuid('nationality_country_id')->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            $table->dropColumn(['family_name', 'date_of_birth', 'nationality_country_id']);
        });
    }
};
```

- [ ] **Step 2: Run migration and commit**

```bash
php artisan migrate --no-interaction
git add database/migrations/*_add_person_identity_columns_to_speakers_table.php
git commit -m "feat: add family_name, date_of_birth, nationality_country_id to speakers"
```

---

### Task 12: Write model relationship tests

**Files:**
- Create: `tests/Feature/PersonIdentityModelsTest.php`

**Interfaces:**
- Consumes: All models from Tasks 2-10
- Produces: Confidence that models, relationships, and casts work

- [ ] **Step 1: Write test**

```php
<?php

use App\Enums\AssignmentStatus;
use App\Enums\CredentialType;
use App\Enums\TitleUsagePosition;
use App\Models\Affiliation;
use App\Models\AffiliationRole;
use App\Models\CredentialAssignment;
use App\Models\CredentialDefinition;
use App\Models\Institution;
use App\Models\PersonName;
use App\Models\Speaker;
use App\Models\Title;
use App\Models\TitleAssignment;
use App\Models\TitleCategory;

describe('person identity models', function () {
    it('creates a title category with titles', function () {
        $category = TitleCategory::create([
            'code' => 'test_category',
            'name' => 'Test Category',
            'sort_order' => 10,
        ]);

        $title = Title::create([
            'category_id' => $category->id,
            'name' => 'Test Title',
            'short_form' => 'TT',
            'usage_position' => TitleUsagePosition::BeforeName,
            'sort_order' => 10,
        ]);

        expect($category->titles)->toHaveCount(1);
        expect($title->category->code)->toBe('test_category');
        expect($title->usage_position)->toBe(TitleUsagePosition::BeforeName);
    });

    it('assigns a title to a speaker polymorphically', function () {
        $speaker = Speaker::factory()->create();
        $category = TitleCategory::create(['code' => 'state_honour', 'name' => 'State Honour']);
        $title = Title::create([
            'category_id' => $category->id,
            'name' => 'Datuk',
            'short_form' => 'Datuk',
            'usage_position' => TitleUsagePosition::BeforeName,
            'sort_order' => 90,
        ]);

        $assignment = TitleAssignment::create([
            'titleable_type' => 'speaker',
            'titleable_id' => $speaker->id,
            'title_id' => $title->id,
            'status' => AssignmentStatus::Active,
        ]);

        expect($speaker->fresh()->titleAssignments)->toHaveCount(1);
        expect($assignment->title->name)->toBe('Datuk');
        expect($assignment->titleable->id)->toBe($speaker->id);
    });

    it('creates credential definition and assignment', function () {
        $speaker = Speaker::factory()->create();
        $definition = CredentialDefinition::create([
            'name' => 'Doctor of Philosophy',
            'short_form' => 'PhD',
            'credential_type' => CredentialType::AcademicDegree,
        ]);

        $assignment = CredentialAssignment::create([
            'credentialable_type' => 'speaker',
            'credentialable_id' => $speaker->id,
            'credential_id' => $definition->id,
            'date_obtained' => '2020-06-15',
        ]);

        expect($speaker->fresh()->credentialAssignments)->toHaveCount(1);
        expect($assignment->credential->short_form)->toBe('PhD');
    });

    it('creates affiliation with roles for a speaker', function () {
        $speaker = Speaker::factory()->create();
        $institution = Institution::factory()->create();

        $affiliation = Affiliation::create([
            'affiliatable_type' => 'speaker',
            'affiliatable_id' => $speaker->id,
            'institution_id' => $institution->id,
            'affiliation_type' => 'employee',
            'is_primary' => true,
        ]);

        AffiliationRole::create([
            'affiliation_id' => $affiliation->id,
            'role_name' => 'CEO',
            'is_current' => true,
        ]);

        AffiliationRole::create([
            'affiliation_id' => $affiliation->id,
            'role_name' => 'Board Member',
            'is_current' => true,
        ]);

        expect($speaker->fresh()->affiliations)->toHaveCount(1);
        expect($affiliation->fresh()->roles)->toHaveCount(2);
    });

    it('creates person names', function () {
        $speaker = Speaker::factory()->create();

        PersonName::create([
            'person_id' => $speaker->id,
            'name_type' => 'display',
            'full_name' => 'Ahmad Rahman',
            'language_code' => 'en',
            'is_primary' => true,
        ]);

        PersonName::create([
            'person_id' => $speaker->id,
            'name_type' => 'religious',
            'full_name' => 'أحمد بن عبد الرحمن',
            'language_code' => 'ar',
        ]);

        expect($speaker->fresh()->names)->toHaveCount(2);
    });
});
```

**Note:** This test uses `Speaker` because the rename hasn't happened yet. The relationships (`titleAssignments`, `credentialAssignments`, `affiliations`, `names`) need to be added to the Speaker model first. See Task 17 for the model update — for now, add just the relationship methods to Speaker temporarily.

- [ ] **Step 2: Add temporary relationship methods to Speaker model**

Add these to `app/Models/Speaker.php` (will be moved to Person in Task 17):

```php
/**
 * @return MorphMany<TitleAssignment, $this>
 */
public function titleAssignments(): MorphMany
{
    return $this->morphMany(TitleAssignment::class, 'titleable');
}

/**
 * @return MorphMany<CredentialAssignment, $this>
 */
public function credentialAssignments(): MorphMany
{
    return $this->morphMany(CredentialAssignment::class, 'credentialable');
}

/**
 * @return MorphMany<Affiliation, $this>
 */
public function affiliations(): MorphMany
{
    return $this->morphMany(Affiliation::class, 'affiliatable');
}

/**
 * @return HasMany<PersonName, $this>
 */
public function names(): HasMany
{
    return $this->hasMany(PersonName::class, 'person_id');
}
```

Also add the necessary imports at the top.

- [ ] **Step 3: Run test**

Run: `vendor/bin/pest --parallel --filter=PersonIdentityModelsTest`

Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/PersonIdentityModelsTest.php app/Models/Speaker.php
git commit -m "test: add person identity model relationship tests"
```

---

## Phase 2: Seed Reference Data

### Task 13: Seed title categories and titles from enums

**Files:**
- Create: `database/seeders/TitleCategorySeeder.php`
- Create: `database/seeders/TitleSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Create `TitleCategorySeeder`**

```bash
php artisan make:seeder TitleCategorySeeder --no-interaction
```

```php
<?php

namespace Database\Seeders;

use App\Models\TitleCategory;
use Illuminate\Database\Seeder;

class TitleCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'state_honour', 'name' => 'State Honour', 'sort_order' => 10],
            ['code' => 'royal', 'name' => 'Royal Title', 'sort_order' => 20],
            ['code' => 'religious', 'name' => 'Religious Title', 'sort_order' => 30],
            ['code' => 'academic', 'name' => 'Academic Title', 'sort_order' => 40],
            ['code' => 'professional', 'name' => 'Professional Title', 'sort_order' => 50],
            ['code' => 'military', 'name' => 'Military Rank', 'sort_order' => 60],
            ['code' => 'social', 'name' => 'Social Honorific', 'sort_order' => 70],
        ];

        foreach ($categories as $category) {
            TitleCategory::firstOrCreate(['code' => $category['code']], $category);
        }
    }
}
```

- [ ] **Step 2: Create `TitleSeeder`**

This seeder reads the existing `Honorific`, `PreNominal`, `PostNominal` enum data and creates `titles` rows with reconciled global sort orders.

```bash
php artisan make:seeder TitleSeeder --no-interaction
```

```php
<?php

namespace Database\Seeders;

use App\Enums\Honorific;
use App\Enums\PostNominal;
use App\Enums\PreNominal;
use App\Enums\TitleUsagePosition;
use App\Models\Title;
use App\Models\TitleCategory;
use Illuminate\Database\Seeder;

class TitleSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedHonorifics();
        $this->seedPreNominals();
        $this->seedPostNominals();
    }

    private function seedHonorifics(): void
    {
        $category = TitleCategory::where('code', 'state_honour')->first();
        if (! $category) {
            return;
        }

        // Honorifics: global sort 20-99 (after leading pre-nominals 10-11)
        $baseOffset = 20;

        foreach (Honorific::cases() as $honorific) {
            $originalOrder = $this->honorificOriginalSortOrder($honorific);
            $globalOrder = $baseOffset + ($originalOrder / 10) - 1;

            Title::firstOrCreate(
                [
                    'category_id' => $category->id,
                    'name' => $honorific->getLabel(),
                ],
                [
                    'short_form' => $honorific->getLabel(),
                    'country_id' => null, // Will be set to MY for Malaysian titles
                    'language_code' => 'ms',
                    'usage_position' => TitleUsagePosition::BeforeName,
                    'sort_order' => (int) $globalOrder,
                ],
            );
        }
    }

    private function seedPreNominals(): void
    {
        // Leading pre-nominals (Prof, ProfMadya): sort 10-11
        // Trailing pre-nominals: sort 100+
        foreach (PreNominal::cases() as $preNominal) {
            $isLeading = in_array($preNominal, [PreNominal::Prof, PreNominal::ProfMadya], true);

            $category = match (true) {
                in_array($preNominal, [PreNominal::Prof, PreNominal::ProfMadya, PreNominal::Dr], true) => 'academic',
                in_array($preNominal, [PreNominal::Ir, PreNominal::Ar], true) => 'professional',
                default => 'religious',
            };

            $categoryModel = TitleCategory::where('code', $category)->first();
            if (! $categoryModel) {
                continue;
            }

            $sortOrder = $isLeading
                ? $preNominal === PreNominal::Prof ? 10 : 11
                : 100 + $this->preNominalOriginalSortOrder($preNominal);

            Title::firstOrCreate(
                [
                    'category_id' => $categoryModel->id,
                    'name' => $preNominal->getLabel(),
                ],
                [
                    'short_form' => $preNominal->getLabel(),
                    'language_code' => 'ms',
                    'usage_position' => TitleUsagePosition::BeforeName,
                    'sort_order' => $sortOrder,
                ],
            );
        }
    }

    private function seedPostNominals(): void
    {
        $category = TitleCategory::where('code', 'academic')->first();
        if (! $category) {
            return;
        }

        foreach (PostNominal::cases() as $postNominal) {
            $sortOrder = $this->postNominalOriginalSortOrder($postNominal);

            Title::firstOrCreate(
                [
                    'category_id' => $category->id,
                    'name' => $postNominal->getLabel(),
                ],
                [
                    'short_form' => $postNominal->getLabel(),
                    'language_code' => null,
                    'usage_position' => TitleUsagePosition::AfterName,
                    'sort_order' => $sortOrder,
                ],
            );
        }
    }

    private function honorificOriginalSortOrder(Honorific $honorific): int
    {
        return match ($honorific) {
            Honorific::Tun, Honorific::TohPuan => 10,
            Honorific::TanSri, Honorific::PuanSri => 20,
            Honorific::DatukSeriUtama => 30,
            Honorific::DatukPatinggi => 40,
            Honorific::DatukAmar => 50,
            Honorific::DatukSeriPanglima => 60,
            Honorific::DatukSeri, Honorific::DatoSri,
            Honorific::DatukPaduka, Honorific::DatinPaduka => 70,
            Honorific::DatukWira, Honorific::DatoWira, Honorific::DatoSetia => 80,
            Honorific::Datuk, Honorific::Dato, Honorific::Datin => 90,
        };
    }

    private function preNominalOriginalSortOrder(PreNominal $preNominal): int
    {
        return match ($preNominal) {
            PreNominal::Prof => 10,
            PreNominal::Syeikh => 20,
            PreNominal::SyeikhulMaqari => 21,
            PreNominal::Maulana => 22,
            PreNominal::Habib => 23,
            PreNominal::TuanGuru => 24,
            PreNominal::Pendeta => 25,
            PreNominal::Ustaz => 26,
            PreNominal::Ustazah => 27,
            PreNominal::ImamMuda => 28,
            PreNominal::PU, PreNominal::Dai => 29,
            PreNominal::Hafiz => 30,
            PreNominal::Hafizah => 31,
            PreNominal::Qari => 32,
            PreNominal::Qariah => 33,
            PreNominal::Mufti => 34,
            PreNominal::Kadi => 35,
            PreNominal::ProfMadya => 40,
            PreNominal::Ir => 50,
            PreNominal::Ar => 51,
            PreNominal::Dr => 60,
            PreNominal::Hj => 61,
            PreNominal::Hjh => 62,
        };
    }

    private function postNominalOriginalSortOrder(PostNominal $postNominal): int
    {
        return match ($postNominal) {
            PostNominal::PhD => 10,
            PostNominal::MSc => 20,
            PostNominal::MA => 21,
            PostNominal::BSc => 30,
            PostNominal::BA => 31,
            PostNominal::Lc => 32,
            PostNominal::Hons => 33,
            PostNominal::Dpl => 40,
        };
    }
}
```

- [ ] **Step 3: Register seeders in `DatabaseSeeder`**

Add after existing seeders in the `run()` method:

```php
$this->call([
    // ... existing seeders ...
    TitleCategorySeeder::class,
    TitleSeeder::class,
]);
```

- [ ] **Step 4: Run seeders and verify**

```bash
php artisan db:seed --class=TitleCategorySeeder --no-interaction
php artisan db:seed --class=TitleSeeder --no-interaction
```

Verify:
```bash
php artisan tinker --execute 'echo App\Models\TitleCategory::count() . " categories, " . App\Models\Title::count() . " titles";'
```

Expected: `7 categories, 33 titles` (17 honorifics + 23 pre-nominals + 8 post-nominals)

- [ ] **Step 5: Commit**

```bash
git add database/seeders/TitleCategorySeeder.php database/seeders/TitleSeeder.php database/seeders/DatabaseSeeder.php
git commit -m "feat: seed title categories and titles from enum data"
```

---

## Phase 3: Rename speakers → persons

### Task 14: Rename database tables

**Files:**
- Create: `database/migrations/*_rename_speakers_to_persons.php`
- Modify: All migrations referencing `speaker_search_terms`, `speaker_members`

- [ ] **Step 1: Create rename migration**

```bash
php artisan make:migration rename_speakers_to_persons --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('speakers', 'persons');
        Schema::rename('speaker_search_terms', 'person_search_terms');
        Schema::rename('speaker_members', 'person_members');

        // Update person_search_terms column
        Schema::table('person_search_terms', function ($table) {
            $table->renameColumn('speaker_id', 'person_id');
        });

        // Drop institution_speaker (replaced by affiliations)
        Schema::dropIfExists('institution_speaker');
    }

    public function down(): void
    {
        Schema::table('person_search_terms', function ($table) {
            $table->renameColumn('person_id', 'speaker_id');
        });

        Schema::rename('person_members', 'speaker_members');
        Schema::rename('person_search_terms', 'speaker_search_terms');
        Schema::rename('persons', 'speakers');
    }
};
```

- [ ] **Step 2: Create morph data migration**

```bash
php artisan make:migration update_speaker_morph_aliases_to_person --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update morph type columns
        DB::table('event_involvements')->where('involveable_type', 'speaker')->update(['involveable_type' => 'person']);
        DB::table('donatables')->where('donatable_type', 'speaker')->update(['donatable_type' => 'person']);
        DB::table('member_invitations')->where('subject_type', 'speaker')->update(['subject_type' => 'person']);
        DB::table('membership_applications')->where('subject_type', 'speaker')->update(['subject_type' => 'person']);
        DB::table('reports')->where('entity_type', 'speaker')->update(['entity_type' => 'person']);

        // Update share tracking if table exists
        if (DB::getSchemaBuilder()->hasTable('dawah_shares')) {
            DB::table('dawah_shares')->where('subject_type', 'speaker')->update(['subject_type' => 'person']);
        }

        // Update member permission strings
        DB::table('member_permissions')
            ->where('permission', 'like', 'speaker.%')
            ->update(['permission' => DB::raw("REPLACE(permission, 'speaker.', 'person.')")]);

        // NOTE: event_involvements.role_code = 'speaker' stays unchanged (event role)
        // NOTE: event_taxonomy_policy.policy_code = 'requires_speaker' stays unchanged
    }

    public function down(): void
    {
        DB::table('event_involvements')->where('involveable_type', 'person')->update(['involveable_type' => 'speaker']);
        DB::table('donatables')->where('donatable_type', 'person')->update(['donatable_type' => 'speaker']);
        DB::table('member_invitations')->where('subject_type', 'person')->update(['subject_type' => 'speaker']);
        DB::table('membership_applications')->where('subject_type', 'person')->update(['subject_type' => 'speaker']);
        DB::table('reports')->where('entity_type', 'person')->update(['entity_type' => 'speaker']);

        if (DB::getSchemaBuilder()->hasTable('dawah_shares')) {
            DB::table('dawah_shares')->where('subject_type', 'person')->update(['subject_type' => 'speaker']);
        }

        DB::table('member_permissions')
            ->where('permission', 'like', 'person.%')
            ->update(['permission' => DB::raw("REPLACE(permission, 'person.', 'speaker.')")]);
    }
};
```

- [ ] **Step 3: Run migrations**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*_rename_speakers_to_persons.php database/migrations/*_update_speaker_morph_aliases_to_person.php
git commit -m "feat: rename speakers to persons and update morph aliases"
```

---

### Task 15: Rename Speaker model to Person

**Files:**
- Rename: `app/Models/Speaker.php` → `app/Models/Person.php`
- Modify: `app/Models/EventKeyPerson.php`
- Modify: `app/Models/Event.php`
- Modify: `app/Models/Institution.php`
- Modify: `app/Models/User.php`
- Delete: `app/Models/InstitutionSpeakerPivot.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Models/Concerns/HasUserRestoration.php`

- [ ] **Step 1: Rename Speaker.php → Person.php**

```bash
git mv app/Models/Speaker.php app/Models/Person.php
```

Inside `app/Models/Person.php`:
- Change `class Speaker` → `class Person`
- Change `$table` property to `protected $table = 'persons';` (if not set, add it — the default convention would be `people` which we don't want)
- Change all `Speaker` type hints to `Person`
- Update `PUBLIC_DIRECTORY_SESSION_KEY` value from `'public_speakers_directory_seed'` to `'public_persons_directory_seed'`
- Update `$fillable`: add `family_name`, `date_of_birth`, `nationality_country_id`; remove `honorific`, `pre_nominal`, `post_nominal`, `qualifications`, `is_freelance`, `job_title`
- Update `$casts`: remove `honorific`, `pre_nominal`, `post_nominal`, `qualifications`, `is_freelance`
- Update `events()` relationship: change `'speaker'` morph alias to `'person'` in `wherePivot('involveable_type', 'speaker')` → `'person'`
- Update `eventKeyPeople()`: change `'speaker'` → `'person'`
- Update `institutions()` relationship: will be replaced by affiliations (see Task 17)
- Update media placeholder paths from `speaker*.png` to `person*.png`
- Remove all the `honorificSortOrder`, `preNominalSortOrder`, `postNominalSortOrder`, `formatDisplayedName` and related private methods (replaced in Task 17)

- [ ] **Step 2: Update morph map in AppServiceProvider**

In `app/Providers/AppServiceProvider.php`:
- Change `'speaker' => Speaker::class` → `'person' => Person::class`
- Update import from `App\Models\Speaker` → `App\Models\Person`
- Update observer registration: `Person::observe(PersonObserver::class)`
- Update public-slug binding list: `'speaker'` → `'person'`

- [ ] **Step 3: Delete InstitutionSpeakerPivot**

```bash
git rm app/Models/InstitutionSpeakerPivot.php
```

- [ ] **Step 4: Update EventKeyPerson model**

In `app/Models/EventKeyPerson.php`:
- Change `speaker()` relationship to `person()`
- Update `Speaker` import → `Person`
- Update `$speaker instanceof Speaker` → `$person instanceof Person`

- [ ] **Step 5: Update Event model**

In `app/Models/Event.php`:
- Update `@property` type hints
- Rename `speakers()` → `persons()`, `speakerKeyPeople()` → `personKeyPeople()`
- Update eager loads from `speakers` → `persons`, `keyPeople.speaker` → `keyPeople.person`
- Update computed attributes: `speaker_names` → `person_names`, `speaker_ids` → `person_ids`
- Update `setPrimaryOrganizer` type union

- [ ] **Step 6: Update Institution model**

In `app/Models/Institution.php`:
- Rename `speakers()` → `persons()` using new `affiliations` relationship
- Update imports

- [ ] **Step 7: Update User model**

In `app/Models/User.php`:
- Rename `speakers()` → `persons()` using `person_members` pivot
- Rename `followingSpeakers()` → `followingPersons()`
- Rename `verifiedSpeakers()` → `verifiedPersons()`

- [ ] **Step 8: Update HasUserRestoration trait**

In `app/Models/Concerns/HasUserRestoration.php`:
- Replace `speaker_members` → `person_members`
- Replace `verified_speaker_ids` → `verified_person_ids`
- Replace `verifiedSpeakers` → `verifiedPersons`

- [ ] **Step 9: Format**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 10: Run tests to find breakage**

Run: `vendor/bin/pest --parallel 2>&1 | head -50`

Expect: Many failures in tests that reference Speaker. Fix the most critical ones to get the app bootable.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "feat: rename Speaker model to Person, update relationships"
```

---

## Phase 4: Migrate Data

### Task 16: Migrate JSON columns to relational tables

**Files:**
- Create: `database/migrations/*_migrate_speaker_json_to_relational.php`

- [ ] **Step 1: Create data migration**

```bash
php artisan make:migration migrate_speaker_json_to_relational --no-interaction
```

```php
<?php

use App\Enums\AssignmentStatus;
use App\Enums\TitleUsagePosition;
use App\Models\Title;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->migrateHonorifics();
        $this->migratePreNominals();
        $this->migratePostNominals();
        $this->migrateQualifications();
        $this->migrateJobTitles();
        $this->migrateInstitutionSpeakerPivot();
    }

    private function migrateHonorifics(): void
    {
        $persons = DB::table('persons')
            ->whereNotNull('honorific')
            ->where('honorific', '!=', 'null')
            ->get(['id', 'honorific']);

        foreach ($persons as $person) {
            $honorifics = json_decode((string) $person->honorific, true);
            if (! is_array($honorifics)) {
                continue;
            }

            foreach ($honorifics as $honorificValue) {
                $title = $this->findTitleByName($this->honorificLabel($honorificValue), TitleUsagePosition::BeforeName);
                if (! $title) {
                    continue;
                }

                $this->createTitleAssignment($person->id, $title->id);
            }
        }
    }

    private function migratePreNominals(): void
    {
        $persons = DB::table('persons')
            ->whereNotNull('pre_nominal')
            ->where('pre_nominal', '!=', 'null')
            ->get(['id', 'pre_nominal']);

        foreach ($persons as $person) {
            $preNominals = json_decode((string) $person->pre_nominal, true);
            if (! is_array($preNominals)) {
                continue;
            }

            foreach ($preNominals as $preNominalValue) {
                $title = $this->findTitleByName($this->preNominalLabel($preNominalValue), TitleUsagePosition::BeforeName);
                if (! $title) {
                    continue;
                }

                $this->createTitleAssignment($person->id, $title->id);
            }
        }
    }

    private function migratePostNominals(): void
    {
        $persons = DB::table('persons')
            ->whereNotNull('post_nominal')
            ->where('post_nominal', '!=', 'null')
            ->get(['id', 'post_nominal']);

        foreach ($persons as $person) {
            $postNominals = json_decode((string) $person->post_nominal, true);
            if (! is_array($postNominals)) {
                continue;
            }

            foreach ($postNominals as $postNominalValue) {
                $title = $this->findTitleByName($this->postNominalLabel($postNominalValue), TitleUsagePosition::AfterName);
                if (! $title) {
                    continue;
                }

                $this->createTitleAssignment($person->id, $title->id);
            }
        }
    }

    private function migrateQualifications(): void
    {
        $persons = DB::table('persons')
            ->whereNotNull('qualifications')
            ->where('qualifications', '!=', 'null')
            ->get(['id', 'qualifications']);

        foreach ($persons as $person) {
            $qualifications = json_decode((string) $person->qualifications, true);
            if (! is_array($qualifications)) {
                continue;
            }

            foreach ($qualifications as $qualification) {
                if (! is_array($qualification) || ! isset($qualification['degree'])) {
                    continue;
                }

                $definitionId = $this->findOrCreateCredentialDefinition($qualification);
                if (! $definitionId) {
                    continue;
                }

                DB::table('credential_assignments')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'credentialable_type' => 'person',
                    'credentialable_id' => $person->id,
                    'credential_id' => $definitionId,
                    'issuing_institution_id' => $this->findInstitutionByName($qualification['institution'] ?? null),
                    'date_obtained' => isset($qualification['year']) ? "{$qualification['year']}-01-01" : null,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function migrateJobTitles(): void
    {
        $persons = DB::table('persons')
            ->whereNotNull('job_title')
            ->where('job_title', '!=', '')
            ->get(['id', 'job_title']);

        foreach ($persons as $person) {
            // Create affiliation without institution if none exists
            $affiliationId = (string) \Illuminate\Support\Str::uuid();
            DB::table('affiliations')->insert([
                'id' => $affiliationId,
                'affiliatable_type' => 'person',
                'affiliatable_id' => $person->id,
                'institution_id' => null,
                'affiliation_type' => 'employee',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('affiliation_roles')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'affiliation_id' => $affiliationId,
                'role_name' => $person->job_title,
                'is_current' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function migrateInstitutionSpeakerPivot(): void
    {
        // institution_speaker table was already dropped in the rename migration
        // If data existed before the drop, it should have been migrated before the drop.
        // In practice, this migration runs AFTER the rename migration,
        // so we handle this in the rename migration instead.
    }

    private function createTitleAssignment(string $personId, string $titleId): void
    {
        DB::table('title_assignments')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'titleable_type' => 'person',
            'titleable_id' => $personId,
            'title_id' => $titleId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function findTitleByName(string $name, TitleUsagePosition $position): ?object
    {
        return DB::table('titles')
            ->where('name', $name)
            ->where('usage_position', $position->value)
            ->first();
    }

    private function findOrCreateCredentialDefinition(array $qualification): ?string
    {
        $degree = $qualification['degree'] ?? null;
        if (! $degree) {
            return null;
        }

        $existing = DB::table('credential_definitions')
            ->where('short_form', $degree)
            ->first();

        if ($existing) {
            return $existing->id;
        }

        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('credential_definitions')->insert([
            'id' => $id,
            'name' => $degree,
            'short_form' => $degree,
            'credential_type' => 'academic_degree',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function findInstitutionByName(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        $institution = DB::table('institutions')->where('name', $name)->first();

        return $institution?->id;
    }

    // Label methods — these map enum values to their human-readable labels
    // They duplicate the logic from the enum classes being deleted

    private function honorificLabel(string $value): string
    {
        return match ($value) {
            'tun' => 'Tun',
            'toh_puan' => 'Toh Puan',
            'tan_sri' => 'Tan Sri',
            'puan_sri' => 'Puan Sri',
            'datuk' => 'Datuk',
            'dato' => "Dato'",
            'datin' => 'Datin',
            'datuk_seri' => 'Datuk Seri',
            'dato_sri' => "Dato' Sri",
            'datuk_paduka' => 'Datuk Paduka',
            'datin_paduka' => 'Datin Paduka',
            'datuk_wira' => 'Datuk Wira',
            'dato_wira' => "Dato' Wira",
            'dato_setia' => "Dato' Setia",
            'datuk_amar' => 'Datuk Amar',
            'datuk_patinggi' => 'Datuk Patinggi',
            'datuk_seri_panglima' => 'Datuk Seri Panglima',
            'datuk_seri_utama' => 'Datuk Seri Utama',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }

    private function preNominalLabel(string $value): string
    {
        return match ($value) {
            'dr' => 'Dr',
            'prof' => 'Prof',
            'prof_madya' => 'Prof Madya',
            'ir' => 'Ir',
            'ar' => 'Ar',
            'ustaz' => 'Ustaz',
            'ustazah' => 'Ustazah',
            'syeikh' => 'Syeikh',
            'syeikhul_maqari' => 'Syeikhul Maqari',
            'maulana' => 'Maulana',
            'habib' => 'Habib',
            'pendeta' => 'Pendeta',
            'tuan_guru' => 'Tuan Guru',
            'hj' => 'Hj',
            'hjh' => 'Hjh',
            'hafiz' => 'Hafiz',
            'hafizah' => 'Hafizah',
            'qari' => 'Qari',
            'qariah' => 'Qariah',
            'imam_muda' => 'Imam Muda',
            'pu' => 'PU',
            'dai' => 'Dai',
            'mufti' => 'Mufti',
            'kadi' => 'Kadi',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }

    private function postNominalLabel(string $value): string
    {
        return match ($value) {
            'PhD' => 'PhD',
            'MSc' => 'MSc',
            'MA' => 'MA',
            'BA' => 'BA',
            'BSc' => 'BSc',
            'HONS' => 'HONS',
            'Lc.' => 'Lc.',
            'Dpl.' => 'Dpl.',
            default => $value,
        };
    }

    public function down(): void
    {
        DB::table('title_assignments')->where('titleable_type', 'person')->delete();
        DB::table('credential_assignments')->where('credentialable_type', 'person')->delete();
        DB::table('affiliation_roles')->truncate();
        DB::table('affiliations')->where('affiliatable_type', 'person')->delete();
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 3: Verify data migration**

```bash
php artisan tinker --execute 'echo "Title assignments: " . App\Models\TitleAssignment::count() . "\n";'
php artisan tinker --execute 'echo "Credential assignments: " . App\Models\CredentialAssignment::count() . "\n";'
php artisan tinker --execute 'echo "Affiliations: " . App\Models\Affiliation::count() . "\n";'
php artisan tinker --execute 'echo "Affiliation roles: " . App\Models\AffiliationRole::count() . "\n";'
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*_migrate_speaker_json_to_relational.php
git commit -m "feat: migrate JSON columns to relational tables"
```

---

## Phase 5: Update Person Model

### Task 17: Finalize Person model with new relationships and formatting

**Files:**
- Modify: `app/Models/Person.php`
- Create: `app/Observers/PersonObserver.php` (rename from SpeakerObserver)
- Modify: `app/Support/Search/SpeakerSearchService.php` → rename to `PersonSearchService.php`

- [ ] **Step 1: Add new relationships to Person model**

Add `nationality` BelongsTo, update `titleAssignments` to use `'person'` morph alias, add `documents` and `certificates` media collections.

In `app/Models/Person.php`, add to fillable:
```php
'family_name',
'date_of_birth',
'nationality_country_id',
```

Add relationship:
```php
/**
 * @return BelongsTo<AddressCountry, $this>
 */
public function nationality(): BelongsTo
{
    return $this->belongsTo(AddressCountry::class, 'nationality_country_id');
}
```

- [ ] **Step 2: Rewrite `formatted_name` accessor**

Replace the entire `getFormattedNameAttribute` and all the private sorting methods with the new relational version from the spec:

```php
public function getFormattedNameAttribute(): string
{
    $assignments = $this->titleAssignments()
        ->where('status', 'active')
        ->with('title')
        ->get();

    $beforeTitles = $assignments
        ->filter(fn (TitleAssignment $a) => $a->title->usage_position === TitleUsagePosition::BeforeName)
        ->sortBy('title.sort_order')
        ->map(fn (TitleAssignment $a) => $a->title->short_form ?? $a->title->name);

    $afterTitles = $assignments
        ->filter(fn (TitleAssignment $a) => $a->title->usage_position === TitleUsagePosition::AfterName)
        ->sortBy('title.sort_order')
        ->map(fn (TitleAssignment $a) => $a->title->short_form ?? $a->title->name);

    $name = trim(implode(' ', $beforeTitles->all()) . ' ' . $this->name);

    if ($afterTitles->isNotEmpty()) {
        $name .= ', ' . implode(', ', $afterTitles->all());
    }

    return trim($name);
}
```

- [ ] **Step 3: Add new media collections**

In `registerMediaCollections()`:
```php
$this->addMediaCollection('documents')
    ->useDisk(config('media-library.disk_name'))
    ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);

$this->addMediaCollection('certificates')
    ->useDisk(config('media-library.disk_name'))
    ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp']);
```

- [ ] **Step 4: Update events() relationship morph alias**

```php
public function events(): BelongsToMany
{
    return $this->belongsToMany(Event::class, 'event_involvements', 'involveable_id', 'event_id')
        ->using(EventKeyPersonPivot::class)
        ->wherePivot('involveable_type', 'person')
        ->withPivot(['id', 'involveable_type', 'role_code', 'sort_order', 'notes'])
        ->withTimestamps()
        ->orderByPivot('sort_order');
}
```

- [ ] **Step 5: Remove all enum-based formatting methods**

Delete: `formatDisplayedName`, `orderedHonorificCases`, `orderedPreNominalCases`, `leadingPreNominalCases`, `trailingPreNominalCases`, `labelsFromHonorificCases`, `labelsFromPreNominalCases`, `orderedPostNominalValues`, `honorificSortOrder`, `preNominalSortOrder`, `postNominalSortOrder`, `postNominalDisplayValue`, `normalizedStringValues`, `searchableText` (rewrite to use title assignments).

- [ ] **Step 6: Remove honorific/pre_nominal/post_nominal from casts and booted hooks**

Remove the `saving` closure logic for `qualifications` → `post_nominal` derivation.

- [ ] **Step 7: Rename SpeakerObserver → PersonObserver**

```bash
git mv app/Observers/SpeakerObserver.php app/Observers/PersonObserver.php
```

Update class name, watched fields (remove `name`, `honorific`, `pre_nominal`, `post_nominal` from the dirty checks — now watch title assignment changes via model events on TitleAssignment instead).

- [ ] **Step 8: Format and test**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/pest --parallel --filter=PersonIdentityModelsTest
```

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: finalize Person model with relational title system"
```

---

## Phase 6: Full Code Refactor

### Task 18: Drop old columns and delete old enums

**Files:**
- Create: `database/migrations/*_drop_old_speaker_columns.php`
- Delete: `app/Enums/Honorific.php`
- Delete: `app/Enums/PreNominal.php`
- Delete: `app/Enums/PostNominal.php`

- [ ] **Step 1: Create drop migration**

```bash
php artisan make:migration drop_old_speaker_columns_from_persons --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn([
                'honorific',
                'pre_nominal',
                'post_nominal',
                'qualifications',
                'job_title',
                'is_freelance',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->jsonb('honorific')->nullable();
            $table->jsonb('pre_nominal')->nullable();
            $table->jsonb('post_nominal')->nullable();
            $table->jsonb('qualifications')->nullable();
            $table->string('job_title')->nullable();
            $table->boolean('is_freelance')->default(false);
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 3: Delete old enums**

```bash
git rm app/Enums/Honorific.php
git rm app/Enums/PreNominal.php
git rm app/Enums/PostNominal.php
```

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: drop old JSON columns and delete deprecated enums"
```

---

### Task 19: Update enums referencing Speaker

**Files:**
- Modify: `app/Enums/MemberSubjectType.php`
- Modify: `app/Enums/ContributionSubjectType.php`
- Modify: `app/Enums/DawahShareSubjectType.php`

- [ ] **Step 1: Update MemberSubjectType**

Change `case Speaker = 'speaker'` → `case Person = 'person'`. Update all methods: `modelClass()`, `publicRouteSegment()`, `claimableCases()`, etc.

- [ ] **Step 2: Update ContributionSubjectType**

Change `case Speaker = 'speaker'` → `case Person = 'person'`. Update related methods.

- [ ] **Step 3: Update DawahShareSubjectType**

Change `case Speaker = 'speaker'` → `case Person = 'person'`. Update related methods.

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/
git commit -m "refactor: update enums from Speaker to Person"
```

---

### Task 20: Rename Filament resources

**Files:**
- Rename: `app/Filament/Resources/Speakers/` → `app/Filament/Resources/Persons/`
- Rename: `app/Filament/Ahli/Resources/Speakers/` → `app/Filament/Ahli/Resources/Persons/`
- Modify: `app/Filament/Widgets/StatsOverview.php`
- Modify: `app/Filament/Pages/ModerationQueue.php`
- Modify: All other Filament files referencing Speaker

- [ ] **Step 1: Rename admin resource directory**

```bash
git mv app/Filament/Resources/Speakers app/Filament/Resources/Persons
```

Update all files inside:
- `SpeakerResource.php` → `PersonResource.php`
- `SpeakerForm.php` → `PersonForm.php`
- `SpeakersTable.php` → `PersonsTable.php`
- `CreateSpeaker.php` → `CreatePerson.php`
- `EditSpeaker.php` → `EditPerson.php`
- `ViewSpeaker.php` → `ViewPerson.php`
- `ListSpeakers.php` → `ListPersons.php`
- Update all class names, `$model` bindings, namespaces
- Rewrite `PersonForm` to use title/credential/affiliation relation managers instead of JSON fields

- [ ] **Step 2: Rename Ahli resource directory**

```bash
git mv app/Filament/Ahli/Resources/Speakers app/Filament/Ahli/Resources/Persons
```

Update all files similarly.

- [ ] **Step 3: Update other Filament files**

Update all references to `Speaker::class`, `SpeakerResource::class`, `$record->speakers`, morph type checks.

- [ ] **Step 4: Format, run tests, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/pest --parallel --filter=Admin 2>&1 | head -30
git add -A
git commit -m "refactor: rename Filament resources from Speakers to Persons"
```

---

### Task 21: Create Filament resources for Title, TitleIssuer, CredentialDefinition

**Files:**
- Create: `app/Filament/Resources/Titles/` (resource, form, table, pages)
- Create: `app/Filament/Resources/TitleIssuers/`
- Create: `app/Filament/Resources/CredentialDefinitions/`
- Create: Relation managers on PersonResource for titles, credentials, affiliations, names

- [ ] **Step 1: Create TitleResource**

```bash
php artisan make:filament-resource Title --no-interaction
```

Configure with: name, short_form, category (select), country (select), usage_position (select), sort_order, language_code.

- [ ] **Step 2: Create TitleIssuerResource**

```bash
php artisan make:filament-resource TitleIssuer --no-interaction
```

- [ ] **Step 3: Create CredentialDefinitionResource**

```bash
php artisan make:filament-resource CredentialDefinition --no-interaction
```

- [ ] **Step 4: Create relation managers on PersonResource**

```bash
php artisan make:filament-relation-manager PersonResource titleAssignments title
php artisan make:filament-relation-manager PersonResource credentialAssignments credential
php artisan make:filament-relation-manager PersonResource affiliations --table-columns=...
php artisan make:filament-relation-manager PersonResource names
```

- [ ] **Step 5: Format, test, commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat: add Filament resources for titles, issuers, credentials, relation managers"
```

---

### Task 22: Rename Livewire components

**Files:**
- Rename: `app/Livewire/Pages/Speakers/Show.php` → `app/Livewire/Pages/Persons/Show.php`
- Rename: `app/Livewire/Pages/Contributions/SubmitSpeaker.php` → `SubmitPerson.php`
- Modify: All other Livewire components referencing Speaker

- [ ] **Step 1: Rename and update Livewire pages**

```bash
git mv app/Livewire/Pages/Speakers app/Livewire/Pages/Persons
git mv app/Livewire/Pages/Contributions/SubmitSpeaker.php app/Livewire/Pages/Contributions/SubmitPerson.php
```

Update all class names, `public Speaker $speaker` → `public Person $person`, route references.

- [ ] **Step 2: Update all other Livewire components**

Update `Events/Index.php`, `Events/Show.php`, `Events/AdvancedFiltersPanel.php`, `SavedSearches/Index.php`, `Dashboard/*`, `Reports/Create.php`, `Membership/ShowInvitation.php`, `SubmitEvent/Create.php`.

Replace all `speaker_ids` → `person_ids`, `Speaker::class` → `Person::class`, etc.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "refactor: rename Livewire components from Speaker to Person"
```

---

### Task 23: Update routes

**Files:**
- Modify: `routes/web.php`
- Modify: `routes/api.php`
- Modify: `routes/console.php`

- [ ] **Step 1: Update route names**

In `routes/web.php`:
- `speakers.index` → `persons.index`
- `speakers.show` → `persons.show`
- `contributions.submit-speaker` → `contributions.submit-person`
- Keep URL segments: `/penceramah`, `/sumbang/penceramah`

In `routes/api.php`:
- Update route name groups: `speakers.*` → `persons.*`
- Update type-wherein: `'speaker'` → `'person'`

- [ ] **Step 2: Commit**

```bash
git add routes/
git commit -m "refactor: update route names from speakers to persons"
```

---

### Task 24: Rename API Data classes and controllers

**Files:**
- Rename: `app/Data/Api/Event/EventSpeakerData.php` → `EventPersonData.php`
- Rename: `app/Data/Api/Frontend/Search/SpeakerListData.php` → `PersonListData.php`
- Rename: All other `Speaker*Data.php` → `Person*Data.php`
- Modify: All API controllers referencing Speaker

- [ ] **Step 1: Rename all Data classes**

```bash
git mv app/Data/Api/Event/EventSpeakerData.php app/Data/Api/Event/EventPersonData.php
git mv app/Data/Api/Frontend/Search/SpeakerListData.php app/Data/Api/Frontend/Search/PersonListData.php
git mv app/Data/Api/Frontend/Search/SpeakerDetailData.php app/Data/Api/Frontend/Search/PersonDetailData.php
git mv app/Data/Api/Frontend/Search/SpeakerDetailMediaData.php app/Data/Api/Frontend/Search/PersonDetailMediaData.php
git mv app/Data/Api/Frontend/Search/SpeakerGalleryItemData.php app/Data/Api/Frontend/Search/PersonGalleryItemData.php
git mv app/Data/Api/Frontend/Search/SpeakerInstitutionData.php app/Data/Api/Frontend/Search/PersonInstitutionData.php
git mv app/Data/Api/Frontend/Search/EventListSpeakerData.php app/Data/Api/Frontend/Search/EventListPersonData.php
```

- [ ] **Step 2: Update all controllers**

Update all method names, `Speaker::class` references, table queries, morph type checks.

- [ ] **Step 3: Update API support services**

Update `AdminResourceRegistry`, `AdminResourceService`, `AdminResourceMutationService`, `FrontendCatalogService`, `FrontendFormContractService`, `ResourceSearchDispatcher`.

- [ ] **Step 4: Update API documentation generators**

Update `ApiDocumentation/` schemas.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor: rename API Data classes and controllers from Speaker to Person"
```

---

### Task 25: Rename Actions, Services, Support

**Files:**
- Rename: `app/Actions/Speakers/` → `app/Actions/Persons/`
- Rename: `app/Support/Search/SpeakerSearchService.php` → `PersonSearchService.php`
- Modify: All other actions/services/support referencing Speaker

- [ ] **Step 1: Rename actions**

```bash
git mv app/Actions/Speakers app/Actions/Persons
git mv app/Actions/Persons/GenerateSpeakerSlugAction.php app/Actions/Persons/GeneratePersonSlugAction.php
git mv app/Actions/Persons/SaveSpeakerAction.php app/Actions/Persons/SavePersonAction.php
```

- [ ] **Step 2: Rename search service**

```bash
git mv app/Support/Search/SpeakerSearchService.php app/Support/Search/PersonSearchService.php
```

- [ ] **Step 3: Rename console commands**

```bash
git mv app/Jobs/BackfillSpeakerSlugs.php app/Jobs/BackfillPersonSlugs.php
git mv app/Console/Commands/IndexSpeakersToTypesense.php app/Console/Commands/IndexPersonsToTypesense.php
git mv app/Console/Commands/ReindexSpeakerSearch.php app/Console/Commands/ReindexPersonSearch.php
git mv app/Console/Commands/QueueBackfillSpeakerSlugs.php app/Console/Commands/QueueBackfillPersonSlugs.php
```

- [ ] **Step 4: Update all service references**

Update `ContributionEntityMutationService`, `EventKeyPersonSyncService`, `EventSearchService`, `PostgresEventDiscovery`, `TypesenseEventDiscovery`, `CalendarService`, notifications, share tracking.

- [ ] **Step 5: Update support files**

Update `PublicSlugPathResolver`, `Submission/*`, `Authz/*`, `Events/OrganizerResolver`, `Cache/PublicDirectoryCacheVersion`.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "refactor: rename actions, services, support from Speaker to Person"
```

---

### Task 26: Rename MCP tools and resources

**Files:**
- Modify: `app/Mcp/Servers/*.php`
- Modify: `app/Mcp/Tools/Admin/Admin*EventTool.php`
- Modify: `app/Mcp/Tools/Admin/Admin*RecordTool.php`
- Modify: `app/Mcp/Tools/Member/*.php`
- Modify: `app/Support/Mcp/*.php`
- Modify: `app/Mcp/Resources/Docs/*.php`

- [ ] **Step 1: Update MCP event tools**

Rename `speaker_keys` → `person_keys` in all event creation/update tools. Update payload aliases, resolvers.

- [ ] **Step 2: Update MCP record tools**

Update resource registrations from `speakers` → `persons`.

- [ ] **Step 3: Update MCP support**

Rewrite `EventCoverPromptBuilder` — update payload map, reference keys, `speakerNames()` → `personNames()`, `nonSpeakerKeyPeopleLine()`.

Update `McpWriteSchemaFormatter` schema key descriptions.

- [ ] **Step 4: Update MCP server instructions and docs**

Update all instruction text mentioning "speakers" to "persons" in server definitions, prompt definitions, and guide resources.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor: update MCP tools from Speaker to Person"
```

---

### Task 27: Rename views, factories, seeders, tests, config

**Files:**
- Rename: `resources/views/livewire/pages/speakers/` → `persons/`
- Rename: `resources/views/components/pages/speakers/` → `persons/`
- Rename: `database/factories/SpeakerFactory.php` → `PersonFactory.php`
- Rename: `database/seeders/SpeakerSeeder.php` → `PersonSeeder.php`
- Rename: All test files with "Speaker" in name
- Modify: `config/scout.php`, `config/scramble.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: All blade views referencing speaker variables

- [ ] **Step 1: Rename view directories**

```bash
git mv resources/views/livewire/pages/speakers resources/views/livewire/pages/persons
git mv resources/views/components/pages/speakers resources/views/components/pages/persons
git mv resources/views/livewire/pages/contributions/submit-speaker.blade.php resources/views/livewire/pages/contributions/submit-person.blade.php
```

- [ ] **Step 2: Rename factory and seeder**

```bash
git mv database/factories/SpeakerFactory.php database/factories/PersonFactory.php
git mv database/seeders/SpeakerSeeder.php database/seeders/PersonSeeder.php
```

Update class names, `Speaker::class` → `Person::class`, remove JSON field generation, add title/credential/affiliation creation.

- [ ] **Step 3: Rename test files**

```bash
git mv tests/Feature/SpeakerIndexTest.php tests/Feature/PersonIndexTest.php
git mv tests/Feature/SpeakerFollowTest.php tests/Feature/PersonFollowTest.php
# ... continue for all ~10 test files
```

- [ ] **Step 4: Update config files**

In `config/scout.php`: update Typesense schema field names (`speaker_names` → `person_names`, `speaker_ids` → `person_ids`, `key_person_speaker_ids` → `key_person_person_ids`).

In `config/scramble.php`: update API doc groups/examples.

- [ ] **Step 5: Update all blade views**

Replace `$speaker` → `$person`, `speakers.show` → `persons.show`, `speakers.index` → `persons.index` across all views. Keep visible label "Penceramah".

- [ ] **Step 6: Rename placeholder images**

```bash
git mv public/images/placeholders/speaker.png public/images/placeholders/person.png
git mv public/images/placeholders/speaker-female.png public/images/placeholders/person-female.png
git mv public/images/placeholders/speaker-male.png public/images/placeholders/person-male.png
```

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor: rename views, factories, seeders, tests, config from Speaker to Person"
```

---

## Phase 7: Cleanup

### Task 28: Final cleanup and full test run

**Files:**
- Modify: `docs/ilmu360_mobile_api_reference.md`
- Modify: `docs/ilmu360_review_and_enhancement_plan.md`
- Modify: All remaining references
- Run: Media path migration
- Run: Full test suite
- Run: PHPStan
- Run: Pint

- [ ] **Step 1: Search for any remaining `speaker` references**

```bash
rg -n "Speaker::class|speaker_id|speaker_members|speaker_search|SpeakerFactory|SpeakerResource|SpeakerSeeder|app\\\\Models\\\\Speaker|'speaker'" app/ database/ tests/ routes/ resources/views/ config/ --glob '!**/storage/**' --glob '!**/vendor/**' --glob '!**/node_modules/**'
```

Fix any remaining references found.

- [ ] **Step 2: Update non-code docs**

Update API reference docs, review plan docs, MCP guide resources.

- [ ] **Step 3: Run media path migration**

```bash
php artisan app:media:migrate-structure --force --no-interaction
```

- [ ] **Step 4: Rebuild search index**

```bash
php artisan scout:flush "App\Models\Person"
php artisan scout:import "App\Models\Person"
```

- [ ] **Step 5: Run PHPStan**

```bash
vendor/bin/phpstan analyse --ansi
```

Fix any errors found.

- [ ] **Step 6: Run Pint**

```bash
vendor/bin/pint --format agent
```

- [ ] **Step 7: Run full test suite**

```bash
vendor/bin/pest --parallel
```

Fix any failing tests. All tests should pass.

- [ ] **Step 8: Verify forbidden patterns**

```bash
# No legacy speaker references in app code
rg -n "Speaker::class|speaker_members|speaker_search_terms|SpeakerFactory|SpeakerResource" app/ database/ tests/ --glob '!**/storage/**'

# No deleted enum references
rg -n "App\\\\Enums\\\\Honorific|App\\\\Enums\\\\PreNominal|App\\\\Enums\\\\PostNominal" app/ database/ tests/
```

Both should return empty.

- [ ] **Step 9: Final commit**

```bash
git add -A
git commit -m "chore: final cleanup, verify no remaining Speaker references"
```

---

## Self-Review Checklist

After all tasks are complete, verify:

- [ ] All 9 new tables exist with correct schema
- [ ] All 9 new models exist with correct relationships
- [ ] All 6 new enums exist
- [ ] 3 old enums deleted (`Honorific`, `PreNominal`, `PostNominal`)
- [ ] `speakers` table renamed to `persons`
- [ ] `speaker_members` → `person_members`
- [ ] `speaker_search_terms` → `person_search_terms`
- [ ] `institution_speaker` dropped, replaced by `affiliations`
- [ ] All morph aliases updated: `'speaker'` → `'person'`
- [ ] `EventKeyPersonRole::Speaker` stays unchanged
- [ ] `requires_speaker` policy code stays unchanged
- [ ] JSON columns (`honorific`, `pre_nominal`, `post_nominal`, `qualifications`, `job_title`, `is_freelance`) dropped
- [ ] New columns (`family_name`, `date_of_birth`, `nationality_country_id`) added
- [ ] `formatted_name` reads from `title_assignments`
- [ ] Route names renamed to `persons.*`
- [ ] Public URL segment `/penceramah` preserved
- [ ] Public-facing label "Penceramah" preserved
- [ ] Media path prefix updated from `speakers/` to `persons/`
- [ ] Typesense fields renamed
- [ ] Cache keys renamed
- [ ] All tests pass
- [ ] PHPStan passes level 6
- [ ] Pint formatting clean
