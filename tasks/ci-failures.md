# CI Failure Report - Run 30243851958

## Rector (7 files)
1. `app/Forms/SharedFormSchema.php:1088` - RemoveUnusedForeachKeyRector
2. `app/Http/Controllers/Api/Frontend/SearchController.php` - RemoveUnusedVariableAssignRector, RemoveUnusedPrivateMethodParameterRector
3. `app/Observers/AddressAreaObserver.php:48` - RemoveUnusedPrivateMethodParameterRector
4. `database/seeders/TitleSeeder.php:104` - RemoveConcatAutocastRector
5. `app/Support/Location/AddressAssignments.php:10` - AddTypeToConstRector
6. `database/seeders/AddressingSeeder.php:65` - NewMethodCallWithoutParenthesesRector
7. `tests/Feature/ProductionSeederTest.php:95` - NewMethodCallWithoutParenthesesRector

## Pest Failures

### Shard 5 - EventSearchTest (5 failed)
- 5× MassAssignmentException (admin_area_* on Address)

### Shard 1 - FrontendApiParityTest (5 failed)
- 4× MassAssignmentException
- 1× ValidationException

### Shard 9 - GeographyDeletionRulesTest, PersonSlugGenerationTest (4 failed)
- 2× GeographyDeletionRulesTest (ValidationException)
- 2× PersonSlugGenerationTest

### Shard 6 - AdminApiTest (3 failed)

### Shard 12 - PublicPagesTest (1 failed)
- 1× MassAssignmentException

### Shard 20 - CatalogApiTest, InstitutionContributionLocationPickerTest (2 failed)
- 1× CatalogApiTest (RouteNotFoundException)
- 1× InstitutionContributionLocationPickerTest (ViewException)

### Shard 13 - SavedSearchPageTest, EventApiContractTest, EventSearchTypesenseFilterTest (4 failed)
- 2× SavedSearchPageTest (MassAssignmentException)
- 1× EventApiContractTest (ValidationException)
- 1× EventSearchTypesenseFilterTest

### Shard 16 - InstitutionSlugGenerationTest, ScrambleDocsTest, SavedSearchApiTest (13 failed)
- 3× InstitutionSlugGenerationTest (MassAssignmentException)
- 1× ScrambleDocsTest (InvalidExpectationValue)
- 9× InstitutionSlugGenerationTest (other)
- 1× SavedSearchApiTest

### Shard 18 - PersonShowPageTimingTest, VenueIndexTest (2 failed)
- 1× PersonShowPageTimingTest (MassAssignmentException)
- 1× VenueIndexTest

### Shard 19 - InstitutionIndexTest, GeneratedFileFinalFixedPoskodSeederTest (3 failed)
- 2× InstitutionIndexTest (ValidationException)
- 1× GeneratedFileFinalFixedPoskodSeederTest (ValidationException)

### Shard 14 - InstitutionShowPageTest (2 failed)
- 1× InstitutionShowPageTest (MassAssignmentException)
- 1× InstitutionShowPageTest (ValidationException)
