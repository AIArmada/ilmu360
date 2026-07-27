# CI Run 30254354762 - All Failures

## 1. Pint (check) - 2 style issues
- `app/Observers/AddressAreaObserver.php`: unary_operator_spaces, braces_position
- `tests/Pest.php`: array_indentation, unary_operator_spaces, not_operator_with_successor_space

## 2. Shard 1/20 - FrontendApiParityTest (3 failures)
- Tests\Feature\Api\Frontend\FrontendApiParityTest (lines 695, 735, 776)
- Error: ValidationException: "The selected area must belong to the selected State / Federal Territory."
- Area validation rejects selected district/subdistrict for the chosen state

## 3. Shard 6/20 - AdminApiTest (3 failures)
- **Test: it exposes admin person write schema** (line 1725)
  - Failed asserting that an array contains 'address.administrative_district_id'
  - The schema catalogs field list no longer contains administrative_district_id

- **Test: it returns fresh person address data** (line 1819)
  - Failed asserting that null is identical to a UUID
  - The response JSON path `address.administrative_district_id` is null but fixture expects UUID

- **Test: it lists admin geography catalogs** (line 2669)
  - Expected 200 but received 404
  - Route `/api/v1/admin/catalogs/admin-area-level-1` returns 404

## 4. Shard 12/20 - PublicPagesTest (1 failure)
- **Test: it shows federal territory public pages correctly** (line 263)
  - ValidationException: "The selected area requires its parent hierarchy level to be selected first."
  - Federal territory validation requires parent hierarchy before child area
