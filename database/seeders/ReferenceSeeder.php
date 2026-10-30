<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\References\Enums\ReferenceContributorRole;
use AIArmada\References\Enums\ReferenceRecordKind;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Actions\References\GenerateReferenceSlugAction;
use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Enums\SpeakerStatus;
use App\Models\Person;
use App\Models\Reference;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seeds the production references (and their authors and cover media) from
 * data embedded in this file. No connection to the production database is
 * required.
 *
 * Production stores authors as free-text (comma-separated); local stores
 * them as Person records linked via `reference_contributors`. Authors can
 * only be stored on works: parts inherit them from their parent work.
 *
 * Cover/gallery rows are imported as metadata only; the files themselves
 * stay on the production S3 bucket.
 *
 * Usage: php artisan db:seed --class=ReferenceSeeder
 */
class ReferenceSeeder extends Seeder
{
    private const array REFERENCES = [
        [
            'id' => '019d477a-623c-7225-95db-a665a368fc61',
            'title' => 'Idaman Penuntut - Pada Menghuraikan Yang Kusut (Ilmu Fiqah)',
            'slug' => 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah',
            'author' => 'Haji Abdul Ghoni Bin Haji Yahya',
            'created_at' => '2026-04-01 05:18:16',
            'updated_at' => '2026-04-06 09:14:25',
            'media' => [
                [
                    'source_media_id' => 52,
                    'uuid' => '486b1cf5-061b-4fb3-8fb5-6bb6594eb681',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Idaman Penuntut Pada Menghuraikan Yang Kusut (Ilmu Fiqah)',
                    'file_name' => 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 118992,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'IdamanPenuntut-01.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_525_733.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_439_613.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_367_512.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_307_429.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_257_359.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_215_300.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_180_251.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNTI1IDczMyI+Cgk8aW1hZ2Ugd2lkdGg9IjUyNSIgaGVpZ2h0PSI3MzMiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxRQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTlJdTlWTWR3WWtYSnFzK3JYSzlJVFRRdWRXYmlwYmp6Vlk0QXhYeVRTVFBWYmpGTFFTTFY3aGp6RWFrVFUya3VCR3k0SnFHRnBKTVlBNjFHNkZkVFg2VTBrOUxEanl5ZXhJaEoxY2lyVjFaeVB5R05WOFkxVWtkYXZTTkxuc0JVeTNJbjBJTFN5WlNDU2VLcnprRFZWRmFDdEtlRklOWmt5Ti9hcWx1dFZEZlVkUGNWMzhyVlN6ZEtzM0YzQy84QXkweFZ5ZXppZHl4SE5RSFRvRzZyVTNUWTdwclVpdHJxQ05zK1lUVlNXVVRhcXJMMHJSVFRyY0hoYWxXMGlqTzVWNXBxU1FrNHhlaC8vOWs9Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 09:22:29',
                    'updated_at' => '2026-10-24 19:00:45',
                ],
                [
                    'source_media_id' => 53,
                    'uuid' => '33da5dd2-42af-4619-88f4-29c582e68400',
                    'collection_name' => 'gallery',
                    'name' => 'Gallery Image - Idaman Penuntut Pada Menghuraikan Yang Kusut (Ilmu Fiqah)',
                    'file_name' => 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 59839,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'gallery', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'default.jpg'],
                    'generated_conversions' => ['gallery_thumb' => false],
                    'responsive_images' => ['media_library_original' => ['urls' => ['idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_360_498.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_301_416.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_251_347.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_210_291.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh1kh___media_library_original_176_243.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMzYwIDQ5OCI+Cgk8aW1hZ2Ugd2lkdGg9IjM2MCIgaGVpZ2h0PSI0OTgiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxBQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QXlZWW8xaDN2Uzc0TVVFWnRRQlVLMnBJem12QzlEOUlTVHUyeVhNQnF6SGJJUUhXcVgyZmJrazlLMHJZZ1FxS2NkOVJUMFdqS2JjV3dwWXBFeDFwSFAraWlvRWpKR1JTNmpTVFdwTExJcDZWYWdiOTB0VUdUSE5Yb0I4aTAxdUVrcklnWUUydkFxS0JuSHk0cTFhbk1lRDBxd3FqMEZQbHZxUTZuTGVOak5rTGxzWXEvQ3VJMXpVdTFjOUJRZnZBVUtOaGUwNXRMSC8vWiI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 2,
                    'created_at' => '2026-04-06 09:22:29',
                    'updated_at' => '2026-10-25 18:30:05',
                ],
                [
                    'source_media_id' => 64,
                    'uuid' => '73ee3ccd-bc8d-4228-8246-cad894fa3884',
                    'collection_name' => 'gallery',
                    'name' => 'Gallery Image - Idaman Penuntut Pada Menghuraikan Yang Kusut (Ilmu Fiqah)',
                    'file_name' => 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh3sn.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 59839,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'gallery', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'default.jpg'],
                    'generated_conversions' => ['gallery_thumb' => false],
                    'responsive_images' => ['media_library_original' => ['urls' => ['idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh3sn___media_library_original_360_498.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh3sn___media_library_original_301_416.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh3sn___media_library_original_251_347.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh3sn___media_library_original_210_291.jpg', 'idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah-01knh3sn___media_library_original_176_243.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMzYwIDQ5OCI+Cgk8aW1hZ2Ugd2lkdGg9IjM2MCIgaGVpZ2h0PSI0OTgiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxBQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QXlZWW8xaDN2Uzc0TVVFWnRRQlVLMnBJem12QzlEOUlTVHUyeVhNQnF6SGJJUUhXcVgyZmJrazlLMHJZZ1FxS2NkOVJUMFdqS2JjV3dwWXBFeDFwSFAraWlvRWpKR1JTNmpTVFdwTExJcDZWYWdiOTB0VUdUSE5Yb0I4aTAxdUVrcklnWUUydkFxS0JuSHk0cTFhbk1lRDBxd3FqMEZQbHZxUTZuTGVOak5rTGxzWXEvQ3VJMXpVdTFjOUJRZnZBVUtOaGUwNXRMSC8vWiI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 3,
                    'created_at' => '2026-04-06 10:00:47',
                    'updated_at' => '2026-10-25 18:30:09',
                ],
            ],
        ],
        [
            'id' => '019d6248-52c4-72ee-afc9-2eb829fb6998',
            'title' => 'Al-Adab Al-Mufrad',
            'slug' => 'al-adab-al-mufrad',
            'author' => 'Imam Al-Bukhari',
            'description' => 'Karya agung Imam Al-Bukhari yang menghimpunkan hadis-hadis berkaitan adab, akhlak, hubungan kekeluargaan, serta muamalah sesama manusia. Kitab ini menjadi rujukan penting dalam membentuk peribadi Muslim yang berakhlak mulia, dengan susunan yang mudah diikuti dan sarat dengan panduan praktikal untuk kehidupan seharian.',
            'created_at' => '2026-04-06 10:13:20',
            'updated_at' => '2026-04-20 02:47:06',
            'media' => [
                [
                    'source_media_id' => 65,
                    'uuid' => 'f85046bb-c2b2-4b42-84c0-aa301e896fc1',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Al Adab Al Mufrad',
                    'file_name' => 'al-adab-al-mufrad-01knh4gm.jpeg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 154719,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'adab-al-mufrad-jawzi-95-v1.jpeg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['al-adab-al-mufrad-01knh4gm___media_library_original_425_641.jpeg', 'al-adab-al-mufrad-01knh4gm___media_library_original_355_535.jpeg', 'al-adab-al-mufrad-01knh4gm___media_library_original_297_448.jpeg', 'al-adab-al-mufrad-01knh4gm___media_library_original_248_374.jpeg', 'al-adab-al-mufrad-01knh4gm___media_library_original_208_314.jpeg', 'al-adab-al-mufrad-01knh4gm___media_library_original_174_262.jpeg', 'al-adab-al-mufrad-01knh4gm___media_library_original_145_219.jpeg', 'al-adab-al-mufrad-01knh4gm___media_library_original_121_182.jpeg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNDI1IDY0MSI+Cgk8aW1hZ2Ugd2lkdGg9IjQyNSIgaGVpZ2h0PSI2NDEiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQU1BQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTg4ODRvTVZESmZCVHlhanVKQ01BZDZoRnJ2NU5acXkzT21NYm1qQmZCK2hxdzA1SXJMUzJFZkk0eFZpR1Rka2VsRHQwSEtGdFNyZUtUZ3IxcUNPYVVIR2FmZXpDS1JjOUtpU2VNbklJcWxzS0V1aEs4MGg0elZtelVnRW5yVlV6eGdna2lwYk9iekpEZzhVUFljNTlDbnFvSnhWR0pRT1NhMzViVHp4akZRalN0dmFoU1ZqSnh1ektkVkl5RFdocEtubmlySTBzSHRWdUcxOGxjQVVuSldzQ2pabi8yUT09Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 10:13:20',
                    'updated_at' => '2026-10-24 19:00:12',
                ],
            ],
        ],
        [
            'id' => '019d624c-8c57-7227-93bf-55d984dfb557',
            'title' => 'Matla\' Al-Badrain Wa Majma\' Al-Bahrain',
            'slug' => 'matla-al-badrain-wa-majma-al-bahrain',
            'author' => 'Syeikh Muhammad bin Ismail Daudi al-Fathani',
            'created_at' => '2026-04-06 10:17:57',
            'updated_at' => '2026-04-06 10:17:57',
            'media' => [
                [
                    'source_media_id' => 66,
                    'uuid' => '13cb2195-774b-4389-ab63-032749392c5c',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Matla\' Al Badrain Wa Majma\' Al Bahrain',
                    'file_name' => 'matla-al-badrain-wa-majma-al-bahrain-01knh4s3.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 34768,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '653703190_26761835840087764_7055709449658931098_n-v1.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['matla-al-badrain-wa-majma-al-bahrain-01knh4s3___media_library_original_292_410.jpg', 'matla-al-badrain-wa-majma-al-bahrain-01knh4s3___media_library_original_244_343.jpg', 'matla-al-badrain-wa-majma-al-bahrain-01knh4s3___media_library_original_204_286.jpg', 'matla-al-badrain-wa-majma-al-bahrain-01knh4s3___media_library_original_171_240.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMjkyIDQxMCI+Cgk8aW1hZ2Ugd2lkdGg9IjI5MiIgaGVpZ2h0PSI0MTAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxRQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTZTNzFTNmltS0lLUzN1TDJWdDBod3RhU1dIblhEU012QXB0eEVWYkFHQUtwelNWa2FPS2VpT2QxYTUxQ0Fsb2p1WDJySHNOZHU1YjBSeUhGZGMwWWtmWVFNR3NHOTBaYmJVbG1RY0dyaktMVm1pT1ZiTTc2R2VOZ2R0VUw2WEdlS3JXRnlqQmdHNUZOdkxsU0NLd3RZY3BXbVF4UGw4NHF2cWtxNVdtRzhDakFySDFTOUc5UnU1cWtKU3ZPNW14ZUlHc2RTSUxmSVRXNDJ0VzkxR0NqREpybHJ6VG9wSlN4SnpWYUszOGgva2RxMW55eVJMYVp2WG1zUVd5TVdZWkZjazJzdGU2bndmbEhTblh0cUo1Q1hkcWd0TENLS2ZjdWMwa2xGQkZvLzlrPSI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 10:17:57',
                    'updated_at' => '2026-10-24 19:01:47',
                ],
            ],
        ],
        [
            'id' => '019d6251-d236-7343-995b-c19788e40b00',
            'title' => 'Kiamat Sudah Dekat',
            'slug' => 'kiamat-sudah-dekat',
            'author' => 'Dr. Yusuf bin Abdillah bin Yusuf Al-Wabil',
            'created_at' => '2026-04-06 10:23:42',
            'updated_at' => '2026-04-06 10:27:07',
            'media' => [
                [
                    'source_media_id' => 67,
                    'uuid' => '56bcc670-814b-466e-b54d-bc4d908caefa',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Kiamat Sudah Dekat',
                    'file_name' => 'kiamat-sudah-dekat-01knh59w.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 23942,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '26e9d21913b877f5383827d65b5a9732-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['kiamat-sudah-dekat-01knh59w___media_library_original_313_418.webp', 'kiamat-sudah-dekat-01knh59w___media_library_original_261_349.webp', 'kiamat-sudah-dekat-01knh59w___media_library_original_219_292.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMzEzIDQxOCI+Cgk8aW1hZ2Ugd2lkdGg9IjMxMyIgaGVpZ2h0PSI0MTgiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUt3QWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTNmR2RsSk5xcnVPd3J6MjYxUzcwK1VoQXd4WGVlTjlWZURXVEVuZXVaVlk3dUpqTWczZlN0L1lTY09aTXRRVnJzNS8vQUlUUFZsYkNPUlRKUGlicnRrMndPVFZ1NTBnUnMwZzI0N0N1TXYwZHJwd1V6ZzFuN0tWOTBWeUo3WFBZZkc1UStLVkRzQU05NnJtMWhrYmJIS29CSFkxbGZGTjNUeEdDcmtHcUdpQ1dRcVdsSi9HdWF0aVhUalkzb05hSTZCOUZWeDg5eHg5YXBUK0c3QlYzTkl1NnJWOFd0a0RsampGYzlQckFNaERjaXZObFZuVmplTFo2Vkp5VDkzWTF2aW9FZlhzZENLeE5GbGloaUxHWDVoMEZhbnhMK2JYem5tc1BTb2thUmNxRFhiWHBjK2x6enFGVlJzckdsZlg5eFBBVndXOUs1TzRodlRLU0kyL0t2WGJHeXRtaFRNS0hqMHJUaTB5eVorYmFNL2hXVkdueTZJOUN2V1hMeXBXUC85az0iPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 10:27:07',
                    'updated_at' => '2026-10-24 19:00:39',
                ],
            ],
        ],
        [
            'id' => '019d6259-49c3-7370-ac87-9b768e71d48a',
            'title' => 'Penawar Bagi Hati',
            'slug' => 'penawar-bagi-hati',
            'author' => 'Al-Sheikh \'Abdul Qadir Ibn \'Abdul Mutholib Al-Indunisi Al-Mandili rhm.',
            'created_at' => '2026-04-06 10:31:52',
            'updated_at' => '2026-04-06 10:31:52',
            'media' => [
                [
                    'source_media_id' => 68,
                    'uuid' => '5a204a5b-17a5-4516-a81b-0d7e6bc22b97',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Penawar Bagi Hati',
                    'file_name' => 'penawar-bagi-hati-01knh5jj.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 138565,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '494313096_2479855845705007_8928016244097928890_n.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['penawar-bagi-hati-01knh5jj___media_library_original_672_960.jpg', 'penawar-bagi-hati-01knh5jj___media_library_original_562_803.jpg', 'penawar-bagi-hati-01knh5jj___media_library_original_470_671.jpg', 'penawar-bagi-hati-01knh5jj___media_library_original_393_561.jpg', 'penawar-bagi-hati-01knh5jj___media_library_original_329_470.jpg', 'penawar-bagi-hati-01knh5jj___media_library_original_275_393.jpg', 'penawar-bagi-hati-01knh5jj___media_library_original_230_329.jpg', 'penawar-bagi-hati-01knh5jj___media_library_original_192_274.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNjcyIDk2MCI+Cgk8aW1hZ2Ugd2lkdGg9IjY3MiIgaGVpZ2h0PSI5NjAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxnQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTUvUzlNZ3VyY3lTbkdLMHJiUUxhNWsyUjVKcXZva2JUV1d4UmtrMTZQcGRuRlkya2JDSE1oSEp4VHExSnFiU1piYWlsb2NGZGVIN2UwZmJJR0ZVci9TNElMWHpZam12UzlTdDB2b0h6QjgrT0RpdUIxU0pyZXpkSEJHRFJUcVQ1MWRnbW1ub1gvQUVTU2tiaG5tdlVaWUJ0WGJnQUN2TWZoNk04RDFyMHFhTjFVSEp4V05mK0t3bjBKSUlsQ25kZzE1NTQ3aVNORzJnREpydllrZHVoT0s0UHg1eEdSU3BmeEVFT3BsK0JwakRHWEhZMTNyNnl6S0ZOZU9hWnJFdW5SbFl4a1ZvRHhYY24rR3RhK0dxeXFPVWRqUzhXdFQxSmRZWkFSWEhlTDdqN1JDWE5ZQThVM0pQM2FyMytyeTNrT3h4VTBzTlZqVVVuc0Y0cGFILy8yUT09Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 10:31:52',
                    'updated_at' => '2026-10-24 19:01:13',
                ],
            ],
        ],
        [
            'id' => '019d629c-8bff-7087-8fe8-08ca51148293',
            'title' => 'Fiqh Imam Makmum',
            'slug' => 'fiqh-imam-makmum',
            'author' => 'Prof Dr Basri Ibrahim Al-Azhari',
            'publisher' => 'PTS',
            'created_at' => '2026-04-06 11:45:19',
            'updated_at' => '2026-04-06 11:45:19',
            'media' => [
                [
                    'source_media_id' => 82,
                    'uuid' => '9f0b9c4f-0c61-4e77-b6c0-50a3b44f92e3',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Fiqh Imam Makmum',
                    'file_name' => 'fiqh-imam-makmum-01knh9s3.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 42294,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'c3c44cdcf2bd0575ecb26204d215c196-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['fiqh-imam-makmum-01knh9s3___media_library_original_658_1000.webp', 'fiqh-imam-makmum-01knh9s3___media_library_original_550_836.webp', 'fiqh-imam-makmum-01knh9s3___media_library_original_460_699.webp', 'fiqh-imam-makmum-01knh9s3___media_library_original_385_585.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNjU4IDEwMDAiPgoJPGltYWdlIHdpZHRoPSI2NTgiIGhlaWdodD0iMTAwMCIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBTVFBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBOWdubVdHTXU1d0FNbXVHMWo0Z1JXY2pMQkdaQXA1SXJYOGEzRXR2b0U3Ulp6dDdWNUo0ZjFTemV5bWl1aUdsWnU5YUpXanpHdEdtcGFzOUs4UGVQN1RWcFJFNDh0ejB6WFlDUjJjRmZ1bXZETlYrejJFMW5KWjRWeXd6aXZhTkRsYWJTNEhmN3hVVW1rMXpJS3NGRFZFbXFXQ2FoWlNRU0RoaGl2RXRVOEZYZWo2bTgwRVpralk1QUZlNzNMQ09GbVBZVnlzMnNXcnlGSDVJOWFoMVp3K0ZYQ2c3ZFRnOUU4SlgycTZoRlBkcVVpUTVBTmV4MmNDMjl1a1M5RkdLNXEzMWkzRWlvbkdUMnJxb1NIaVZoM0ZLTldkVDRvMkN2NmpibFBOaFpQVVZ5VTNoOWpJeDQ1TmRiS3pEb00xV2JjVDkyclRhMk1ZdG81MjIwQXh5cWVPRFhYUUxzaVZmUVZUVGNPZHRXb21MRGtZb2JiM0NUYkhQVVpvb3BDRkZQU2lpZ0QvL1oiPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 11:45:19',
                    'updated_at' => '2026-10-24 19:01:41',
                ],
                [
                    'source_media_id' => 83,
                    'uuid' => '5abe6f94-6a6c-43e9-bd43-073be94f25ea',
                    'collection_name' => 'back_cover',
                    'name' => 'Back Cover - Fiqh Imam Makmum',
                    'file_name' => 'fiqh-imam-makmum-01knh9s3.jpeg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 11484,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'back_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'images (3).jpeg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['fiqh-imam-makmum-01knh9s3___media_library_original_184_274.jpeg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMTg0IDI3NCI+Cgk8aW1hZ2Ugd2lkdGg9IjE4NCIgaGVpZ2h0PSIyNzQiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQU1BQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTlBOFJlSmpwTTZ4S21jMHRuNGxGeEVqYlNDYTBOVjBpMHYzVnAwQklwa1duMmNTcXF4QUFWRWxjYWFYUW5OK2RnT090WGJXVVRMbkZWakZFUU9CeFZ1MkNqaFJUSk9jOFJlSnJUUjVBc3g1TllCK0llbURxMVlueFBnbGx2WXlpbGg3VjV2SlpUNS8xVC9sV2lpbWlsRldQWkI4UmRLUFY2Nkh3NTRwc2RZbU1kdTJTSytjWHM3a054RS81VjZSOEtJSm90U1pwRVpSNzAzQldHNHF3VCtKSk5UY2w0dDJQVVVzUVc0d1BMVU1lMks5TFR3allSWEdFdDFBK2xUM25oaXpoaEVrTUkzMUdoTmpnckxUMFE3NW9WS2oycDl6cTYyTWdGakNFUGNnVjMxaHBFY3NKV2FJVThlR0xCbnlZUlNWN2o2SC8yUT09Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 2,
                    'created_at' => '2026-04-06 11:45:20',
                    'updated_at' => '2026-04-06 11:45:21',
                ],
            ],
        ],
        [
            'id' => '019d62b7-8533-7177-848a-8708da8ff68c',
            'title' => '40 Hadith Perihal Zaman Kini',
            'slug' => '40-hadith-perihal-zaman-kini',
            'author' => 'Al-Allamah Al-Muhaddith ‘Abd Al-‘Aziz bin Al-Siddiq Al-Ghumari Al-Hasani',
            'created_at' => '2026-04-06 12:14:47',
            'updated_at' => '2026-04-06 12:14:47',
            'media' => [
                [
                    'source_media_id' => 87,
                    'uuid' => 'e31592c4-0bca-48c2-bd8a-ebe13c8e3a48',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - 40 Hadith Perihal Zaman Kini',
                    'file_name' => '40-hadith-perihal-zaman-kini-01knhbf1.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 210530,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '3DqcnWa23h6QFu6TpP4pM7HP5WLmDlFPrEQ2DsU3.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['40-hadith-perihal-zaman-kini-01knhbf1___media_library_original_901_1280.jpg', '40-hadith-perihal-zaman-kini-01knhbf1___media_library_original_753_1070.jpg', '40-hadith-perihal-zaman-kini-01knhbf1___media_library_original_630_895.jpg', '40-hadith-perihal-zaman-kini-01knhbf1___media_library_original_527_749.jpg', '40-hadith-perihal-zaman-kini-01knhbf1___media_library_original_441_627.jpg', '40-hadith-perihal-zaman-kini-01knhbf1___media_library_original_369_524.jpg', '40-hadith-perihal-zaman-kini-01knhbf1___media_library_original_309_439.jpg', '40-hadith-perihal-zaman-kini-01knhbf1___media_library_original_258_367.jpg', '40-hadith-perihal-zaman-kini-01knhbf1___media_library_original_216_307.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgOTAxIDEyODAiPgoJPGltYWdlIHdpZHRoPSI5MDEiIGhlaWdodD0iMTI4MCIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBTFFBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBOWhDT3c0RlF6SzRISEZYVHFka2k0RHIrZFpzOTVic1NmT0dENzF3UnkrRnRXZERyeTZJV0ZXWi9tUEZiVnZib3FBam11ZE56QmtFU2o4NjE0ZFR0NDRCbHh4NzEwVXNOVHBQVGN5cVZLazBlR1FlSkh1UU4xeGpQdlQ5VDF0cmEyVmhjRmlmUTE1ZUxwMWNBT1JYUjZQQXQvd0FTU0Z3UFUxc3FMaTczME5wNGlNbzJ0cWRYcG11VGFoS2theWtINjF0NnBxTjVaMjIwbGp4MUZZbGhhMlZyTXBEaFdIdlhSdmYyRnlvamtJTzBkVFNkRG5tdGJJaDRuMlVPWnE1NGZKWU96K1dvTzZyTmt1b2FmTUk0MVlrKzFlelduaHJUdk9NaGhCUDByUS9zSFR3NGY3T3VSM3hYSlBNNFJueVdMV0ZiamM4amdzOVZlNldlUlcyRHFLM0xhVnI2WDdOSEN3Zm9UWHBBc0xiYmp5bHg5S2RiYVphUXliNDRWRGV1S0ttYVUrV3lRbzRTVGVyUC85az0iPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 12:14:47',
                    'updated_at' => '2026-10-24 19:00:11',
                ],
            ],
        ],
        [
            'id' => '019d62bf-4f0c-7298-9f92-c0dfe3fdb712',
            'title' => 'Syarah Burdah',
            'slug' => 'syarah-burdah',
            'author' => 'Imam Ibnu Marzuq Al Hafid At Tilimsani Al Maliki',
            'created_at' => '2026-04-06 12:23:18',
            'updated_at' => '2026-04-06 12:23:18',
            'media' => [
                [
                    'source_media_id' => 88,
                    'uuid' => '2a9159d4-97a7-47c0-804a-fd5103ecf97c',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Syarah Burdah',
                    'file_name' => 'syarah-burdah-01knhbyk.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 93868,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['syarah-burdah-01knhbyk___media_library_original_768_1024.webp', 'syarah-burdah-01knhbyk___media_library_original_642_856.webp', 'syarah-burdah-01knhbyk___media_library_original_537_716.webp', 'syarah-burdah-01knhbyk___media_library_original_449_599.webp', 'syarah-burdah-01knhbyk___media_library_original_376_501.webp', 'syarah-burdah-01knhbyk___media_library_original_314_419.webp', 'syarah-burdah-01knhbyk___media_library_original_263_351.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNzY4IDEwMjQiPgoJPGltYWdlIHdpZHRoPSI3NjgiIGhlaWdodD0iMTAyNCIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBS3dBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBNWVlVVJSakE1cXY1NzR6aXBwaW0wYnFhdmw0eldTMkxZVzgrNlREQ3RBaEN0VUVhSVNBRHJWb0dsTGNjZGpPdnVpMUFvYlo5NnBiL0FPNnRWMEdWNWF0VjhKRDNIUUUvYUJrMXJxZUt4NE9MZ2MxckRwVVZOeW9iTXBYeUZvMUlGVkVWdHYzYTB4ekVNODAwQVk2VUtWbFlUaVViV05qY0RpdGpZUU1tb0l3QWNnVk03SFoxcVpPN0tpckkvOWs9Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 12:23:18',
                    'updated_at' => '2026-10-24 19:00:12',
                ],
            ],
        ],
        [
            'id' => '019d631b-8660-73a4-8ab1-d6d7682d2c0a',
            'title' => 'Al-Fiqh Al-Manhaji',
            'slug' => 'al-fiqh-al-manhaji',
            'author' => 'Ashab Al-Fadhilah Dr Mustafa al-Khin, Dr Mustafa Al-Bugha, Dr Ali Al-Syarbaji',
            'created_at' => '2026-04-06 14:04:01',
            'updated_at' => '2026-04-06 14:04:01',
            'media' => [
                [
                    'source_media_id' => 91,
                    'uuid' => '3a57f28c-1e15-4c02-9ba1-d9371c8caa35',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Al Fiqh Al Manhaji',
                    'file_name' => 'al-fiqh-al-manhaji-01knhhq1.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 80452,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'e69f321c2a850f78a00a08302ce978b6-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['al-fiqh-al-manhaji-01knhhq1___media_library_original_466_688.webp', 'al-fiqh-al-manhaji-01knhhq1___media_library_original_389_574.webp', 'al-fiqh-al-manhaji-01knhhq1___media_library_original_326_481.webp', 'al-fiqh-al-manhaji-01knhhq1___media_library_original_272_402.webp', 'al-fiqh-al-manhaji-01knhhq1___media_library_original_228_337.webp', 'al-fiqh-al-manhaji-01knhhq1___media_library_original_191_282.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNDY2IDY4OCI+Cgk8aW1hZ2Ugd2lkdGg9IjQ2NiIgaGVpZ2h0PSI2ODgiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUx3QWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTVXSzNRUUJ5dFJlWkNEL3F6VnRXQzJHVDBxdEZjV3Uza2dtdUZ5WjFLS0hKTkZrRHl6Vm1XTkRiRmd0VkRkMjRQYXJqeUJySEs5S0ZKM0J4UlRsa1A5bU1QYXVVV2FSWlRqUFd1eW10OGFVemUxY1hOTzBUSENkNjFwOVRLVDJKdk5rYVFaejFycVlYSjA5VjlxNUdDNU1qQWJPOWRuYVJBMkN0N1VUNlhDSmRaUTJqa2Q4VngwdG04cnQ4dGRUQmRyOW4yRUhGS24yWWM3T2FJKzYzb0o2Mk9GWkpMYWNMdHh6WGEyVGY4UzVjK2xVNzJLMWVUY1U1RktsNGdqOHRRUUtjdmV0b1ZIUS8vOWs9Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 14:04:01',
                    'updated_at' => '2026-10-24 19:01:44',
                ],
            ],
        ],
        [
            'id' => '019d63dd-b1e0-7398-a3d7-ddd782f7b763',
            'title' => 'Fiqh Solat',
            'slug' => 'fiqh-solat',
            'author' => 'Haji Engku Ali bin Engku Endut (Muhammad) Al-‘Idrusi',
            'created_at' => '2026-04-06 17:36:06',
            'updated_at' => '2026-04-06 17:36:06',
            'media' => [
                [
                    'source_media_id' => 101,
                    'uuid' => 'b6e2b36e-008f-42cd-b82e-77b56bdf16bb',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Fiqh Solat',
                    'file_name' => 'fiqh-solat-01knhxvc.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 111009,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'solat-jawi.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['fiqh-solat-01knhxvc___media_library_original_710_1024.jpg', 'fiqh-solat-01knhxvc___media_library_original_594_857.jpg', 'fiqh-solat-01knhxvc___media_library_original_496_715.jpg', 'fiqh-solat-01knhxvc___media_library_original_415_599.jpg', 'fiqh-solat-01knhxvc___media_library_original_347_500.jpg', 'fiqh-solat-01knhxvc___media_library_original_291_420.jpg', 'fiqh-solat-01knhxvc___media_library_original_243_350.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNzEwIDEwMjQiPgoJPGltYWdlIHdpZHRoPSI3MTAiIGhlaWdodD0iMTAyNCIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBTGdBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBekwvVWxzZ00xbmY4SktvL2hxUHhBY012R2F3Zk5VSGxhdXRXblRtMGp6TUxoS05hbEdVa2RHUEU2LzNhMU5PMVQ3YWVCaXVMaUtra2xhNlB3OHdMa0FZcUtkYXBPYWl5OFRnNkZLakthUkhyaWhzWnJuemJOSTN5OUsyUEVjaFRiaXNhMXZkcllicFN4S2w3UnMzeTVyNnZFZDViUTRCcnBkQlVCczF6VjFkQ1JnRjZWMFhodHQrYzFOQzdtaThmWlllUlU4U0x1SzF6dms0R2E3MjZzbzdyaHhWVWFEYlYwVktVNXpiUnc0YkcwYU5LTUdjY3NaSnJwL0RCSWRnYXQvMkJiVmVzTENPMGI1S21GS2NKSnNyRVkybFdwU2dqLzlrPSI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 17:36:06',
                    'updated_at' => '2026-10-24 19:00:13',
                ],
            ],
        ],
        [
            'id' => '019d63e3-75fe-7224-95bb-4c15e507cb98',
            'title' => 'Huwallah',
            'slug' => 'huwallah',
            'author' => 'Sayid Muhammad Maliki',
            'created_at' => '2026-04-06 17:42:24',
            'updated_at' => '2026-04-06 17:42:24',
            'media' => [
                [
                    'source_media_id' => 102,
                    'uuid' => '923434be-827d-4cc4-959b-2ab7ad35cf2d',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Huwallah',
                    'file_name' => 'huwallah-01knhy6x.png',
                    'mime_type' => 'image/png',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 341852,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/png', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'kitab-huwalloh-shofwa-v1.png'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['huwallah-01knhy6x___media_library_original_381_549.png', 'huwallah-01knhy6x___media_library_original_318_458.png', 'huwallah-01knhy6x___media_library_original_266_383.png', 'huwallah-01knhy6x___media_library_original_223_321.png', 'huwallah-01knhy6x___media_library_original_186_268.png', 'huwallah-01knhy6x___media_library_original_156_225.png', 'huwallah-01knhy6x___media_library_original_130_187.png', 'huwallah-01knhy6x___media_library_original_109_157.png', 'huwallah-01knhy6x___media_library_original_91_131.png', 'huwallah-01knhy6x___media_library_original_76_110.png'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMzgxIDU0OSI+Cgk8aW1hZ2Ugd2lkdGg9IjM4MSIgaGVpZ2h0PSI1NDkiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxnQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTBMMHE2QklqbGFyeDJUTU9sV3RNZzh3ODgxdFJ3aE9OdGQwV3NOSGtQVHZ5cXlPZkZpNjg0clh0WjBqaUNTTmcxYzhrRkR4V1JmUkZXeUtKcFlqUzVMOS9Sa1dtemhEeFc1SEx2NU5lYVd2aWFLSVpacXNXM2pkZlBLay9MVlY2TUtqdTJPY2tub2QvUE4vZFBGWk4zYzg0eldXbmlhMnVGd0hBcUdXK2psUERqODZ2RDRlTUhmcVZEVThuamQ1Q0Z6V3JhMkJ4dWFzbFBsYklyU3Q3dDBIYzF4ejVtdERqZzFmVXNTd1BIekd4RkpEZFRLMk41cUthNlp1QnhUSTMrYk5hMG5KTFVKUzEwUC9aIj4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 17:42:24',
                    'updated_at' => '2026-10-24 19:00:43',
                ],
            ],
        ],
        [
            'id' => '019d63ed-e842-7391-8539-9cc742e94607',
            'title' => 'As-Syamail Al-Muhammadiyah',
            'slug' => 'as-syamail-al-muhammadiyah',
            'author' => 'Imam At-Tirmidzi',
            'created_at' => '2026-04-06 17:53:49',
            'updated_at' => '2026-04-21 23:30:29',
            'media' => [
                [
                    'source_media_id' => 103,
                    'uuid' => '1d69dc12-316b-4fe0-baac-da430088f4dd',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - As Syamail Al Muhammadiyah',
                    'file_name' => 'as-syamail-al-muhammadiyah-01knhyvt.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 218898,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '0b2fff3bf762702c07d1ba02e5ce979b_large.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['as-syamail-al-muhammadiyah-01knhyvt___media_library_original_719_1024.jpg', 'as-syamail-al-muhammadiyah-01knhyvt___media_library_original_601_856.jpg', 'as-syamail-al-muhammadiyah-01knhyvt___media_library_original_503_716.jpg', 'as-syamail-al-muhammadiyah-01knhyvt___media_library_original_421_600.jpg', 'as-syamail-al-muhammadiyah-01knhyvt___media_library_original_352_501.jpg', 'as-syamail-al-muhammadiyah-01knhyvt___media_library_original_294_419.jpg', 'as-syamail-al-muhammadiyah-01knhyvt___media_library_original_246_350.jpg', 'as-syamail-al-muhammadiyah-01knhyvt___media_library_original_206_293.jpg', 'as-syamail-al-muhammadiyah-01knhyvt___media_library_original_172_245.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNzE5IDEwMjQiPgoJPGltYWdlIHdpZHRoPSI3MTkiIGhlaWdodD0iMTAyNCIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBTGdBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBd3RNdFk1NFJrY21yTnhheFFIQldzK0c2TUZpckljTlVETmQzWkxHWEZlRDF1MmV6VnJLTTJqV2d0SXB6amJWYlZMS09HRTRITlVJM3U0RGtTMWRubmFiVHlaRGx2V2pyZE1kS3NwVFNHV2RvTGl3eVQwNXJOa25LT1VWeU1jVnBhYTdmWkRqcGlzQzRreGN2eDNyU091NXRERHdxU2s1RjJLVHpKQWhjOG10ZTh0ZnM5Z0FEbk5jOWF5WnVVNDcxdjZrN2ZaUURuR0tVaHp3OEtjNDhvL1J5SHRQTHgxSFdzYlVOT2t0cmhpZVFUVW1tNm0wTUFVTFZ5VFVsbSsvRm1rcnhZT1ZTTGZLVXROMDk3aTRWdWdCcmExa2hiVFpqb090VW85U0VYM0lzVlcxSFVtbWdLbGFUdkpsUmxVazF6bi8vMlE9PSI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 17:53:49',
                    'updated_at' => '2026-10-24 19:00:13',
                ],
            ],
        ],
        [
            'id' => '019d63f5-e0d0-73a6-8654-1dbcc0bb0453',
            'title' => 'Al-Misbah Al-Munir',
            'slug' => 'al-misbah-al-munir',
            'author' => 'Baba Abdul Aziz Bin Ismail Al-Fathoni',
            'created_at' => '2026-04-06 18:02:31',
            'updated_at' => '2026-04-06 18:02:31',
            'media' => [
                [
                    'source_media_id' => 104,
                    'uuid' => '686f8adc-88c9-41b9-ba20-5b424112d6a5',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Al Misbah Al Munir',
                    'file_name' => 'al-misbah-al-munir-01knhzbr.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 70939,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'misbahulmunir.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['al-misbah-al-munir-01knhzbr___media_library_original_350_491.jpg', 'al-misbah-al-munir-01knhzbr___media_library_original_292_410.jpg', 'al-misbah-al-munir-01knhzbr___media_library_original_244_342.jpg', 'al-misbah-al-munir-01knhzbr___media_library_original_204_286.jpg', 'al-misbah-al-munir-01knhzbr___media_library_original_171_240.jpg', 'al-misbah-al-munir-01knhzbr___media_library_original_143_201.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMzUwIDQ5MSI+Cgk8aW1hZ2Ugd2lkdGg9IjM1MCIgaGVpZ2h0PSI0OTEiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxRQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QUlJMGFQa1U5a2hVODRxdDVwUzNKSFdzc1QzRWtoem5GY2tZdVIweWx5bStpUW5waXE5MnFLdkFyS1c0bmlsQUdjVmZta0x3QW5yVGNYRUl5NWlaSXQwSFdxTEpKR3hBWE5XMGNyRFN4M0Fac2JhbE94VGh6RlNPS1NSdm1YQXF6Y3hiSU90T2U2R2NiY1V5ZDk4Vk55dUVZY282QkRKRmcwcTJ4UnVha3RuMnhkS20zNTdDb1pvbTBWRGFsMjlxV2VQWkhpcml2Z2RCVVZ3ZHk4aW1oTzdaLy85az0iPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-06 18:02:31',
                    'updated_at' => '2026-10-24 19:00:14',
                ],
            ],
        ],
        [
            'id' => '019d65e6-58c3-70ed-9de4-ec6b3a6879f0',
            'title' => 'Mukhtashar Minhajul Qashidin',
            'slug' => 'mukhtashar-minhajul-qashidin',
            'author' => 'Ibnu Qudamah Al-Maqdisi',
            'created_at' => '2026-04-07 03:04:48',
            'updated_at' => '2026-04-07 03:04:48',
            'media' => [
                [
                    'source_media_id' => 106,
                    'uuid' => '9279aa0c-c236-4c41-85cc-019eabbe8a5f',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Mukhtashar Minhajul Qashidin',
                    'file_name' => 'mukhtashar-minhajul-qashidin-01knjycp.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 67390,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'website-500x500-2024-12-06T151620.353.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['mukhtashar-minhajul-qashidin-01knjycp___media_library_original_500_500.jpg', 'mukhtashar-minhajul-qashidin-01knjycp___media_library_original_418_418.jpg', 'mukhtashar-minhajul-qashidin-01knjycp___media_library_original_350_350.jpg', 'mukhtashar-minhajul-qashidin-01knjycp___media_library_original_292_292.jpg', 'mukhtashar-minhajul-qashidin-01knjycp___media_library_original_244_244.jpg', 'mukhtashar-minhajul-qashidin-01knjycp___media_library_original_204_204.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNTAwIDUwMCI+Cgk8aW1hZ2Ugd2lkdGg9IjUwMCIgaGVpZ2h0PSI1MDAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUlBQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTNmak1BYmVQTmVDeVNaWWdDdmUvaktNMnlWNEV6S0hQSE5aVDNOYWFpL2lDSi9uMmtWOUdmQnNZMFZxK2NZMkJsR0JYMGY4QUJ6L2tDdFRpS2FTZWhTK01ZLzBaSytmSmMrYTJLK2hQakQveDd4ZzE0VEphTDVoT2VEU2JzeU56T2h6NTRCcjZUK0R2L0lHYXZubExaVWszWjZWOURmQjA1MFpxY2RXSFEvL1oiPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-07 03:04:48',
                    'updated_at' => '2026-10-24 19:00:14',
                ],
            ],
        ],
        [
            'id' => '019d65e9-2860-733e-969e-646ae20a0268',
            'title' => 'Fiqh Munakahat',
            'slug' => 'fiqh-munakahat',
            'created_at' => '2026-04-07 03:07:52',
            'updated_at' => '2026-04-07 03:07:52',
        ],
        [
            'id' => '019d663e-bb34-710f-9f6a-1e596ae0b3f6',
            'title' => 'Hikam Al-Haddad',
            'slug' => 'hikam-al-haddad',
            'author' => 'Imam Abdullah bin Alawi Al-Haddad',
            'created_at' => '2026-04-07 04:41:20',
            'updated_at' => '2026-04-07 04:41:20',
            'media' => [
                [
                    'source_media_id' => 107,
                    'uuid' => '6d1e4103-4423-445f-a8c6-698ed215ca9f',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Hikam Al Haddad',
                    'file_name' => 'hikam-al-haddad-01knk3xe.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 189745,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '491951122_1315198243582111_2233972208679132079_n-v1.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['hikam-al-haddad-01knk3xe___media_library_original_713_1007.jpg', 'hikam-al-haddad-01knk3xe___media_library_original_596_842.jpg', 'hikam-al-haddad-01knk3xe___media_library_original_499_705.jpg', 'hikam-al-haddad-01knk3xe___media_library_original_417_589.jpg', 'hikam-al-haddad-01knk3xe___media_library_original_349_493.jpg', 'hikam-al-haddad-01knk3xe___media_library_original_292_412.jpg', 'hikam-al-haddad-01knk3xe___media_library_original_244_345.jpg', 'hikam-al-haddad-01knk3xe___media_library_original_204_288.jpg', 'hikam-al-haddad-01knk3xe___media_library_original_171_242.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNzEzIDEwMDciPgoJPGltYWdlIHdpZHRoPSI3MTMiIGhlaWdodD0iMTAwNyIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBTFFBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBN3lCUWtPK1EwZmFZdXdwTG1HU1NGUkdLeVpyZTdqWUZUeFhuUmlTeldGekNUakZKTkJHd0VpQ3FFRmhQTWR4ZkZhYVFtS0VKbkpxdVVFeVdTVm9yTXNneXdGY3RQZlhyei9NaEM1cnJZZm1pQU5VNzYxaldNdmluRjJIWXliYTh1VllCVkpyZERsb3d6OEh2VmV5dFVDQ1FDcDdyL1ZuRlZmVUxDd1NBdzVIYXF0MWNOSXBqSzFVdHJsMFlxT2xPbGtMU2cxRWRTMnJGaTJ1SFJSR0ZxZTZreERscXJSVEVkaFZhOXVIZjVUMHFaeXNnVWJuLzJRPT0iPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-07 04:41:20',
                    'updated_at' => '2026-10-24 19:01:37',
                ],
            ],
        ],
        [
            'id' => '019d6644-93f5-706a-b091-56c3799adcf9',
            'title' => 'Hikam Ghausiah',
            'slug' => 'hikam-ghausiah',
            'created_at' => '2026-04-07 04:47:43',
            'updated_at' => '2026-04-07 04:47:43',
        ],
        [
            'id' => '019d6647-f80a-7267-913c-21404f795394',
            'title' => 'Fiqh Sirah',
            'slug' => 'fiqh-sirah',
            'created_at' => '2026-04-07 04:51:25',
            'updated_at' => '2026-04-07 04:51:25',
        ],
        [
            'id' => '019d665c-6723-713e-99e4-0849df017428',
            'title' => 'Nur Al-Huda Sharah Masail Al-Tauhid',
            'slug' => 'nur-al-huda-sharah-masail-al-tauhid',
            'author' => 'Tuan Guru Haji Adnan bin Haji Muḥammad Samman',
            'created_at' => '2026-04-07 05:13:44',
            'updated_at' => '2026-04-08 09:48:07',
            'media' => [
                [
                    'source_media_id' => 131,
                    'uuid' => '768b3b02-c2b4-45f4-9a3d-e93f4c01c519',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Nurul Huda',
                    'file_name' => 'nurul-huda-01knp7vx.jpeg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 16417,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'WhatsApp_Image_2020-12-09_at_11.25.49.jpeg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['nurul-huda-01knp7vx___media_library_original_210_321.jpeg', 'nurul-huda-01knp7vx___media_library_original_175_268.jpeg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMjEwIDMyMSI+Cgk8aW1hZ2Ugd2lkdGg9IjIxMCIgaGVpZ2h0PSIzMjEiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQU1RQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTlOWGhxdHBGdUdhcm1GbTZWWWlSMEE1cnpIZHM3SEt5R1NRNDYxWFBEY1ZiblZtSFdxcVJNRHpSRzZZSjNScUxHQjJxQzd1bzdSTnoxZElxanFHbmk4ajI1d2E3SFRSZ3BpMnR6RmV4NVNubUxEZEtqMDdUeFpSNHprMWQyNW9WT3dPUTlpRjYxR1psRlNNQTFNOHRmU3RqSVFTcTFQQkI2VTNZbzdVcTRGQURoU1VVVXdFb29vb0EvOWs9Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 09:48:07',
                    'updated_at' => '2026-10-24 19:00:30',
                ],
            ],
        ],
        [
            'id' => '019d665f-e263-713c-8d2c-da8f54e2e346',
            'title' => 'Sunan Tarmizi',
            'slug' => 'sunan-tarmizi',
            'author' => 'Abu Isa Muhammad ibn Isa Al-Tirmizi',
            'created_at' => '2026-04-07 05:17:33',
            'updated_at' => '2026-04-07 05:17:33',
            'media' => [
                [
                    'source_media_id' => 108,
                    'uuid' => 'b26d4cd8-8bc0-4d5c-9d9e-7a27b88984ef',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Sunan Tarmizi',
                    'file_name' => 'sunan-tarmizi-01knk5zr.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 82220,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'my-11134207-7r98t-lmwo8njc887qd8-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['sunan-tarmizi-01knk5zr___media_library_original_468_693.webp', 'sunan-tarmizi-01knk5zr___media_library_original_391_579.webp', 'sunan-tarmizi-01knk5zr___media_library_original_327_484.webp', 'sunan-tarmizi-01knk5zr___media_library_original_274_406.webp', 'sunan-tarmizi-01knk5zr___media_library_original_229_339.webp', 'sunan-tarmizi-01knk5zr___media_library_original_191_283.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNDY4IDY5MyI+Cgk8aW1hZ2Ugd2lkdGg9IjQ2OCIgaGVpZ2h0PSI2OTMiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUx3QWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QXJhYmFSaTJCWVZwMjhVTXBJVk9sVTR4dHNSZzRKcXpienJCSDFHYXk1a3R6V01HMW9QblNDRmdHWHJUYnExaGEyTEtLbzNzL241SWJtcnRxR2ZUanVPVFJHYWs5QW5UY1ZjeXA1R0dtYmxPQ0JYTlBxTjA1T0pQMXJWdjUyWFRGVkR5YTV0NG1oUXZJU0tTczczTTd0YkYrSzZ1ZDRMUG11MjB0bWJUZHpua2l2UExkV25YZEdTY1YyZWl6dDlnWkhQSXFra3RoY3plNXpsM001c2xaUm5iWFAzdXB2Y0lZdGhCNlZwcmZxTGZZUWFwRm9TKzdaeldLZG5xaTdYUTNUNzZTeWpDbE01cnJOS3VYYTNaMkdBYTUyTXd1UmxlbGF5M2FwYjdRQ0tKU3ZzaHFLUi8vMlE9PSI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-07 05:17:33',
                    'updated_at' => '2026-10-24 19:00:50',
                ],
            ],
        ],
        [
            'id' => '019d6842-07e1-73e3-9783-d6c32dd52a5f',
            'title' => 'Cahaya Ketauhidan',
            'slug' => 'cahaya-ketauhidan',
            'author' => 'Ahmad Mukhlis Raja Jamaludin',
            'created_at' => '2026-04-07 14:04:11',
            'updated_at' => '2026-04-07 14:04:11',
        ],
        [
            'id' => '019d686d-7cc4-7340-9076-1191483e8fa2',
            'title' => 'Adab Menuntut',
            'slug' => 'adab-menuntut',
            'created_at' => '2026-04-07 14:51:39',
            'updated_at' => '2026-04-07 14:51:39',
        ],
        [
            'id' => '019d68d1-22dc-7019-8231-5bfc46cccc12',
            'title' => 'Faridatul Faraid',
            'slug' => 'faridatul-faraid',
            'author' => 'Syeikh Ahmad Zain Al-Fathoni',
            'description' => 'Kitab klasik dalam ilmu faraid (pembahagian harta pusaka menurut syariat Islam) yang disusun oleh ulama Nusantara, Syeikh Ahmad Zain Al-Fathoni. Ia menghuraikan asas-asas pembahagian harta warisan secara sistematik, meliputi bahagian waris, kaedah pengiraan, serta prinsip-prinsip penting dalam hukum faraid. Karya ini sering dijadikan rujukan dalam pengajian tradisional kerana susunannya yang ringkas tetapi padat, sesuai untuk pelajar yang ingin memahami ilmu faraid secara berperingkat.',
            'created_at' => '2026-04-07 16:40:29',
            'updated_at' => '2026-04-20 02:45:53',
            'media' => [
                [
                    'source_media_id' => 118,
                    'uuid' => 'f7bfb533-1a83-47c2-b105-0273a8f34d27',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Faridatul Faraid',
                    'file_name' => 'faridatul-faraid-01knmd28.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 73795,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'Faridatul Faraid_0000-v1.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['faridatul-faraid-01knmd28___media_library_original_542_753.jpg', 'faridatul-faraid-01knmd28___media_library_original_453_629.jpg', 'faridatul-faraid-01knmd28___media_library_original_379_527.jpg', 'faridatul-faraid-01knmd28___media_library_original_317_440.jpg', 'faridatul-faraid-01knmd28___media_library_original_265_368.jpg', 'faridatul-faraid-01knmd28___media_library_original_222_308.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNTQyIDc1MyI+Cgk8aW1hZ2Ugd2lkdGg9IjU0MiIgaGVpZ2h0PSI3NTMiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxBQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTlTdUdZTUFEVVJXVCs5VHJnL3ZGb1lFVjU3MWJQUmlySkROc21QdlZMYnV4eUNhYXVTRFNXNStkcUxXWVNWMHhiai9XTFVwRzVSVWR3UDNpMU4vRFZyZGk2SVpqQ21vYmI3N1ZZT0NwcUMySDd4cVV0MEMyWXQwZHJnMEdkU3ZXclVpSzNVVkY1TWY5MnBkN2lqSldWeUh6MUE2MFdweTdHcFRESC9kcVJJMVhvS0ZjSlNWdEQvL1oiPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-07 16:40:29',
                    'updated_at' => '2026-10-24 19:00:29',
                ],
            ],
        ],
        [
            'id' => '019d68d7-e5ee-7090-9817-88ea68abdba0',
            'title' => 'Al-Quran',
            'slug' => 'al-quran',
            'created_at' => '2026-04-07 16:47:52',
            'updated_at' => '2026-04-07 16:47:52',
            'media' => [
                [
                    'source_media_id' => 119,
                    'uuid' => '7263db1d-c0e1-453e-b225-bdd9819aadcf',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Al Quran',
                    'file_name' => 'al-quran-01knmdfs.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 62450,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 's-l500-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['al-quran-01knmdfs___media_library_original_330_500.webp', 'al-quran-01knmdfs___media_library_original_276_418.webp', 'al-quran-01knmdfs___media_library_original_230_348.webp', 'al-quran-01knmdfs___media_library_original_193_292.webp', 'al-quran-01knmdfs___media_library_original_161_244.webp', 'al-quran-01knmdfs___media_library_original_135_205.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMzMwIDUwMCI+Cgk8aW1hZ2Ugd2lkdGg9IjMzMCIgaGVpZ2h0PSI1MDAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQU1BQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QXEydHJHWWwrV283bjdOQWNOak5RL3dCb0NHMTNaNUFyQ1c5a3ZMd2x1VnJqakJ0M1kyN0hRUUxiekg1Y1VsNWFvSVd3dGMvOXRlenZjamhjMXNTNmdzdHRuUEpGTndhZDBKTzVuMzBFc2Rzb3dlYWl0N1ZvSWcyT1RYcEdzZUhVTmlIVmZ1MWhHMmdlMjJjYnhXelZvaTZuSDNNRFNydXh5S2Zid1NTV3g0UEZhbHdJb2dZempjVFhTNk5va2I2YVhJNU5DMVFyYW5iWDB5U2FjeWpISXJ5YlVaWnJUVVhHN0NrMTBMZUljMm9CZXVXMVM4anVYSko1cGM5OUNtaWh2a3ViOEhPUm12Uk5LMUJZckRZZXdyenUzbmppYmpyV21tcGJJamg2VGs3Z2tmL1oiPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-07 16:47:52',
                    'updated_at' => '2026-10-24 19:00:32',
                ],
            ],
        ],
        [
            'id' => '019d6bd2-a23d-7330-b78a-cdaa851c53e6',
            'title' => 'Kasymul Ghummah',
            'slug' => 'kasymul-ghummah',
            'created_at' => '2026-04-08 06:40:59',
            'updated_at' => '2026-04-08 06:41:03',
            'media' => [
                [
                    'source_media_id' => 120,
                    'uuid' => '888bd1d7-b0f0-411e-ba3b-5cd748d9545f',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Kasymul Ghummah',
                    'file_name' => 'kasymul-ghummah-01knnx58.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 77966,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'jhjkb.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['kasymul-ghummah-01knnx58___media_library_original_360_507.jpg', 'kasymul-ghummah-01knnx58___media_library_original_301_424.jpg', 'kasymul-ghummah-01knnx58___media_library_original_251_353.jpg', 'kasymul-ghummah-01knnx58___media_library_original_210_296.jpg', 'kasymul-ghummah-01knnx58___media_library_original_176_248.jpg', 'kasymul-ghummah-01knnx58___media_library_original_147_207.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMzYwIDUwNyI+Cgk8aW1hZ2Ugd2lkdGg9IjM2MCIgaGVpZ2h0PSI1MDciIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxRQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTBiblI3bEkyY0tUanRYTVhHb3kyN3NyeGtNSzltbGppalhEcndhNSs4OE5XR295TXdUQlB0WGd3YVdramdxMDUzdkE4NnRyK1c1ZFVTTTdqWFlXWGgyNG5qVjJHTWpwV3RZK0g5UDArUU1VQkk5cTZpejh1UmNJTUFWVXVWdTBRbzA1N3pKcGxqMi9PTTFVQVVFN1ZBcXkwaW5yU2VaRUIyclZXT3psWlZLb3pES0N0Q0JZMVg1QmlxTDNDQS9LQlJIZGdHazVJT1ZtYlBxU0xKdDNETlVaOVJJT0ExY3JKUEswbTR1YzAzenBPbTgxbXJsZldZOWpxVXZ6NjFLbDBXUDNxNUx6NU9tODA1YmlYY1BuTkpwc1gxbVBZLzlrPSI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 06:40:59',
                    'updated_at' => '2026-10-24 19:00:19',
                ],
            ],
        ],
        [
            'id' => '019d6bd6-03f5-7129-9b16-634583f28c53',
            'title' => 'Hidup Bertasawuf',
            'slug' => 'hidup-bertasawuf',
            'created_at' => '2026-04-08 06:44:41',
            'updated_at' => '2026-04-08 06:44:41',
        ],
        [
            'id' => '019d6bda-a9a4-727d-873a-6ec2870f9c30',
            'title' => 'Tafsir Jalalain',
            'slug' => 'tafsir-jalalain',
            'author' => 'Jalaluddin Al-Mahalli, Jalaluddin As-Suyuthi',
            'created_at' => '2026-04-08 06:49:45',
            'updated_at' => '2026-04-08 06:49:45',
            'media' => [
                [
                    'source_media_id' => 121,
                    'uuid' => '68780118-81b2-4433-8185-6f78330fea71',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Tafsir Jalalain',
                    'file_name' => 'tafsir-jalalain-01knnxna.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 183546,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'tasfir-jalalain-v1.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['tafsir-jalalain-01knnxna___media_library_original_484_729.jpg', 'tafsir-jalalain-01knnxna___media_library_original_404_609.jpg', 'tafsir-jalalain-01knnxna___media_library_original_338_509.jpg', 'tafsir-jalalain-01knnxna___media_library_original_283_426.jpg', 'tafsir-jalalain-01knnxna___media_library_original_237_357.jpg', 'tafsir-jalalain-01knnxna___media_library_original_198_298.jpg', 'tafsir-jalalain-01knnxna___media_library_original_166_250.jpg', 'tafsir-jalalain-01knnxna___media_library_original_138_208.jpg', 'tafsir-jalalain-01knnxna___media_library_original_116_175.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNDg0IDcyOSI+Cgk8aW1hZ2Ugd2lkdGg9IjQ4NCIgaGVpZ2h0PSI3MjkiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQU1BQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QXhiV3ppTVFMRGlwWlk3Vk1LdU0xWExOOWk0T0t6K1NNbHptdko2blJDbXBSTm55cmFQYWVLdUpCYlNKbGNacm1wbWY1Y01TSzBkTWtjTmpQRk83S2xSU1Z6T3ZKWlk3VmR2U3MxWjVXUElyU3V5LzJFWnJIRFA2MXZUU2FQUHFTbEY2TXNlZExuQUdSVy9vN3FZOHQxcm1Wa2NIR2EzZEptVUpnOWFkU0s1UlVxa25MVmtGNURQTmFLRTZWa0xiU3huRWltdW5obFV3aGMwK2FPM25oMkhBUHJVUms0NldLbkNNbGUrcHlqUjQ2VnQ2WEJ0ZzM1NXFXUFRiZFhHNXNpdEtOTGVJYlZQRk9jN3F5SnBRcythUi8vOWs9Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 06:49:45',
                    'updated_at' => '2026-10-24 19:00:30',
                ],
            ],
        ],
        [
            'id' => '019d6bf4-15f0-7041-be82-cd8f075c51bb',
            'title' => 'Mutiara Al-Quran',
            'slug' => 'mutiara-al-quran',
            'author' => 'Imam Al-Ghazali',
            'created_at' => '2026-04-08 07:17:31',
            'updated_at' => '2026-04-08 07:17:31',
            'media' => [
                [
                    'source_media_id' => 122,
                    'uuid' => '788a6e08-9a8c-46b7-b6bb-cbc3275bf1a3',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Mutiara Al Quran',
                    'file_name' => 'mutiara-al-quran-01knnz85.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 171642,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '618258001_895787026537646_2911055436786612105_n-v1.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['mutiara-al-quran-01knnz85___media_library_original_864_1397.jpg', 'mutiara-al-quran-01knnz85___media_library_original_722_1167.jpg', 'mutiara-al-quran-01knnz85___media_library_original_604_977.jpg', 'mutiara-al-quran-01knnz85___media_library_original_506_818.jpg', 'mutiara-al-quran-01knnz85___media_library_original_423_684.jpg', 'mutiara-al-quran-01knnz85___media_library_original_354_572.jpg', 'mutiara-al-quran-01knnz85___media_library_original_296_479.jpg', 'mutiara-al-quran-01knnz85___media_library_original_247_399.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgODY0IDEzOTciPgoJPGltYWdlIHdpZHRoPSI4NjQiIGhlaWdodD0iMTM5NyIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBTkFBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBOC90MVNUTzQ0RlNOYXc1KzlWZTNuUlUya2MxS0pVVHJ6V0U1TzU2dkt4MzJLTURyVkM2Z1dOL2w2VmZNNmtERlZMcVpUd0JVeGNyNmpqRjNJSTFETjF4VXhoQlAzNnFnODFZWENLQ2V0YU4yT2kyZy93QWtBL2Zxck9Ocll6bXAyTzhlOVZIem5ta25jYTJINDVxd0NHV29TTUdwSXlnKzlRMWNOMEt4Q2lxajhtclVwUS9kcU43ZC9MMzlxRXJCZEpFckFWR1JSUlFaeDJHMDlwRzh2Ym5paWltRWovL1oiPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 07:17:31',
                    'updated_at' => '2026-10-24 19:01:42',
                ],
            ],
        ],
        [
            'id' => '019d6bf7-9310-7343-9afa-ff217620897a',
            'title' => 'Bakurah Al-Amani',
            'slug' => 'bakurah-al-amani',
            'author' => 'Syeikh Wan Ismail bin Wan Abdul Qadir Al-Fatani (Pak Da Eil)',
            'created_at' => '2026-04-08 07:21:20',
            'updated_at' => '2026-04-08 07:21:20',
            'media' => [
                [
                    'source_media_id' => 123,
                    'uuid' => '24a71e41-6c21-48ff-9df7-4b51712f6573',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Bakurah Al Amani',
                    'file_name' => 'bakurah-al-amani-01knnzf4.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 73510,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'e8e1f27c77678638273aa9e81cdc437d-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['bakurah-al-amani-01knnzf4___media_library_original_652_930.webp', 'bakurah-al-amani-01knnzf4___media_library_original_545_777.webp', 'bakurah-al-amani-01knnzf4___media_library_original_456_650.webp', 'bakurah-al-amani-01knnzf4___media_library_original_381_543.webp', 'bakurah-al-amani-01knnzf4___media_library_original_319_455.webp', 'bakurah-al-amani-01knnzf4___media_library_original_267_381.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNjUyIDkzMCI+Cgk8aW1hZ2Ugd2lkdGg9IjY1MiIgaGVpZ2h0PSI5MzAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxnQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTZXeTAyS1dMSkZYUDdIaEhhcE5PTzIzQnExODdkSytYNUp2Wm0rRG9RcVFYdW9vLzJYRW5RVlN2clpZbzhnVnNFT0RtcW1wSnV0ODRweFU0dlZqeGRDRkttN3hXeFkwOVA5SEZhS0lNY1ZTc3hpQVZmaVBGZFZOSnhPbkJOZlZvMkdPbkZVZFFqLzBZOFZwRWduRlZ0UlgvQUVlcWtrbzNKeDh2OW1raGxpVmFMRlhBTnRjbGE2dEpFb0FGV2pyc3Y5MnVXTlJ4WnhZZXRUaEJOUzZIUkFEZG1vZFJaZkk2MWlqV1pQN3RWcnpWSkpFeGlvbldiMFJPS3JVNXdrK2JvZi9aIj4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 07:21:20',
                    'updated_at' => '2026-10-24 19:00:29',
                ],
            ],
        ],
        [
            'id' => '019d6c02-c3f7-7348-9b89-180d4d4304de',
            'title' => 'Mawaqif Al-Marjan',
            'slug' => 'mawaqif-al-marjan',
            'created_at' => '2026-04-08 07:33:33',
            'updated_at' => '2026-04-08 07:33:33',
            'media' => [
                [
                    'source_media_id' => 124,
                    'uuid' => '22b11d46-853c-4b29-83c3-20b0bb8311ae',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Mawaqif Al Marjan',
                    'file_name' => 'mawaqif-al-marjan-01knp05h.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 56684,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'my-11134207-7r98y-lme69wy6o68m4c-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['mawaqif-al-marjan-01knp05h___media_library_original_550_773.webp', 'mawaqif-al-marjan-01knp05h___media_library_original_460_647.webp', 'mawaqif-al-marjan-01knp05h___media_library_original_384_540.webp', 'mawaqif-al-marjan-01knp05h___media_library_original_322_453.webp', 'mawaqif-al-marjan-01knp05h___media_library_original_269_378.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNTUwIDc3MyI+Cgk8aW1hZ2Ugd2lkdGg9IjU1MCIgaGVpZ2h0PSI3NzMiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxRQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QXlyS3hlOWx3cHJiVHdzKzNMTlI0V2pEVGs0cnNYVGlxeE5hb3Fza21jOUNsRDJjZE9oeDdlRi9senVyQjFHeE5sSmcxNlZzNHJqUEZhYlpscUtOU2JxSzdOS2tJOGowTHZoRk16TlhhTkhudFhJZURSKytiTmQyNmdyU3hYOGFRVVA0Y2ZReldURmNUNHVINzFhOUFLZ0N1RjhZcis4V3BvZnhFWFUrQmpmQzkwdHZLU3hydXhmUVBIa1NMbjYxNUZienVuM1RpcnFYdHgvejBQNTE2VmJMNnM1dVNPR25qYVVZSk05TWE3Z1ZDVElNL1d1RThVM0szRW8ybnBXYzE5Y2Y4OUQrZFU1cFhsKytjMUZQTHFzWkpzcVdPcE9MUC85az0iPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 07:33:33',
                    'updated_at' => '2026-10-24 19:00:30',
                ],
            ],
        ],
        [
            'id' => '019d6c14-5370-7258-b52b-e4495b8cbdc2',
            'title' => 'Akhlak Baginda',
            'slug' => 'akhlak-baginda',
            'created_at' => '2026-04-08 07:52:44',
            'updated_at' => '2026-04-08 07:52:44',
        ],
        [
            'id' => '019d6c16-277c-71a0-854d-abeda621eef6',
            'title' => 'Hadis 40 Imam Nawawi',
            'slug' => 'hadis-40-imam-nawawi',
            'author' => 'Imam Nawawi',
            'created_at' => '2026-04-08 07:54:44',
            'updated_at' => '2026-05-04 04:10:15',
        ],
        [
            'id' => '019d6c1b-4a55-704e-8d70-98a32e423805',
            'title' => 'Kisah-Kisah Dalam Al-Quran',
            'slug' => 'kisah-kisah-dalam-al-quran',
            'created_at' => '2026-04-08 08:00:21',
            'updated_at' => '2026-04-08 08:00:21',
            'media' => [
                [
                    'source_media_id' => 126,
                    'uuid' => '3f53c306-f41e-496c-a940-58a08b6f34ca',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Kisah Kisah Dalam Al Quran',
                    'file_name' => 'kisah-kisah-dalam-al-quran-01knp1pj.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 235890,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '57787896.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['kisah-kisah-dalam-al-quran-01knp1pj___media_library_original_1385_1923.webp', 'kisah-kisah-dalam-al-quran-01knp1pj___media_library_original_1158_1608.webp', 'kisah-kisah-dalam-al-quran-01knp1pj___media_library_original_969_1345.webp', 'kisah-kisah-dalam-al-quran-01knp1pj___media_library_original_811_1126.webp', 'kisah-kisah-dalam-al-quran-01knp1pj___media_library_original_678_941.webp', 'kisah-kisah-dalam-al-quran-01knp1pj___media_library_original_567_787.webp', 'kisah-kisah-dalam-al-quran-01knp1pj___media_library_original_475_660.webp', 'kisah-kisah-dalam-al-quran-01knp1pj___media_library_original_397_551.webp', 'kisah-kisah-dalam-al-quran-01knp1pj___media_library_original_332_461.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMTM4NSAxOTIzIj4KCTxpbWFnZSB3aWR0aD0iMTM4NSIgaGVpZ2h0PSIxOTIzIiB4bGluazpocmVmPSJkYXRhOmltYWdlL2pwZWc7YmFzZTY0LC85ai80QUFRU2taSlJnQUJBUUVBWUFCZ0FBRC8vZ0ErUTFKRlFWUlBVam9nWjJRdGFuQmxaeUIyTVM0d0lDaDFjMmx1WnlCSlNrY2dTbEJGUnlCMk9EQXBMQ0JrWldaaGRXeDBJSEYxWVd4cGRIa0svOXNBUXdBSUJnWUhCZ1VJQndjSENRa0lDZ3dVRFF3TEN3d1pFaE1QRkIwYUh4NGRHaHdjSUNRdUp5QWlMQ01jSENnM0tTd3dNVFEwTkI4bk9UMDRNand1TXpReS85c0FRd0VKQ1FrTUN3d1lEUTBZTWlFY0lUSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5LzhBQUVRZ0FMQUFnQXdFaUFBSVJBUU1SQWYvRUFCOEFBQUVGQVFFQkFRRUJBQUFBQUFBQUFBQUJBZ01FQlFZSENBa0tDLy9FQUxVUUFBSUJBd01DQkFNRkJRUUVBQUFCZlFFQ0F3QUVFUVVTSVRGQkJoTlJZUWNpY1JReWdaR2hDQ05Dc2NFVlV0SHdKRE5pY29JSkNoWVhHQmthSlNZbktDa3FORFUyTnpnNU9rTkVSVVpIU0VsS1UxUlZWbGRZV1ZwalpHVm1aMmhwYW5OMGRYWjNlSGw2ZzRTRmhvZUlpWXFTazVTVmxwZVltWnFpbzZTbHBxZW9xYXF5czdTMXRyZTR1YnJDdzhURnhzZkl5Y3JTMDlUVjF0ZlkyZHJoNHVQazVlYm42T25xOGZMejlQWDI5L2o1K3YvRUFCOEJBQU1CQVFFQkFRRUJBUUVBQUFBQUFBQUJBZ01FQlFZSENBa0tDLy9FQUxVUkFBSUJBZ1FFQXdRSEJRUUVBQUVDZHdBQkFnTVJCQVVoTVFZU1FWRUhZWEVUSWpLQkNCUkNrYUd4d1Frak0xTHdGV0p5MFFvV0pEVGhKZkVYR0JrYUppY29LU28xTmpjNE9UcERSRVZHUjBoSlNsTlVWVlpYV0ZsYVkyUmxabWRvYVdwemRIVjJkM2g1ZW9LRGhJV0doNGlKaXBLVGxKV1dsNWlabXFLanBLV21wNmlwcXJLenRMVzJ0N2k1dXNMRHhNWEd4OGpKeXRMVDFOWFcxOWpaMnVMajVPWG01K2pwNnZMejlQWDI5L2o1K3YvYUFBd0RBUUFDRVFNUkFEOEE2KytoTTlvNkR1SzQ2VFJTSE9YWDg2MFovRnR2SmJ1c1orWWppdU1uMUs0a2xkdk9ZWlByWGpSaEk5T25XbEJXUnVuUnlUOTlmenJmMGF4ZTJqUE9jMTU0dDljNS93QmUzNTExR2orSlV0YmJiY05rK3RFNFN0b0U2OHBLek9TdG94SVRsc1ZPYkVISkQxWHRvVEtUODJLdTI2YlNWWjgxNkhLbWNyYklCWWpydXBrMXNFVE8vTlgyaVZsSURnR3FNOXRzVWt5Wi9HbnlwRTNNcU9kOStBU00xZldDN0kzS3I4MVF0QURjcG4xcjFyU0xPQjdGQzBhazQ5S1Zhb3FmUW1LY2p6VDdOZWYzWHFPU0M2eHlHeFhyVXRqYkJUKzZYOHE1Kzl0NGwzQUlLNXBZcnNqcXcrRzlxMm16LzlrPSI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 08:00:21',
                    'updated_at' => '2026-10-24 19:00:36',
                ],
            ],
        ],
        [
            'id' => '019d6c5d-ea85-70e9-b3a2-b3c813887a68',
            'title' => 'Al-Hikam',
            'slug' => 'al-hikam',
            'author' => 'Imam Ibnu Atha\'illah As-Sakandari',
            'description' => 'Karya tasawuf yang masyhur ini menghimpunkan untaian hikmah yang mendidik hati agar mengenal Allah, membersihkan jiwa, dan membetulkan niat dalam perjalanan menuju-Nya. Al-Hikam sering dijadikan rujukan dalam pengajian tazkiyah kerana bahasanya yang padat, mendalam, dan membuka ruang muhasabah yang besar bagi pencari ilmu dan penempuh jalan rohani.',
            'created_at' => '2026-04-08 09:13:07',
            'updated_at' => '2026-04-20 02:48:26',
            'media' => [
                [
                    'source_media_id' => 129,
                    'uuid' => '97811040-79d5-46f1-9aba-3081648c5a07',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Al Hikam',
                    'file_name' => 'al-hikam-01knp6cb.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 90212,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'my-11134207-7r98x-lrwvvv2esfefb1-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['al-hikam-01knp6cb___media_library_original_564_800.webp', 'al-hikam-01knp6cb___media_library_original_471_668.webp', 'al-hikam-01knp6cb___media_library_original_394_559.webp', 'al-hikam-01knp6cb___media_library_original_330_468.webp', 'al-hikam-01knp6cb___media_library_original_276_391.webp', 'al-hikam-01knp6cb___media_library_original_231_328.webp', 'al-hikam-01knp6cb___media_library_original_193_274.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNTY0IDgwMCI+Cgk8aW1hZ2Ugd2lkdGg9IjU2NCIgaGVpZ2h0PSI4MDAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxRQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTROTk90VmczTU9hei9za1VreDJqaW96ZlBLb1VIQXFSWEtMOHZXbEM2M1BVaHlVMXpOR25wOXJBa29EclczTFp3R0FsUlhQMnN4YnIxcldpdVdTUEI1Rk9TdnFnbEtFMWRJNUcwaTNGUjYxMFZwcGc4MVVmSE5jdlozQlRhVDJyWUdveTdsYmQwckNvcHZTSnpWRzJqcGhvU0JkNkVjVlVaQUNWOUtweDZ6UHR4dk9La2puOHdrNTYxV0hqVVdrektEbEhZNTBXYW0zRHAxcU5HSk8xdURUYmE2a0VRSGFvWloyRW5BRldocWVoZlJtREJSelczWnh0R29aMU9EV0JZek0wZ3lCWFFHN2RvVlVnWUZEYlRTUW16Ly9aIj4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 09:22:09',
                    'updated_at' => '2026-10-24 19:00:32',
                ],
            ],
        ],
        [
            'id' => '019d6c80-e84d-72bc-b31c-dfa3ea49ad82',
            'title' => 'Solat Seolah-olah Melihat Allah',
            'slug' => 'solat-seolah-olah-melihat-allah',
            'author' => 'Maulana Iqbal Zain Al-Jauhari',
            'created_at' => '2026-04-08 09:51:20',
            'updated_at' => '2026-04-08 09:51:20',
            'media' => [
                [
                    'source_media_id' => 132,
                    'uuid' => '53faabee-e0e9-42e7-9b30-7117f93d0b14',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Solat Seolah Olah Melihat Allah',
                    'file_name' => 'solat-seolah-olah-melihat-allah-01knp81t.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 9598,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'my-11134207-7rasj-m65femptci8j30-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['solat-seolah-olah-melihat-allah-01knp81t___media_library_original_376_565.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMzc2IDU2NSI+Cgk8aW1hZ2Ugd2lkdGg9IjM3NiIgaGVpZ2h0PSI1NjUiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQU1BQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTlPeFJpbXl2NWFsalZYN2V1TTRxaEYyaXFKMUJTY0FjMVpqY3VvSm9BZkttOVNLcE5BaWpCeFdnUm1xczNseDhuclNiUzNHaXRIYnhoczk2dkl1RnhVRUlTUTVBcTBCaWhOUFlHS1RWS2FNR1RMTlZzMHd4S3h5UlNjVXdUSzhRQ3RnR3JnNlZHSWxIUVZJT0tFckEyZi9aIj4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 09:51:20',
                    'updated_at' => '2026-04-08 09:51:21',
                ],
            ],
        ],
        [
            'id' => '019d6c96-65f2-73b2-b914-b2a6b0ec8adc',
            'title' => 'Halal & Haram Dalam Islam',
            'slug' => 'halal-haram-dalam-islam',
            'author' => 'Dr Yusuf Al-Qaradhawi ',
            'created_at' => '2026-04-08 10:14:49',
            'updated_at' => '2026-04-08 10:14:49',
            'media' => [
                [
                    'source_media_id' => 135,
                    'uuid' => '37e640a5-596e-4078-88e0-e4ae6ff6c5b3',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Halal & Haram Dalam Islam',
                    'file_name' => 'halal-haram-dalam-islam-01knp9cs.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 210333,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '9789670562063-v1.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['halal-haram-dalam-islam-01knp9cs___media_library_original_664_1000.jpg', 'halal-haram-dalam-islam-01knp9cs___media_library_original_555_836.jpg', 'halal-haram-dalam-islam-01knp9cs___media_library_original_464_699.jpg', 'halal-haram-dalam-islam-01knp9cs___media_library_original_388_584.jpg', 'halal-haram-dalam-islam-01knp9cs___media_library_original_325_489.jpg', 'halal-haram-dalam-islam-01knp9cs___media_library_original_272_410.jpg', 'halal-haram-dalam-islam-01knp9cs___media_library_original_227_342.jpg', 'halal-haram-dalam-islam-01knp9cs___media_library_original_190_286.jpg', 'halal-haram-dalam-islam-01knp9cs___media_library_original_159_239.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNjY0IDEwMDAiPgoJPGltYWdlIHdpZHRoPSI2NjQiIGhlaWdodD0iMTAwMCIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBTUFBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBbkdrWHpzV0VaNHBIMG05Szc5bkFyMFRWb3BJNGl0c2czZlN1YUs2anlyTGl1WGtSbEo4dWxqQlRTYjBMdjJjR3JKMHE4U0Vaak9UV3NFMUU0VlJtdWswbEpIakMzS0RkN2lrb3BNY1hmU3hxWHhNQmQxVGN3cm5yaTVlUTduaEkvQ3VnMUNaOXIrWGd0WE0zZDNlQ1BhMFk1cm9qWTBzVzRKL0lkR1NJdG10K0xiS3lOdHdTSzVtQzV2R2lWVWpIRmIxalBKdFR6Y0JxSldHa1pWM3FDT1dNVWd6OWF6ZnRUc2Q4amdxSzVTMmxrRXZ6U0hCOTZ1eVRJRnh2T0s1SlloUWRyQW9PV3B2UGV5S2hlTndCVTFuckNaQW1rR2E1bFpvOW0zZWNWbTNNckNRN1dPS1N4Q203V05vVW9wTnlaLy9aIj4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 10:14:49',
                    'updated_at' => '2026-10-24 19:00:40',
                ],
            ],
        ],
        [
            'id' => '019d6ca2-31be-71d7-8291-09c57e31c62a',
            'title' => 'Iman & Kehidupan',
            'slug' => 'iman-kehidupan',
            'author' => 'Dr Yusuf Al-Qaradhawi ',
            'created_at' => '2026-04-08 10:27:42',
            'updated_at' => '2026-04-08 10:27:42',
            'media' => [
                [
                    'source_media_id' => 137,
                    'uuid' => '25eaa8e5-e394-4fbd-ac19-aeba22a7d264',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Iman & Kehidupan',
                    'file_name' => 'iman-kehidupan-01knpa4c.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 44916,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '0d56805692fad29ae4832be8d1d8aa42-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['iman-kehidupan-01knpa4c___media_library_original_504_809.webp', 'iman-kehidupan-01knpa4c___media_library_original_421_676.webp', 'iman-kehidupan-01knpa4c___media_library_original_352_565.webp', 'iman-kehidupan-01knpa4c___media_library_original_295_474.webp', 'iman-kehidupan-01knpa4c___media_library_original_246_395.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNTA0IDgwOSI+Cgk8aW1hZ2Ugd2lkdGg9IjUwNCIgaGVpZ2h0PSI4MDkiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQU13QWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTZVUmsxSjVISFdyNHNqaXM2NmVhQ1hHM2l2ak1MUm5aeHNUVGdtck1YeUQ2MHhvaXBwOXU4czhnQVhqdldrYklrRGl0TVJTbnlxTmduQkphR3BkVzAvSGtyV2ZQcGQxUGd1dGJhM1l4MW9udlFzSklJeml2ZGhLbGV5TjAxME1hMjBxNWh5VVVWbzJ0clBuOTZLVFQ3OHZ1RE4zcTIxMlBXcXF1RVpXbHVEc25xYzR0NXgxcXRlNm41YTRQZXFMdVZIeXRtb0pWTXcrYXZuOFBSclJtcFRsK0p6Um5aNmsxcnFiL0FHa0JjNE5iUnV5UU9hNTZLTVJISUhOV2tsTEQ1aml0OFZHclZuektYNGhPcHpQUVJRS2VBUFNpaXZJYmR6SU1EMHBNRDBvb3JSTUQvOWs9Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 10:27:42',
                    'updated_at' => '2026-10-24 19:00:39',
                ],
            ],
        ],
        [
            'id' => '019d6cab-905c-719f-8b62-1a40a9f3bf1d',
            'title' => 'Dosa-dosa Besar',
            'slug' => 'dosa-dosa-besar',
            'author' => 'Imam Az-Zahabi (Adz-Dzahabi)',
            'created_at' => '2026-04-08 10:37:56',
            'updated_at' => '2026-04-08 10:39:31',
            'media' => [
                [
                    'source_media_id' => 138,
                    'uuid' => '400f2278-9e25-4b6d-a968-e8760a664700',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Dosa Dosa Besar',
                    'file_name' => 'dosa-dosa-besar-01knpaq4.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 93866,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'my-11134207-7rasl-m6y57zgm2tzted-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['dosa-dosa-besar-01knpaq4___media_library_original_707_1024.webp', 'dosa-dosa-besar-01knpaq4___media_library_original_591_856.webp', 'dosa-dosa-besar-01knpaq4___media_library_original_494_715.webp', 'dosa-dosa-besar-01knpaq4___media_library_original_414_600.webp', 'dosa-dosa-besar-01knpaq4___media_library_original_346_501.webp', 'dosa-dosa-besar-01knpaq4___media_library_original_289_419.webp', 'dosa-dosa-besar-01knpaq4___media_library_original_242_351.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNzA3IDEwMjQiPgoJPGltYWdlIHdpZHRoPSI3MDciIGhlaWdodD0iMTAyNCIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBTGdBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBeEl3aFFsalNLUm5BTlZ0d2FQQVlacUZKV1NmR0s0NzMwUjYwNnpqTFF2T2VlVFFSaGNnMVZNclBMajFxWGNxSmdzTTBYN2pwMW5KMlptYWJCSk1OKzQxcC9aanV3ZXRaVmxMUGJyZ0RpdE9LZDNJWTlhcnFjN0U4bCt3ckwxT0tTM2NIY2VhMUpaNVU1RlpkOFo3bkJJbzZnaS9wa2FYVVpXUTRxVXdpS1lxcHlLdzRMdVNIN3B4VmthaklUVTJOcFUyNVhORXhHYWJhVGdWRmZyOW5WVlUxU09vU0E1cXRQZFNTbjVqU3RxRWFiVHVmLzlrPSI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 10:37:56',
                    'updated_at' => '2026-10-24 19:00:38',
                ],
            ],
        ],
        [
            'id' => '019d6cb4-b3b3-72c8-9483-aa351c9533a3',
            'title' => 'Hujjah Ahlussunnah Wal Jemaah',
            'slug' => 'hujjah-ahlussunnah-wal-jemaah',
            'author' => 'Syekh KH Ali Ma’Sum Al-Jawi',
            'description' => 'Kitab ini menghimpunkan penjelasan dan hujah-hujah bagi mempertahankan pegangan Ahlussunnah Wal Jemaah, khususnya dalam persoalan aqidah, sifat-sifat Allah, kenabian, perkara sam‘iyyat dan isu-isu yang sering menjadi medan kekeliruan umat. Perbahasannya disusun untuk memperkukuh keyakinan terhadap manhaj akidah yang sahih di samping menolak fahaman yang menyimpang daripada jalan para ulama muktabar. Ia sesuai dijadikan rujukan bagi mereka yang ingin memahami asas pegangan Islam secara lebih tersusun dan berdalil.',
            'created_at' => '2026-04-08 10:47:55',
            'updated_at' => '2026-04-20 08:14:41',
            'media' => [
                [
                    'source_media_id' => 139,
                    'uuid' => '04467400-fa02-454c-95e9-5f274c4255d7',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Hujjah Ahlussunnah Wal Jemaah',
                    'file_name' => 'hujjah-ahlussunnah-wal-jemaah-01knpb9d.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 221302,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'IMG-3088_700x.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['hujjah-ahlussunnah-wal-jemaah-01knpb9d___media_library_original_700_970.webp', 'hujjah-ahlussunnah-wal-jemaah-01knpb9d___media_library_original_585_811.webp', 'hujjah-ahlussunnah-wal-jemaah-01knpb9d___media_library_original_490_679.webp', 'hujjah-ahlussunnah-wal-jemaah-01knpb9d___media_library_original_409_567.webp', 'hujjah-ahlussunnah-wal-jemaah-01knpb9d___media_library_original_343_475.webp', 'hujjah-ahlussunnah-wal-jemaah-01knpb9d___media_library_original_286_396.webp', 'hujjah-ahlussunnah-wal-jemaah-01knpb9d___media_library_original_240_333.webp', 'hujjah-ahlussunnah-wal-jemaah-01knpb9d___media_library_original_200_277.webp', 'hujjah-ahlussunnah-wal-jemaah-01knpb9d___media_library_original_168_233.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNzAwIDk3MCI+Cgk8aW1hZ2Ugd2lkdGg9IjcwMCIgaGVpZ2h0PSI5NzAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxBQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QXliZTNFaWhWR01WZkVObENnTXNxZyttYXFYTWpXZWx0SWcrY2ppdUplUy9sbU1rc2hLNTZacnhvUjV0V3oxYTJJbENUUjZDMEZuTWhNVXFrK2xaMTFBSTBLc00xeHNWM2ZSM0lhSnlGQjZWM0VidGQ2V3NyajVnT2FKdzViTk1LR0lsT1NRdHlRK2xNR0hRVnlmMmZma0FuRmRmZHFQN0hiYjF4WEpSdXdZaWxDNldoM1U4TlRxSnVTR3JiQkdISjYxMWFFSnBhcXZURmNzN2t1UHJYV1JLUDdKWFBYRk9iYlN1T3BocWRPemloTGQvTWdhSi91a1ZqM21reXhTYm9odUI5S3R4U01ZaHpWbU9lUVk1cEwzV1p1TlNMYWk3R1paNlRKSSsrVWJRUFd0VzdsQ1JDSmVnRk5rbmtPUm1xYzdzSXp6US9lWVJqVWJYTTduLy8yUT09Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 10:47:55',
                    'updated_at' => '2026-10-24 19:01:08',
                ],
            ],
        ],
        [
            'id' => '019d6cc0-1d3c-7213-b328-a27674d3c996',
            'title' => 'Permasalahan Aqidah Tauhid Pegangan Setiap Mukmin',
            'slug' => 'permasalahan-aqidah-tauhid-pegangan-setiap-mukmin',
            'author' => 'Ishak Din, Prof Dato Dr Harun Din',
            'created_at' => '2026-04-08 11:00:22',
            'updated_at' => '2026-04-08 11:00:22',
            'media' => [
                [
                    'source_media_id' => 140,
                    'uuid' => 'bbf18ef1-c895-4493-a5b0-d8995c2193a5',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Permasalahan Aqidah Tauhid Pegangan Setiap Mukmin',
                    'file_name' => 'permasalahan-aqidah-tauhid-pegangan-setiap-mukmin-01knpc07.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 38114,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '48380a0ef8c8f4fa72c5cabd8a99fdf1-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['permasalahan-aqidah-tauhid-pegangan-setiap-mukmin-01knpc07___media_library_original_528_800.webp', 'permasalahan-aqidah-tauhid-pegangan-setiap-mukmin-01knpc07___media_library_original_441_668.webp', 'permasalahan-aqidah-tauhid-pegangan-setiap-mukmin-01knpc07___media_library_original_369_559.webp', 'permasalahan-aqidah-tauhid-pegangan-setiap-mukmin-01knpc07___media_library_original_309_468.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNTI4IDgwMCI+Cgk8aW1hZ2Ugd2lkdGg9IjUyOCIgaGVpZ2h0PSI4MDAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQU1BQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTV1NXMwaVU1SE5aUWlSbklLOFZydkkwZythcXp3L0tTQlhCVG5LS3N6MWEzSktWNG9aRll3SEhQTlhIdENZZGk4aXM1WEtuSkpyUXRyOFJrWk9hbXJ6MnVpc1BWaHpjclF6YzZER3cxYml0SkpJOTJPSzlRazBEUlpRVWpLYnowckltOE9UVzduWW1VN1Y2TWNOQnlYTXp5cTJKcXVMOW10VHpTNmhrUmlBbFZrVjJHZHA0cnFOV2todExnUnlLQWM4MTF1amFSb1YzWUpJeFhjUnpXdGFoVDJneFVLMVJhMUZxY3ZlMzkxcDl4SEtKRzRQclhTVzN4QVUyV3lTUEw0eG1zUFc3UnAwQVVWei9rbUJjTU9hdkwrV3RGeG51amZHMG5UYW5IWVRWUkpxOSs4NTRCT1FLdjZRMHNjWmlWMjQ5NnJ3Wko1cmEwbUdNc3h4V2VZdFVXb3hOc0xIblhOWS8vMlE9PSI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 11:00:23',
                    'updated_at' => '2026-10-24 19:00:36',
                ],
            ],
        ],
        [
            'id' => '019d6cd7-772a-7110-b170-4a94e6940f23',
            'title' => 'Bidayatul Hidayah',
            'slug' => 'bidayatul-hidayah',
            'author' => 'Imam Hujjatul Islam Al Ghazali',
            'created_at' => '2026-04-08 11:25:53',
            'updated_at' => '2026-04-08 11:25:53',
            'media' => [
                [
                    'source_media_id' => 141,
                    'uuid' => 'dddc67a4-b495-4280-ad57-99a05aea2b8e',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Bidayatul Hidayah',
                    'file_name' => 'bidayatul-hidayah-01knpdex.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 671434,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'image2.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['bidayatul-hidayah-01knpdex___media_library_original_1282_1863.webp', 'bidayatul-hidayah-01knpdex___media_library_original_1072_1558.webp', 'bidayatul-hidayah-01knpdex___media_library_original_897_1304.webp', 'bidayatul-hidayah-01knpdex___media_library_original_750_1090.webp', 'bidayatul-hidayah-01knpdex___media_library_original_628_913.webp', 'bidayatul-hidayah-01knpdex___media_library_original_525_763.webp', 'bidayatul-hidayah-01knpdex___media_library_original_439_638.webp', 'bidayatul-hidayah-01knpdex___media_library_original_367_533.webp', 'bidayatul-hidayah-01knpdex___media_library_original_307_446.webp', 'bidayatul-hidayah-01knpdex___media_library_original_257_373.webp', 'bidayatul-hidayah-01knpdex___media_library_original_215_312.webp', 'bidayatul-hidayah-01knpdex___media_library_original_180_262.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMTI4MiAxODYzIj4KCTxpbWFnZSB3aWR0aD0iMTI4MiIgaGVpZ2h0PSIxODYzIiB4bGluazpocmVmPSJkYXRhOmltYWdlL2pwZWc7YmFzZTY0LC85ai80QUFRU2taSlJnQUJBUUVBWUFCZ0FBRC8vZ0ErUTFKRlFWUlBVam9nWjJRdGFuQmxaeUIyTVM0d0lDaDFjMmx1WnlCSlNrY2dTbEJGUnlCMk9EQXBMQ0JrWldaaGRXeDBJSEYxWVd4cGRIa0svOXNBUXdBSUJnWUhCZ1VJQndjSENRa0lDZ3dVRFF3TEN3d1pFaE1QRkIwYUh4NGRHaHdjSUNRdUp5QWlMQ01jSENnM0tTd3dNVFEwTkI4bk9UMDRNand1TXpReS85c0FRd0VKQ1FrTUN3d1lEUTBZTWlFY0lUSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5LzhBQUVRZ0FMd0FnQXdFaUFBSVJBUU1SQWYvRUFCOEFBQUVGQVFFQkFRRUJBQUFBQUFBQUFBQUJBZ01FQlFZSENBa0tDLy9FQUxVUUFBSUJBd01DQkFNRkJRUUVBQUFCZlFFQ0F3QUVFUVVTSVRGQkJoTlJZUWNpY1JReWdaR2hDQ05Dc2NFVlV0SHdKRE5pY29JSkNoWVhHQmthSlNZbktDa3FORFUyTnpnNU9rTkVSVVpIU0VsS1UxUlZWbGRZV1ZwalpHVm1aMmhwYW5OMGRYWjNlSGw2ZzRTRmhvZUlpWXFTazVTVmxwZVltWnFpbzZTbHBxZW9xYXF5czdTMXRyZTR1YnJDdzhURnhzZkl5Y3JTMDlUVjF0ZlkyZHJoNHVQazVlYm42T25xOGZMejlQWDI5L2o1K3YvRUFCOEJBQU1CQVFFQkFRRUJBUUVBQUFBQUFBQUJBZ01FQlFZSENBa0tDLy9FQUxVUkFBSUJBZ1FFQXdRSEJRUUVBQUVDZHdBQkFnTVJCQVVoTVFZU1FWRUhZWEVUSWpLQkNCUkNrYUd4d1Frak0xTHdGV0p5MFFvV0pEVGhKZkVYR0JrYUppY29LU28xTmpjNE9UcERSRVZHUjBoSlNsTlVWVlpYV0ZsYVkyUmxabWRvYVdwemRIVjJkM2g1ZW9LRGhJV0doNGlKaXBLVGxKV1dsNWlabXFLanBLV21wNmlwcXJLenRMVzJ0N2k1dXNMRHhNWEd4OGpKeXRMVDFOWFcxOWpaMnVMajVPWG01K2pwNnZMejlQWDI5L2o1K3YvYUFBd0RBUUFDRVFNUkFEOEE1MnkwNk80aDN1YW5HazJ3T00wK3dmeTlQTCtsWk05OVBKS2Rwd0t3bk9yS28wbWM4WTAxQlhScS93QmsyM1kxV3ZOTmp0NHQ2R3FjRi9Pa2czSElyV3ZIOHl4RFk2MGxPckdvazJOeGc0dXlHMnBBMHBqN1Z6NWJMdGc5NjM3VWoreW16NlZ6aGJFckQzcTQvd0FTUW12Y2lQRFlkZWU5ZEJPYzZZdjBybkEzNzFSNzEwVTMvSU5YSHBSUCtKRUY4TWhiRmQ5Z1ZQZXM2WFNtRXBaZWxQdHRUK3p4YkN1YW1Hc3IvY3BTcFZWTnVLM0tqVXB1Q1RaVmowcGpLR2JwV3BkSUVzUW83VlcvdGhmK2VkUTNHcGZhSS9MQzRwUnBWbk5PU0NWU2tvdEpuLy9aIj4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 11:25:53',
                    'updated_at' => '2026-10-24 19:00:40',
                ],
            ],
        ],
        [
            'id' => '019d6cd9-374a-7390-aa77-fc2947a2d9c2',
            'title' => 'Hidayatus Salikin',
            'slug' => 'hidayatus-salikin',
            'author' => 'Syeikh Abdus Samad Al-Falimbani',
            'created_at' => '2026-04-08 11:27:48',
            'updated_at' => '2026-04-08 11:27:48',
            'media' => [
                [
                    'source_media_id' => 142,
                    'uuid' => '68ce3cd2-3f88-4083-82ba-6decff2e1852',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Hidayatus Salikin',
                    'file_name' => 'hidayatus-salikin-01knpdjd.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 88576,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'my-11134207-23010-cqvhw856g4lv8a-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['hidayatus-salikin-01knpdjd___media_library_original_561_800.webp', 'hidayatus-salikin-01knpdjd___media_library_original_469_669.webp', 'hidayatus-salikin-01knpdjd___media_library_original_392_559.webp', 'hidayatus-salikin-01knpdjd___media_library_original_328_468.webp', 'hidayatus-salikin-01knpdjd___media_library_original_274_391.webp', 'hidayatus-salikin-01knpdjd___media_library_original_229_327.webp', 'hidayatus-salikin-01knpdjd___media_library_original_192_274.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNTYxIDgwMCI+Cgk8aW1hZ2Ugd2lkdGg9IjU2MSIgaGVpZ2h0PSI4MDAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxnQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QW82Wll4elI1UFd0TmRHNHp0NHB2aHVOWkFNMTFMc29HM0Zja3BOTTFxNGh4bFpITW5SZU03ZUtwYWhZUnd4ZkwxcnMwY0g1U3ZGWUd1eEtwTzJuR1RiQ2ppSEtWbVZ2RFFPeml1bGFPUmowcm5QRExZcnJkeDcxTXR6bHhNclZDRVJ1Rnhpc0xYRUs5YTZUZWUxYzVyNzVhbkRjV0ZsZW9aV2d6bUpRdzZWMGttcEJvc3B5YTgydGRYbGhqd29xN2JlSTVZQ2N4N3FwMDd1NTIxS0hPN25lcHFZU0hMOEdzRFdMb3lBc2VsYzlkZUlwWmlNSnRxcFBxOHM2WWFxaFRzN2hUb2NrcjJQL1oiPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 11:27:48',
                    'updated_at' => '2026-10-24 19:00:41',
                ],
            ],
        ],
        [
            'id' => '019d6cdb-56ac-7330-89d2-03cfe40bfa34',
            'title' => 'Seperti Air yang Mengalir',
            'slug' => 'seperti-air-yang-mengalir',
            'author' => 'Pahrol Mohd Juoi',
            'description' => 'Buku ini membawa pembaca menyelami perjalanan hati dalam menghadapi kehidupan seharian dengan pendekatan yang lembut, reflektif dan penuh muhasabah. Penulis mengajak pembaca memahami hakikat keikhlasan, kesabaran, redha dan hubungan dengan Allah melalui contoh-contoh kehidupan yang dekat dengan realiti. Gaya penulisannya santai tetapi menyentuh jiwa, menjadikannya sesuai untuk pembacaan umum yang ingin mencari ketenangan, memperbaiki diri dan menghayati nilai-nilai Islam dalam kehidupan seharian.',
            'created_at' => '2026-04-08 11:30:07',
            'updated_at' => '2026-04-20 08:15:01',
            'media' => [
                [
                    'source_media_id' => 143,
                    'uuid' => '77ed7e78-c7f8-4d93-a007-f08b8e174ab8',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Seperti Air Yang Mengalir',
                    'file_name' => 'seperti-air-yang-mengalir-01knpdpn.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 65847,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'FULL-COVER-Seperti-Air-yang-Mengalir-front.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['seperti-air-yang-mengalir-01knpdpn___media_library_original_400_600.jpg', 'seperti-air-yang-mengalir-01knpdpn___media_library_original_334_501.jpg', 'seperti-air-yang-mengalir-01knpdpn___media_library_original_280_420.jpg', 'seperti-air-yang-mengalir-01knpdpn___media_library_original_234_351.jpg', 'seperti-air-yang-mengalir-01knpdpn___media_library_original_195_293.jpg', 'seperti-air-yang-mengalir-01knpdpn___media_library_original_163_245.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNDAwIDYwMCI+Cgk8aW1hZ2Ugd2lkdGg9IjQwMCIgaGVpZ2h0PSI2MDAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQU1BQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTR2RkdNMU5iQU0yRFVrcWhTUUZyMzdueWx0TGxUT0RXZ2tzSDJNcVI4OVVTS0FPS21VZVljWmNvSzVUb2EyOURzNDlSdU5rcjRyQ3F6YXp5VzBnZU5pQ0tpdkNVNE5RZG1hWWVjWVZFNXE2TmZ4RHBrV255QlkyQnJEMkhibXJOM2R5M2JicEdKTlZTNXhpcG9RbkNtb3pkMlhYblRuVWNvcXlFUVpiRktRUWNVc2JBZGFmdlQwcmJxYzYySXNta3FZc2hIU29UMXBvVFAvL1oiPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 11:30:07',
                    'updated_at' => '2026-10-24 19:00:41',
                ],
            ],
        ],
        [
            'id' => '019d6ceb-5296-72d9-8db8-8ce9c1e153ba',
            'title' => 'Mukjizat Saintifik Dalam Al-Quran',
            'slug' => 'mukjizat-saintifik-dalam-al-quran',
            'created_at' => '2026-04-08 11:47:34',
            'updated_at' => '2026-04-08 11:47:34',
        ],
        [
            'id' => '019d6cf0-317f-7211-8a6b-b597e450c11c',
            'title' => 'Usul Dakwah',
            'slug' => 'usul-dakwah',
            'author' => 'Dr Abdul Karim Zaidan',
            'created_at' => '2026-04-08 11:52:53',
            'updated_at' => '2026-04-08 11:52:53',
            'media' => [
                [
                    'source_media_id' => 144,
                    'uuid' => '35b79975-493d-4363-9d20-70bb2f39e0d1',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Usul Dakwah',
                    'file_name' => 'usul-dakwah-01knpf0c.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 56342,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'zaidan-03-v1.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['usul-dakwah-01knpf0c___media_library_original_311_447.jpg', 'usul-dakwah-01knpf0c___media_library_original_260_374.jpg', 'usul-dakwah-01knpf0c___media_library_original_217_312.jpg', 'usul-dakwah-01knpf0c___media_library_original_182_262.jpg', 'usul-dakwah-01knpf0c___media_library_original_152_218.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMzExIDQ0NyI+Cgk8aW1hZ2Ugd2lkdGg9IjMxMSIgaGVpZ2h0PSI0NDciIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxnQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QXhONDh2SnF0NW02VDJxT1NZN1Fvb0FaVTNBVndKRy9ORjZJSkdiek9GNHFhNHg5bEI3MFJ4TkpBWDlLUzQvNDlSbWhTdTdDbEMwV3g5dmIrZkVjTHpVeGhkSXRtM21xOXZkdEJnRGdWTExmU01RMVp0VHY1SFJDTU9XNzNKSTQzanRYM0RGVmJuL2owRldCY3ZMRXdicFVGMS94NkRGS21tcGFrMTdjbGtWanl3cVYxSVRwUWtxNHlVNXFYemxLL2RyWnBHY2F0Ukt5WXkyT1kyRlRYQ2Y2SlVQMmdLRHRXbTNGeVd0Z3VLbEo4MmdwenV2ZTNQLy9aIj4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 11:52:53',
                    'updated_at' => '2026-10-24 19:00:41',
                ],
            ],
        ],
        [
            'id' => '019d6cf4-5b94-7319-a848-e157f22621ce',
            'title' => 'Al-Bidaya Wan-Nihayah',
            'slug' => 'al-bidaya-wan-nihayah',
            'author' => 'Ismail bin Umar bin Katsir',
            'created_at' => '2026-04-08 11:57:26',
            'updated_at' => '2026-04-08 11:57:26',
            'media' => [
                [
                    'source_media_id' => 145,
                    'uuid' => '9dd87ce4-bbf6-44fc-9040-f12e90b65d6d',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Al Bidaya Wan Nihayah',
                    'file_name' => 'al-bidaya-wan-nihayah-01knpf8p.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 15159,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'البداية والنهاية_.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['al-bidaya-wan-nihayah-01knpf8p___media_library_original_189_267.jpg', 'al-bidaya-wan-nihayah-01knpf8p___media_library_original_158_223.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMTg5IDI2NyI+Cgk8aW1hZ2Ugd2lkdGg9IjE4OSIgaGVpZ2h0PSIyNjciIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxRQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTRTeXQ0V3RRV0F6VFpKYldGOXBGTXRtSDJaUWMweWRiWlQ4eHlUV0Z0VFpUMExNYzlyS2NBWXFQVTFRV3VVcW4rNExmdXljMUpkSE5pYWR0Uk9laExIeHArN3VLb0orK09XSE5UeTNCaTA3QUZaMXJkTXorMVVrWXgzMUxUL3VYQkE2MWR1d1A3UEI5YXpidTVBSXE2OC9uYWNPS0xiTUh1SWJkcDdIYUt6bzdKNHBjR3RLMnVHV0FEQXBzaitZT1JTVGFOdVNOdENnOW5KTkxoYTBKWURCWWJUMXAwVGVXdkFvdlpTOEdEUmR0ZzRLMXovL1oiPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 11:57:26',
                    'updated_at' => '2026-10-24 19:00:39',
                ],
            ],
        ],
        [
            'id' => '019d6cf5-c2b6-7186-ba49-beb80e633028',
            'title' => 'Sirah Nabi Muhammad S.A.W – Pengajaran & Pedoman',
            'slug' => 'sirah-nabi-muhammad-saw-pengajaran-pedoman',
            'author' => 'Dr. Mustafa as-Syibaie',
            'created_at' => '2026-04-08 11:58:58',
            'updated_at' => '2026-04-08 11:58:58',
            'media' => [
                [
                    'source_media_id' => 146,
                    'uuid' => 'ca01c619-5c3b-4bda-a01e-e91be3986e15',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Sirah Nabi Muhammad S.a.w – Pengajaran & Pedoman',
                    'file_name' => 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg.png',
                    'mime_type' => 'image/png',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 804575,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/png', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'e72e86b2ab6c5db4aadaba4916915a3c.png'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_557_844.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_466_706.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_389_589.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_326_494.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_272_412.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_228_345.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_191_289.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_159_241.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_133_202.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_111_168.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_93_141.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_78_118.png', 'sirah-nabi-muhammad-saw-pengajaran-pedoman-01knpfbg___media_library_original_65_98.png'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNTU3IDg0NCI+Cgk8aW1hZ2Ugd2lkdGg9IjU1NyIgaGVpZ2h0PSI4NDQiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQU1BQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QThoV0lEQk5YSXJlTnhuYVRWWXh2SXFoUld6WndUUXdaYVBOWFd4VTR6YXVPblFoS0NiTTZTR0pRUVFRYXFQRUZVc09sYXQxRkxLaGRZelZCbzNFQkxDblF4TXB6NVJWc1BDRUxvdjJNSWVOVzlLNktLYjl5RTJnOFZqNmNRTFRPT2FsZVIrTmo0TmNPTGh6VlhjNjhNN1UxWXZYRW5sd01Ob0ZjL2NIZEEzMXE2MHMwcU1KRzRxbE9OdHVhdkJRVWFxc1RpNU4wOVRXMG1LSjdNRm14VGJ5MmpXUU1qVlFzZFFodDROcmpKcTBOV3N5Zm1VMTBWc0pWblViUmpTeE5PRUVLK3dLQjJxdGR4b3R2bFRVeDFPekorN1ZhL3ZJSllkc1F4VHcrRnEwNnFiV2dxK0pwMUtiUzNQL1oiPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 11:58:58',
                    'updated_at' => '2026-10-24 19:00:34',
                ],
            ],
        ],
        [
            'id' => '019d6d15-8a66-713e-8f3f-19f83e617ea6',
            'title' => 'Mughni Al-Muhtaj ila Ma’rifah Ma’ani Alfazh Al-Minhaj',
            'slug' => 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj',
            'author' => 'Syekh Syamsuddin Al-Khathib Al-Syarbini',
            'created_at' => '2026-04-08 12:33:41',
            'updated_at' => '2026-04-08 12:33:58',
            'media' => [
                [
                    'source_media_id' => 147,
                    'uuid' => 'e71bfd6b-0db5-48b0-b1b0-d4119d70ced3',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Mughni Al Muhtaj Ila Ma’rifah Ma’ani Alfazh Al Minhaj',
                    'file_name' => 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 950026,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '594170053719896a11eb08ee513813d5.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_1300_1920.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_1087_1605.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_909_1343.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_761_1124.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_636_939.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_532_786.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_445_657.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_373_551.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_312_461.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_261_385.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_218_322.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_182_269.webp', 'mughni-al-muhtaj-ila-marifah-maani-alfazh-al-minhaj-01knphbk___media_library_original_152_224.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMTMwMCAxOTIwIj4KCTxpbWFnZSB3aWR0aD0iMTMwMCIgaGVpZ2h0PSIxOTIwIiB4bGluazpocmVmPSJkYXRhOmltYWdlL2pwZWc7YmFzZTY0LC85ai80QUFRU2taSlJnQUJBUUVBWUFCZ0FBRC8vZ0ErUTFKRlFWUlBVam9nWjJRdGFuQmxaeUIyTVM0d0lDaDFjMmx1WnlCSlNrY2dTbEJGUnlCMk9EQXBMQ0JrWldaaGRXeDBJSEYxWVd4cGRIa0svOXNBUXdBSUJnWUhCZ1VJQndjSENRa0lDZ3dVRFF3TEN3d1pFaE1QRkIwYUh4NGRHaHdjSUNRdUp5QWlMQ01jSENnM0tTd3dNVFEwTkI4bk9UMDRNand1TXpReS85c0FRd0VKQ1FrTUN3d1lEUTBZTWlFY0lUSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5LzhBQUVRZ0FMd0FnQXdFaUFBSVJBUU1SQWYvRUFCOEFBQUVGQVFFQkFRRUJBQUFBQUFBQUFBQUJBZ01FQlFZSENBa0tDLy9FQUxVUUFBSUJBd01DQkFNRkJRUUVBQUFCZlFFQ0F3QUVFUVVTSVRGQkJoTlJZUWNpY1JReWdaR2hDQ05Dc2NFVlV0SHdKRE5pY29JSkNoWVhHQmthSlNZbktDa3FORFUyTnpnNU9rTkVSVVpIU0VsS1UxUlZWbGRZV1ZwalpHVm1aMmhwYW5OMGRYWjNlSGw2ZzRTRmhvZUlpWXFTazVTVmxwZVltWnFpbzZTbHBxZW9xYXF5czdTMXRyZTR1YnJDdzhURnhzZkl5Y3JTMDlUVjF0ZlkyZHJoNHVQazVlYm42T25xOGZMejlQWDI5L2o1K3YvRUFCOEJBQU1CQVFFQkFRRUJBUUVBQUFBQUFBQUJBZ01FQlFZSENBa0tDLy9FQUxVUkFBSUJBZ1FFQXdRSEJRUUVBQUVDZHdBQkFnTVJCQVVoTVFZU1FWRUhZWEVUSWpLQkNCUkNrYUd4d1Frak0xTHdGV0p5MFFvV0pEVGhKZkVYR0JrYUppY29LU28xTmpjNE9UcERSRVZHUjBoSlNsTlVWVlpYV0ZsYVkyUmxabWRvYVdwemRIVjJkM2g1ZW9LRGhJV0doNGlKaXBLVGxKV1dsNWlabXFLanBLV21wNmlwcXJLenRMVzJ0N2k1dXNMRHhNWEd4OGpKeXRMVDFOWFcxOWpaMnVMajVPWG01K2pwNnZMejlQWDI5L2o1K3YvYUFBd0RBUUFDRVFNUkFEOEE1SzJqaEZzQzNXcXR4Y3BEOXhjbW9yaDNXMVRhY1ZYV1ZVSTM4MTVzWTMxTDVpNURlSkx3NjROUzNld1d4WlFLejVKSTlwS2ptbWlSMnRHM0hOVTQyMUZ6YVdMdDBtL1NneTlSV0JHenZ3NTRyYnZKZkwwa0RQSnJDaFlGVGs0cXFLOTFrZFJKbmRPRlBGYXlKdDB6SjZtc1c0WURHRG10bUIvTTB2SGNVNm15RjFMTjNiUFBweTdCbkZZQzJrck9Vd1JYU1dtcFJMYWhXVW1vM250M0pJVWcvU3BoS1VicXhvNHJvYzgxbEtKQXVDYTI0clY3ZlR6dUZUeHpXNkVFcVNmcFNhaHFNYldwVlZJcHlsS1ZsWVhLai8vWiI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 12:33:58',
                    'updated_at' => '2026-10-24 19:02:03',
                ],
            ],
        ],
        [
            'id' => '019d6d17-6672-71a8-936a-9475aec8bbfe',
            'title' => 'Ihya\' Ulumuddin',
            'slug' => 'ihya-ulumuddin',
            'author' => 'Imam al-Ghazālī ',
            'created_at' => '2026-04-08 12:35:43',
            'updated_at' => '2026-04-08 12:35:43',
            'media' => [
                [
                    'source_media_id' => 148,
                    'uuid' => '9f41f63b-adba-471e-9045-8755a4f68d9c',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Ihya\' Ulumuddin',
                    'file_name' => 'ihya-ulumuddin-01knphes.jpeg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 10273,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'images (2).jpeg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['ihya-ulumuddin-01knphes___media_library_original_187_270.jpeg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMTg3IDI3MCI+Cgk8aW1hZ2Ugd2lkdGg9IjE4NyIgaGVpZ2h0PSIyNzAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxnQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTg1dG9rTVFKRlRpSkIycXZibmJFRFVja3JUUHRSc1lyamU1Mm92aUpNZEtwM3FJc1p3S0lyaG8zRWJIZG1tM3B6RWFhM0V4MEovYzgxVU53aVNFQmVhc3hjd1ZsU2tpWTVwcGFoY3Z4M0VaY0FMejYwKzg1aU5VYllremlyOTMvQUtxbmF6RXg5c014aW83aXlXUnR5bm1pS1hhbU1WSUpmYXAxdU1TMXNraU80OWFMMGZ1amlsTTVIYW9wNUM4ZUthdmNUUC9aIj4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 12:35:43',
                    'updated_at' => '2026-04-08 12:35:45',
                ],
            ],
        ],
        [
            'id' => '019d6d1f-7703-7359-b265-51dfd1c3a76e',
            'title' => 'Mukhtashar Tafsir Ibnu Katsir',
            'slug' => 'mukhtashar-tafsir-ibnu-katsir',
            'author' => 'Imam Ibnu Katsir',
            'description' => 'Kitab ini merupakan ringkasan kepada tafsir terkenal karya Imam Ibnu Katsir yang menghuraikan ayat-ayat Al-Quran berdasarkan pendekatan tafsir bil-ma’thur, iaitu dengan merujuk kepada Al-Quran, hadis Nabi, serta pandangan para sahabat dan ulama salaf. Ia membantu pembaca memahami maksud ayat secara sahih, jelas dan berautoriti, tanpa bergantung kepada tafsiran akal semata-mata. Kitab ini sering dijadikan rujukan asas dalam pengajian tafsir kerana manhajnya yang seimbang dan berpegang teguh kepada sumber-sumber yang muktabar.',
            'created_at' => '2026-04-08 12:44:31',
            'updated_at' => '2026-04-20 08:14:02',
            'media' => [
                [
                    'source_media_id' => 149,
                    'uuid' => '638ee3c6-0141-48f5-beec-3eb250977fcf',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Mukhtashar Tafsir Ibnu Katsir',
                    'file_name' => 'mukhtashar-tafsir-ibnu-katsir-01knphyx.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 229445,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'tafsiribnkathir1.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['mukhtashar-tafsir-ibnu-katsir-01knphyx___media_library_original_600_800.jpg', 'mukhtashar-tafsir-ibnu-katsir-01knphyx___media_library_original_501_668.jpg', 'mukhtashar-tafsir-ibnu-katsir-01knphyx___media_library_original_420_560.jpg', 'mukhtashar-tafsir-ibnu-katsir-01knphyx___media_library_original_351_468.jpg', 'mukhtashar-tafsir-ibnu-katsir-01knphyx___media_library_original_294_392.jpg', 'mukhtashar-tafsir-ibnu-katsir-01knphyx___media_library_original_245_327.jpg', 'mukhtashar-tafsir-ibnu-katsir-01knphyx___media_library_original_205_273.jpg', 'mukhtashar-tafsir-ibnu-katsir-01knphyx___media_library_original_172_229.jpg', 'mukhtashar-tafsir-ibnu-katsir-01knphyx___media_library_original_144_192.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNjAwIDgwMCI+Cgk8aW1hZ2Ugd2lkdGg9IjYwMCIgaGVpZ2h0PSI4MDAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUt3QWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QXh0TTBpR1d6Vm1YbXBiblRiTzFqTFBpbWYydkhZV1NKajVqU29pNmtvZVJ1UFROWXliaTd2WTdxVkNOV05vYm1XMXpZTC9EeFZiV0REOWpFa0lyWHVOSHRtamJHS3hybXlLV2pLRGtaNHFvU1Vub1oxOE5Pa3J5THVxMkR6SW0wVVcxbGRSeDdRNS9PcnV1TkpERWlvY0dzbEpyaU9MY1pqdXJTU1puQnBGMTdhOTJFYi8xcDRpWnJIYTNMRHJXUkZkWDkxY2VYR3pIUHBYWWFmbzhzZHB1bjVKOWFjVlo2aW5MbVdndXFXSXVZbFk5UldPdWtOTSt6b0syYitSeEh3eHJIaW5sM241elRkN21WMGRIcFdrMldub0crVXllcHJabGtSNFBsSXJoamN6YnY5WTM1MXBXVThyS0FYSnFWQzhybW50V29jcC8vMlE9PSI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 12:44:32',
                    'updated_at' => '2026-10-24 19:00:31',
                ],
            ],
        ],
        [
            'id' => '019d6d23-b727-7373-9e16-d62240c49463',
            'title' => 'Nashaihul \'Ibad',
            'slug' => 'nashaihul-ibad',
            'author' => 'Shaykh Muhammad Nawawi Al-Jawi al-Bantani',
            'created_at' => '2026-04-08 12:49:10',
            'updated_at' => '2026-04-08 12:49:50',
            'media' => [
                [
                    'source_media_id' => 150,
                    'uuid' => '4365d4da-0dfe-4571-92e1-78705a1a1af0',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Nashaihul \'Ibad',
                    'file_name' => 'nashaihul-ibad-01knpj8m.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 166142,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'image_74f1a069-aeb3-4c08-aa39-2d1ac091540b_800x-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['nashaihul-ibad-01knpj8m___media_library_original_757_1144.webp', 'nashaihul-ibad-01knpj8m___media_library_original_633_957.webp', 'nashaihul-ibad-01knpj8m___media_library_original_529_799.webp', 'nashaihul-ibad-01knpj8m___media_library_original_443_669.webp', 'nashaihul-ibad-01knpj8m___media_library_original_370_559.webp', 'nashaihul-ibad-01knpj8m___media_library_original_310_468.webp', 'nashaihul-ibad-01knpj8m___media_library_original_259_391.webp', 'nashaihul-ibad-01knpj8m___media_library_original_217_328.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNzU3IDExNDQiPgoJPGltYWdlIHdpZHRoPSI3NTciIGhlaWdodD0iMTE0NCIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBTUFBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBeFk0VTJqaXBQTGhIWEZOOHdiUWxLSWtQM21yaHMzdXlaVklSU1NpUEVVWlBBelVqVzhaalBITlFqRVJ5R3lLdGJ0MFdhbDNYVTFwdW5PTDAxTW00YmF3cHU4bnVhVzZIekNrVmZsNE5kTWJXUE5xWDVtTDVoeUJXcW4vSHRXUGo1eHpXeEgveDdENlZuVld4MVlSL0VVTHVFc2dZVkJHamJhdnh1cklNMUtCRmlrcWpXakhQRHhuYVNabVJSTThvQkZhKzNiQmltRFlEa1lxUjNYeWp6VXprNU5HMUdsR2xCNm4vMlE9PSI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 12:49:50',
                    'updated_at' => '2026-10-24 19:00:45',
                ],
            ],
        ],
        [
            'id' => '019d6d29-47f5-7174-b59f-e6aa8e31ca12',
            'title' => 'Risalah Muqtadho As-Syahadatain',
            'slug' => 'risalah-muqtadho-as-syahadatain',
            'created_at' => '2026-04-08 12:55:15',
            'updated_at' => '2026-04-08 12:55:15',
        ],
        [
            'id' => '019d6d2c-7032-7019-b002-418a25fe0ee2',
            'title' => 'Peringatan Tentang Umur Insan',
            'slug' => 'peringatan-tentang-umur-insan',
            'author' => 'Imam Habib Abdullah Haddad',
            'created_at' => '2026-04-08 12:58:42',
            'updated_at' => '2026-04-08 12:58:42',
            'media' => [
                [
                    'source_media_id' => 151,
                    'uuid' => '589efb35-3164-4dae-ba4a-5120a4155311',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Peringatan Tentang Umur Insan',
                    'file_name' => 'peringatan-tentang-umur-insan-01knpjrw.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 8124,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'sg-11134201-23010-gqjcgnz2fxlvec-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['peringatan-tentang-umur-insan-01knpjrw___media_library_original_276_420.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMjc2IDQyMCI+Cgk8aW1hZ2Ugd2lkdGg9IjI3NiIgaGVpZ2h0PSI0MjAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQU1RQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTh3RWNXMGZOelR4RkQvZXFwM3FWUU1kYWthUk41TVdmdlZITkdpZmRPYWt0WXhKT0ZKNG92WTFpbTJyMHBYMXNQbGRya1p0am5yVGhiSEhXblhEN0pSaXBGZmN0RGRoeGltTVNFb3dJYWk0UnBQbXowcDdOdFhPYVlybGtZMExVSkt5c1JYaC9lVTZCd0Y2MUhPVmtmSU5JaXFPOU5vVVhZbG1jRmVEU1JuOTAxTlpWSTYwbVZTSWpQTkNRbTdzcjk2Y3RGRk1rZlVUMFVVd1AvOWs9Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 12:58:42',
                    'updated_at' => '2026-04-08 12:58:42',
                ],
            ],
        ],
        [
            'id' => '019d6d39-a8ab-735e-a542-5274740a95e2',
            'title' => 'Timeline Akhir Zaman',
            'slug' => 'timeline-akhir-zaman',
            'author' => 'Ustaz Dr. Ashraff Amirul',
            'created_at' => '2026-04-08 13:13:08',
            'updated_at' => '2026-04-08 13:13:08',
            'media' => [
                [
                    'source_media_id' => 152,
                    'uuid' => '3c41907f-6c0e-4e6e-8787-e8d619135ada',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Timeline Akhir Zaman',
                    'file_name' => 'timeline-akhir-zaman-01knpkka.jpg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 114332,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => '9789672739326-v1.jpg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['timeline-akhir-zaman-01knpkka___media_library_original_651_1000.jpg', 'timeline-akhir-zaman-01knpkka___media_library_original_544_836.jpg', 'timeline-akhir-zaman-01knpkka___media_library_original_455_699.jpg', 'timeline-akhir-zaman-01knpkka___media_library_original_381_585.jpg', 'timeline-akhir-zaman-01knpkka___media_library_original_318_488.jpg', 'timeline-akhir-zaman-01knpkka___media_library_original_266_409.jpg', 'timeline-akhir-zaman-01knpkka___media_library_original_223_343.jpg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNjUxIDEwMDAiPgoJPGltYWdlIHdpZHRoPSI2NTEiIGhlaWdodD0iMTAwMCIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBTVFBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBOCtoQmtrMjFaZXpjS1NBYXEyNzdiaGZyWFVwTkFzS2hnRHhUSnNjazhiaHNZTk9KQ3g0STVybzJOcHVMYkFheE5Ta2phYkVZd0tWeDJLa1oyenFhMy9NRHdxQU8xYzYvRFZNTHVRS0FEU2tyaEYyTmJEQUhwV1JkRUNVODVOTmE2bEl4dU5SWjNISnBSaTBOeUpaVUlhbWVVNUdjR3JjdUZmMXFSYnBkbTNhS3E0V00wb3c2aWdERldwcGdlZ3F2Z25tbUt4YW0rK2FpTkZGSkF4alU5UHUwVVVNdUovL1oiPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 13:13:08',
                    'updated_at' => '2026-10-24 19:00:47',
                ],
            ],
        ],
        [
            'id' => '019d6d3e-4bf5-708c-8a9b-143dc3cdbb8b',
            'title' => 'Soal Jawab Solat Sunat',
            'slug' => 'soal-jawab-solat-sunat',
            'created_at' => '2026-04-08 13:18:12',
            'updated_at' => '2026-04-08 13:18:12',
        ],
        [
            'id' => '019d6d48-d1b7-7335-869f-f0da2536f809',
            'title' => 'Al-Bayan Fatwa Syeikh Ali Jum\'ah',
            'slug' => 'al-bayan-fatwa-syeikh-ali-jumah',
            'author' => 'Prof Dr Ali Jum\'ah',
            'created_at' => '2026-04-08 13:29:42',
            'updated_at' => '2026-04-08 13:29:42',
            'media' => [
                [
                    'source_media_id' => 153,
                    'uuid' => '6048c95e-5fee-4153-aa07-25e46d36a727',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Al Bayan Fatwa Syeikh Ali Jum\'ah',
                    'file_name' => 'al-bayan-fatwa-syeikh-ali-jumah-01knpmhm.jpeg',
                    'mime_type' => 'image/jpeg',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 103837,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'asd-v1.jpeg'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['al-bayan-fatwa-syeikh-ali-jumah-01knpmhm___media_library_original_551_800.jpeg', 'al-bayan-fatwa-syeikh-ali-jumah-01knpmhm___media_library_original_460_668.jpeg', 'al-bayan-fatwa-syeikh-ali-jumah-01knpmhm___media_library_original_385_559.jpeg', 'al-bayan-fatwa-syeikh-ali-jumah-01knpmhm___media_library_original_322_468.jpeg', 'al-bayan-fatwa-syeikh-ali-jumah-01knpmhm___media_library_original_269_391.jpeg', 'al-bayan-fatwa-syeikh-ali-jumah-01knpmhm___media_library_original_225_327.jpeg', 'al-bayan-fatwa-syeikh-ali-jumah-01knpmhm___media_library_original_188_273.jpeg'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNTUxIDgwMCI+Cgk8aW1hZ2Ugd2lkdGg9IjU1MSIgaGVpZ2h0PSI4MDAiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxnQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QThMMmttcmx2WlBPakVEcFdubzJtcmR5RlNNbXV6c3RCaHQ3WXE2Z0ZxeWxVUzBIRzdQTjRiSjU1L0tDbk5KZldEMlV1eHE5TmJRN2EzSWtWUm11UThVVzUrMUFnY1VScUp2UWJWbHFhUGhxREY1WG9ndFZraFVkNjR2d3FZdHhkaGsxMkVGL0dzMkNSaXNLbEp1VndqRnFGeWE2c0FscGtqbkZlZCtJSUM4aHIwdTkxSzNOdGdzT2xlZGEvY3hPNTJIdlRwMDdPNG5xTnNHWFNiYzdzbHpTUWFuTkxNV0liR2E5Ui93Q0VkMDZZNWFFR2tuOFA2ZGJ4YmtnWFAwcnFiVExkN1dQT1crM1hwMlFvNXpXaFllQ3A3aGhKZGtnSHRYZFdGcEJDbVZqWFAwcXhNK0NBQnhTRlkvL1oiPgoJPC9pbWFnZT4KPC9zdmc+']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 13:29:42',
                    'updated_at' => '2026-10-24 19:00:49',
                ],
            ],
        ],
        [
            'id' => '019d6d4b-7668-703c-a22b-dc086cd459f5',
            'title' => 'Rasional Sunnah Rasulullah Sallallahu Alayhi Wasallam',
            'slug' => 'rasional-sunnah-rasulullah-sallallahu-alayhi-wasallam',
            'author' => 'Rusdi Ramli Al-Jauhari',
            'created_at' => '2026-04-08 13:32:35',
            'updated_at' => '2026-04-08 13:32:35',
            'media' => [
                [
                    'source_media_id' => 154,
                    'uuid' => '89ba73c6-c726-4531-a87f-185557180112',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Rasional Sunnah Rasulullah Sallallahu Alayhi Wasallam',
                    'file_name' => 'rasional-sunnah-rasulullah-sallallahu-alayhi-wasallam-01knpmpx.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 40724,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'asdasd-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['rasional-sunnah-rasulullah-sallallahu-alayhi-wasallam-01knpmpx___media_library_original_642_1024.webp', 'rasional-sunnah-rasulullah-sallallahu-alayhi-wasallam-01knpmpx___media_library_original_537_857.webp', 'rasional-sunnah-rasulullah-sallallahu-alayhi-wasallam-01knpmpx___media_library_original_449_716.webp', 'rasional-sunnah-rasulullah-sallallahu-alayhi-wasallam-01knpmpx___media_library_original_375_598.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNjQyIDEwMjQiPgoJPGltYWdlIHdpZHRoPSI2NDIiIGhlaWdodD0iMTAyNCIgeGxpbms6aHJlZj0iZGF0YTppbWFnZS9qcGVnO2Jhc2U2NCwvOWovNEFBUVNrWkpSZ0FCQVFFQVlBQmdBQUQvL2dBK1ExSkZRVlJQVWpvZ1oyUXRhbkJsWnlCMk1TNHdJQ2gxYzJsdVp5QkpTa2NnU2xCRlJ5QjJPREFwTENCa1pXWmhkV3gwSUhGMVlXeHBkSGtLLzlzQVF3QUlCZ1lIQmdVSUJ3Y0hDUWtJQ2d3VURRd0xDd3daRWhNUEZCMGFIeDRkR2h3Y0lDUXVKeUFpTENNY0hDZzNLU3d3TVRRME5COG5PVDA0TWp3dU16UXkvOXNBUXdFSkNRa01Dd3dZRFEwWU1pRWNJVEl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeS84QUFFUWdBTXdBZ0F3RWlBQUlSQVFNUkFmL0VBQjhBQUFFRkFRRUJBUUVCQUFBQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVFBQUlCQXdNQ0JBTUZCUVFFQUFBQmZRRUNBd0FFRVFVU0lURkJCaE5SWVFjaWNSUXlnWkdoQ0NOQ3NjRVZVdEh3SkROaWNvSUpDaFlYR0JrYUpTWW5LQ2txTkRVMk56ZzVPa05FUlVaSFNFbEtVMVJWVmxkWVdWcGpaR1ZtWjJocGFuTjBkWFozZUhsNmc0U0Zob2VJaVlxU2s1U1ZscGVZbVpxaW82U2xwcWVvcWFxeXM3UzF0cmU0dWJyQ3c4VEZ4c2ZJeWNyUzA5VFYxdGZZMmRyaDR1UGs1ZWJuNk9ucThmTHo5UFgyOS9qNSt2L0VBQjhCQUFNQkFRRUJBUUVCQVFFQUFBQUFBQUFCQWdNRUJRWUhDQWtLQy8vRUFMVVJBQUlCQWdRRUF3UUhCUVFFQUFFQ2R3QUJBZ01SQkFVaE1RWVNRVkVIWVhFVElqS0JDQlJDa2FHeHdRa2pNMUx3RldKeTBRb1dKRFRoSmZFWEdCa2FKaWNvS1NvMU5qYzRPVHBEUkVWR1IwaEpTbE5VVlZaWFdGbGFZMlJsWm1kb2FXcHpkSFYyZDNoNWVvS0RoSVdHaDRpSmlwS1RsSldXbDVpWm1xS2pwS1dtcDZpcHFyS3p0TFcydDdpNXVzTER4TVhHeDhqSnl0TFQxTlhXMTlqWjJ1TGo1T1htNStqcDZ2THo5UFgyOS9qNSt2L2FBQXdEQVFBQ0VRTVJBRDhBM3RXdjFsenpYSlhLUE14MnFTS3ROZGVmT3FrOEUxMTJsNlBielFna0E4VjJWYXFndE56Q0NjMmVYM1VFc2VjcWNVV1VxcndldGVtYXhvTUN3azdCZ2l2T0xtM1cydm1RZE0xaENyejZQUmxUanlhamJhOVAybENUM3IwdlFkU2pqalVNd0trVjVLYlppZmtKcS9hNmplMktCY2tnVk5lRGxhVWQwUlFxSmFNOWIxclVyZDdRcXJEcFhqK3NYeWZibktuT0RVOXpyZDFkUkZBU00xeTkzRlBHNWtjNXpVMGFjcjgwdHlxdFJOY3NUckprV0NMN3ZOWjB0NkF1Q0tzNnRxVFJTQmRuQnFDTzArMXdid3VDYTJhUndwenAwMVB1Wmt0OEZQQXFqZVg1bFRBRlg3clRXakpPRFdhOEJEZmROTDNUZU5hOGJzOVAxS3h0V2xYZENoL0N0UzBzcllXNmdRb09QU2lpc3lxeS9kb2l1TEcxSU9ZRS9Lc3ViVHJQUC9IdW41VVVVR1ZOSS8vWiI+Cgk8L2ltYWdlPgo8L3N2Zz4=']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 13:32:35',
                    'updated_at' => '2026-10-24 19:00:43',
                ],
            ],
        ],
        [
            'id' => '019d6d4c-202d-711a-9bd4-146d0a6a38b1',
            'title' => 'Hadis Qudsi',
            'slug' => 'hadis-qudsi',
            'created_at' => '2026-04-08 13:33:18',
            'updated_at' => '2026-04-08 13:33:18',
        ],
        [
            'id' => '019d6d9a-0de9-7377-b74b-abf0d508f527',
            'title' => 'Fiqh Muamalat',
            'slug' => 'fiqh-muamalat',
            'description' => 'Kitab ini membincangkan hukum-hakam Islam berkaitan muamalat, iaitu interaksi sesama manusia dalam urusan kehidupan seperti jual beli, hutang, sewaan, perkongsian, wakaf, hibah dan transaksi kewangan yang lain. Perbahasannya bertujuan memastikan setiap urusan berlangsung secara adil, telus dan mematuhi syariat, di samping mengelakkan unsur riba, gharar, penipuan dan kezaliman. Ia menjadi rujukan penting dalam memahami cara mengurus harta, perniagaan dan hubungan sosial menurut prinsip fiqh Islam.',
            'created_at' => '2026-04-08 14:58:25',
            'updated_at' => '2026-04-20 08:13:34',
        ],
        [
            'id' => '019d6d9e-a0bd-70ea-8e0b-50bb156b29e7',
            'title' => 'Mukhtashar Riyadhus Shalihin',
            'slug' => 'mukhtashar-riyadhus-shalihin',
            'created_at' => '2026-04-08 15:03:25',
            'updated_at' => '2026-04-08 15:17:43',
        ],
        [
            'id' => '019d6da2-e37c-71c7-aa65-a43dba4e6e73',
            'title' => 'Sirah Nabawiyyah',
            'slug' => 'sirah-nabawiyyah',
            'created_at' => '2026-04-08 15:08:04',
            'updated_at' => '2026-04-08 15:08:04',
        ],
        [
            'id' => '019d6da4-eb52-70a8-8bcc-b91085c03d32',
            'title' => 'Fiqh Sirah Nabawiyyah',
            'slug' => 'fiqh-sirah-nabawiyyah',
            'created_at' => '2026-04-08 15:10:17',
            'updated_at' => '2026-04-08 15:10:17',
            'media' => [
                [
                    'source_media_id' => 158,
                    'uuid' => 'e580cd4c-39ae-4275-9ee6-9afacc7e4e81',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Fiqh Sirah Nabawiyyah',
                    'file_name' => 'fiqh-sirah-nabawiyyah-01knpt9t.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 59808,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'id-11134207-7rasb-m3ym1pkdmtg9a9-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['fiqh-sirah-nabawiyyah-01knpt9t___media_library_original_510_709.webp', 'fiqh-sirah-nabawiyyah-01knpt9t___media_library_original_426_592.webp', 'fiqh-sirah-nabawiyyah-01knpt9t___media_library_original_357_496.webp', 'fiqh-sirah-nabawiyyah-01knpt9t___media_library_original_298_414.webp', 'fiqh-sirah-nabawiyyah-01knpt9t___media_library_original_249_346.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgNTEwIDcwOSI+Cgk8aW1hZ2Ugd2lkdGg9IjUxMCIgaGVpZ2h0PSI3MDkiIHhsaW5rOmhyZWY9ImRhdGE6aW1hZ2UvanBlZztiYXNlNjQsLzlqLzRBQVFTa1pKUmdBQkFRRUFZQUJnQUFELy9nQStRMUpGUVZSUFVqb2daMlF0YW5CbFp5QjJNUzR3SUNoMWMybHVaeUJKU2tjZ1NsQkZSeUIyT0RBcExDQmtaV1poZFd4MElIRjFZV3hwZEhrSy85c0FRd0FJQmdZSEJnVUlCd2NIQ1FrSUNnd1VEUXdMQ3d3WkVoTVBGQjBhSHg0ZEdod2NJQ1F1SnlBaUxDTWNIQ2czS1N3d01UUTBOQjhuT1QwNE1qd3VNelF5LzlzQVF3RUpDUWtNQ3d3WURRMFlNaUVjSVRJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXkvOEFBRVFnQUxBQWdBd0VpQUFJUkFRTVJBZi9FQUI4QUFBRUZBUUVCQVFFQkFBQUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVRQUFJQkF3TUNCQU1GQlFRRUFBQUJmUUVDQXdBRUVRVVNJVEZCQmhOUllRY2ljUlF5Z1pHaENDTkNzY0VWVXRId0pETmljb0lKQ2hZWEdCa2FKU1luS0NrcU5EVTJOemc1T2tORVJVWkhTRWxLVTFSVlZsZFlXVnBqWkdWbVoyaHBhbk4wZFhaM2VIbDZnNFNGaG9lSWlZcVNrNVNWbHBlWW1acWlvNlNscHFlb3FhcXlzN1MxdHJlNHVickN3OFRGeHNmSXljclMwOVRWMXRmWTJkcmg0dVBrNWVibjZPbnE4Zkx6OVBYMjkvajUrdi9FQUI4QkFBTUJBUUVCQVFFQkFRRUFBQUFBQUFBQkFnTUVCUVlIQ0FrS0MvL0VBTFVSQUFJQkFnUUVBd1FIQlFRRUFBRUNkd0FCQWdNUkJBVWhNUVlTUVZFSFlYRVRJaktCQ0JSQ2thR3h3UWtqTTFMd0ZXSnkwUW9XSkRUaEpmRVhHQmthSmljb0tTbzFOamM0T1RwRFJFVkdSMGhKU2xOVVZWWlhXRmxhWTJSbFptZG9hV3B6ZEhWMmQzaDVlb0tEaElXR2g0aUppcEtUbEpXV2w1aVptcUtqcEtXbXA2aXBxckt6dExXMnQ3aTV1c0xEeE1YR3g4akp5dExUMU5YVzE5aloydUxqNU9YbTUranA2dkx6OVBYMjkvajUrdi9hQUF3REFRQUNFUU1SQUQ4QTh3c05PRjREOHdCclMvc0ZHVEFjYnF3N2E2ZUJzcVNLdWpVSlRrZ211YVNsZlJrcHh0cVgxME9IYmhwQmtlOVlWNUNJWjJRSElGV0pMNlRuNWptcUxzWFlrbkpvZ3BMZGliWFFSUmsxWlV0akFxR0w3NHJVanQ0MVVFbkpOVkoyTHB3NXRFVTB0Mm1mQjRwbHphdkFlZW5yVzBsb0dYY0Rpb05TTWEyNFVISnFGSjNOSFRnNHUyNVd0N1JTbTRzS3Z4UlFrREw4MWhySzRHQXhvODZRSGhqVGNXK3B0Q3JDTWJXT2gyN1ZJVjZ4cjNrbkxaTlJDNWxCKythZ2RpeEpKb2pGcG1jcWtMTkpILy9aIj4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 15:10:18',
                    'updated_at' => '2026-10-24 19:00:46',
                ],
            ],
        ],
        [
            'id' => '019d6dac-7bd0-7303-a1f4-dab39ca35e7d',
            'title' => 'Riyadhus Shalihin',
            'slug' => 'riyadhus-shalihin',
            'author' => 'Imam Al-Nawawi',
            'created_at' => '2026-04-08 15:18:33',
            'updated_at' => '2026-04-08 15:19:16',
            'media' => [
                [
                    'source_media_id' => 159,
                    'uuid' => '1e18c84c-40ee-4600-9b4f-92ddb578fb1f',
                    'collection_name' => 'front_cover',
                    'name' => 'Front Cover - Riyadhus Shalihin',
                    'file_name' => 'riyadhus-shalihin-01knptt8.webp',
                    'mime_type' => 'image/webp',
                    'disk' => 's3',
                    'conversions_disk' => 's3',
                    'size' => 163192,
                    'manipulations' => [],
                    'custom_properties' => ['collection' => 'front_cover', 'custom_headers' => ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'], 'original_file_name' => 'Riyaz-us-Saliheen_darsi_Aala_-1-v1.webp'],
                    'generated_conversions' => ['thumb' => true],
                    'responsive_images' => ['media_library_original' => ['urls' => ['riyadhus-shalihin-01knptt8___media_library_original_2151_2883.webp', 'riyadhus-shalihin-01knptt8___media_library_original_1799_2411.webp', 'riyadhus-shalihin-01knptt8___media_library_original_1505_2017.webp', 'riyadhus-shalihin-01knptt8___media_library_original_1259_1687.webp', 'riyadhus-shalihin-01knptt8___media_library_original_1053_1411.webp', 'riyadhus-shalihin-01knptt8___media_library_original_881_1181.webp', 'riyadhus-shalihin-01knptt8___media_library_original_737_988.webp', 'riyadhus-shalihin-01knptt8___media_library_original_617_827.webp'], 'base64svg' => 'data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMgIi0vL1czQy8vRFREIFNWRyAxLjEvL0VOIiAiaHR0cDovL3d3dy53My5vcmcvR3JhcGhpY3MvU1ZHLzEuMS9EVEQvc3ZnMTEuZHRkIj4KPHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHhtbDpzcGFjZT0icHJlc2VydmUiIHg9IjAiCiB5PSIwIiB2aWV3Qm94PSIwIDAgMjE1MSAyODgzIj4KCTxpbWFnZSB3aWR0aD0iMjE1MSIgaGVpZ2h0PSIyODgzIiB4bGluazpocmVmPSJkYXRhOmltYWdlL2pwZWc7YmFzZTY0LC85ai80QUFRU2taSlJnQUJBUUVBWUFCZ0FBRC8vZ0ErUTFKRlFWUlBVam9nWjJRdGFuQmxaeUIyTVM0d0lDaDFjMmx1WnlCSlNrY2dTbEJGUnlCMk9EQXBMQ0JrWldaaGRXeDBJSEYxWVd4cGRIa0svOXNBUXdBSUJnWUhCZ1VJQndjSENRa0lDZ3dVRFF3TEN3d1pFaE1QRkIwYUh4NGRHaHdjSUNRdUp5QWlMQ01jSENnM0tTd3dNVFEwTkI4bk9UMDRNand1TXpReS85c0FRd0VKQ1FrTUN3d1lEUTBZTWlFY0lUSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5TWpJeU1qSXlNakl5LzhBQUVRZ0FLd0FnQXdFaUFBSVJBUU1SQWYvRUFCOEFBQUVGQVFFQkFRRUJBQUFBQUFBQUFBQUJBZ01FQlFZSENBa0tDLy9FQUxVUUFBSUJBd01DQkFNRkJRUUVBQUFCZlFFQ0F3QUVFUVVTSVRGQkJoTlJZUWNpY1JReWdaR2hDQ05Dc2NFVlV0SHdKRE5pY29JSkNoWVhHQmthSlNZbktDa3FORFUyTnpnNU9rTkVSVVpIU0VsS1UxUlZWbGRZV1ZwalpHVm1aMmhwYW5OMGRYWjNlSGw2ZzRTRmhvZUlpWXFTazVTVmxwZVltWnFpbzZTbHBxZW9xYXF5czdTMXRyZTR1YnJDdzhURnhzZkl5Y3JTMDlUVjF0ZlkyZHJoNHVQazVlYm42T25xOGZMejlQWDI5L2o1K3YvRUFCOEJBQU1CQVFFQkFRRUJBUUVBQUFBQUFBQUJBZ01FQlFZSENBa0tDLy9FQUxVUkFBSUJBZ1FFQXdRSEJRUUVBQUVDZHdBQkFnTVJCQVVoTVFZU1FWRUhZWEVUSWpLQkNCUkNrYUd4d1Frak0xTHdGV0p5MFFvV0pEVGhKZkVYR0JrYUppY29LU28xTmpjNE9UcERSRVZHUjBoSlNsTlVWVlpYV0ZsYVkyUmxabWRvYVdwemRIVjJkM2g1ZW9LRGhJV0doNGlKaXBLVGxKV1dsNWlabXFLanBLV21wNmlwcXJLenRMVzJ0N2k1dXNMRHhNWEd4OGpKeXRMVDFOWFcxOWpaMnVMajVPWG01K2pwNnZMejlQWDI5L2o1K3YvYUFBd0RBUUFDRVFNUkFEOEE3YUgvQUU2UVN5a0JSU2FoNG0wL1RGMnlUTHgyelhqaDhZYW1pYlJLY0dzK2FXVFVzeVR6a3NlMmF6amhtbnF5SGlWSmFJOTEwdnhWcCtva0NPWmNudG11aFY0MlVIY1B6cjV0dHc5Z1BPZ25JWmZldEZQR3VyQkFvbWJBcC9WM2ZSaWRkSmFub2ovREd4TVp3ZWE0alcvaDdxTmpLeldvTEpuZ0N2ZGc0QXFDYWFJcnlvUDRWQ3FUVHVXNmNMV1BFZEUrSCtwMzhxRzVESkhua1YzMFh3dHNmS1VFODRyczdXZUVMd29INFZvSTRZWkZEcVRidUhzNFdzWkR6cWd5YWphUVNMbFJUWkZCSElxU0lBTHdLekw2am9EdFBLOFZNTDVGa0VZNjFFZWxRN0Y4N09PYUVKbi8yUT09Ij4KCTwvaW1hZ2U+Cjwvc3ZnPg==']],
                    'order_column' => 1,
                    'created_at' => '2026-04-08 15:19:16',
                    'updated_at' => '2026-10-24 19:02:04',
                ],
            ],
        ],
        [
            'id' => '019dedae-ba84-73a4-a9f3-fe4629552ed7',
            'title' => 'Sunting Idaman Penuntut - Pada Menghuraikan Yang Kusut (Ilmu Fiqah)',
            'slug' => 'sunting-idaman-penuntut-pada-menghuraikan-yang-kusut-ilmu-fiqah',
            'parent_reference_id' => '019d477a-623c-7225-95db-a665a368fc61',
            'part_type' => 'jilid',
            'part_number' => '1',
            'part_label' => 'Bahagian Ibadat - Penggal Yang Pertama',
            'created_at' => '2026-05-03 11:52:24',
            'updated_at' => '2026-05-03 11:52:24',
        ],
        [
            'id' => '019dee94-5c8b-70a0-84fc-cae6e1481cc1',
            'title' => 'Asas Ahli Sunnah Wal Jamaah',
            'slug' => 'asas-ahli-sunnah-wal-jamaah',
            'created_at' => '2026-05-03 16:03:13',
            'updated_at' => '2026-05-03 16:03:13',
        ],
        [
            'id' => '019dee94-9c96-7139-8e5b-84b2a89a8206',
            'title' => '40 Hadith Cinta Pada Kecintaan Allah',
            'slug' => '40-hadith-cinta-pada-kecintaan-allah',
            'created_at' => '2026-05-03 16:03:30',
            'updated_at' => '2026-05-03 16:03:30',
        ],
        [
            'id' => '019dee94-c7de-70b4-91a2-d464b724dc2c',
            'title' => 'Agungnya Sayyidul Ayyam & Menghormati Adab-Adabnya',
            'slug' => 'agungnya-sayyidul-ayyam-menghormati-adab-adabnya',
            'created_at' => '2026-05-03 16:03:41',
            'updated_at' => '2026-05-03 16:03:41',
        ],
        [
            'id' => '019dee95-7a5b-71d8-a379-6e8f4638d81f',
            'title' => 'Kelebihan Al-Quran',
            'slug' => 'kelebihan-al-quran',
            'created_at' => '2026-05-03 16:04:26',
            'updated_at' => '2026-05-03 16:04:26',
        ],
        [
            'id' => '019dee97-1b59-73db-9696-3e92bcc7f5a2',
            'title' => 'Talaqqi Al-Quran',
            'slug' => 'talaqqi-al-quran',
            'created_at' => '2026-05-03 16:06:13',
            'updated_at' => '2026-05-03 16:06:13',
        ],
        [
            'id' => '019dee97-9df2-7268-98fb-846cae7fc896',
            'title' => 'Menyucikan Jiwa',
            'slug' => 'menyucikan-jiwa',
            'created_at' => '2026-05-03 16:06:47',
            'updated_at' => '2026-05-03 16:06:47',
        ],
        [
            'id' => '019dee97-df78-71e4-bf04-1c251c25592d',
            'title' => '99 Sunnah Rare',
            'slug' => '99-sunnah-rare',
            'created_at' => '2026-05-03 16:07:03',
            'updated_at' => '2026-05-03 16:07:03',
        ],
        [
            'id' => '019dee98-86ba-707c-a2e2-c9a6e00f1185',
            'title' => 'Sunnah-Sunnah Yang Ditinggalkan',
            'slug' => 'sunnah-sunnah-yang-ditinggalkan',
            'created_at' => '2026-05-03 16:07:46',
            'updated_at' => '2026-05-03 16:07:46',
        ],
        [
            'id' => '019dee99-393d-73d2-b867-1bcd59bec9bc',
            'title' => 'Mengapa Bermazhab',
            'slug' => 'mengapa-bermazhab',
            'created_at' => '2026-05-03 16:08:32',
            'updated_at' => '2026-05-03 16:08:32',
        ],
        [
            'id' => '019dee99-d967-7286-8b4f-28362be6792a',
            'title' => 'Amalan Menghindari Neraka',
            'slug' => 'amalan-menghindari-neraka',
            'created_at' => '2026-05-03 16:09:13',
            'updated_at' => '2026-05-03 16:09:13',
        ],
        [
            'id' => '019dee9a-86b6-707b-afe9-cedf9d7bf051',
            'title' => 'Fikrah Islamiah',
            'slug' => 'fikrah-islamiah',
            'created_at' => '2026-05-03 16:09:57',
            'updated_at' => '2026-05-03 16:09:57',
        ],
        [
            'id' => '019dee9a-c0a5-72f3-8674-469df5fcc0cb',
            'title' => 'Nasihat Agama & Wasiat Imam',
            'slug' => 'nasihat-agama-wasiat-imam',
            'created_at' => '2026-05-03 16:10:12',
            'updated_at' => '2026-05-03 16:10:12',
        ],
        [
            'id' => '019dee9b-0a92-7259-a19a-bdfada65525d',
            'title' => 'Asbabun Nuzul',
            'slug' => 'asbabun-nuzul',
            'created_at' => '2026-05-03 16:10:31',
            'updated_at' => '2026-05-03 16:10:31',
        ],
        [
            'id' => '019df736-23ae-722c-86d2-f71a0f39c8e4',
            'title' => 'Ringkasan Fiqh Mazhab Syafie',
            'slug' => 'ringkasan-fiqh-mazhab-syafie',
            'created_at' => '2026-05-05 08:16:53',
            'updated_at' => '2026-05-05 08:16:53',
        ],
        [
            'id' => '019df736-23bc-7143-85b0-25c0f43a62c6',
            'title' => 'Pohon Rezeki',
            'slug' => 'pohon-rezeki',
            'created_at' => '2026-05-05 08:16:53',
            'updated_at' => '2026-05-05 08:16:53',
        ],
        [
            'id' => '019df736-23c6-715f-b75f-aac12ec74003',
            'title' => 'Kembara Haji Nabi Muhammad S.A.W.',
            'slug' => 'kembara-haji-nabi-muhammad-saw',
            'created_at' => '2026-05-05 08:16:53',
            'updated_at' => '2026-05-05 08:16:53',
        ],
        [
            'id' => '019df736-23ce-7090-ad3e-895c46e48787',
            'title' => 'Umat Bertanya Rasul Menjawab',
            'slug' => 'umat-bertanya-rasul-menjawab',
            'created_at' => '2026-05-05 08:16:53',
            'updated_at' => '2026-05-05 08:16:53',
        ],
        [
            'id' => '019df736-23d6-71c7-8b0f-3d267f49172c',
            'title' => 'Tasawwuf: Perlaksanaan Al-Quran dan Sunnah',
            'slug' => 'tasawwuf-perlaksanaan-al-quran-dan-sunnah',
            'created_at' => '2026-05-05 08:16:53',
            'updated_at' => '2026-05-05 08:16:53',
        ],
        [
            'id' => '019df777-eba5-72f8-829f-5472748596e1',
            'title' => 'Mizan Al-Hasanah',
            'slug' => 'mizan-al-hasanah',
            'created_at' => '2026-05-05 09:28:44',
            'updated_at' => '2026-05-05 09:28:44',
        ],
    ];

    private int $importedReferenceCount = 0;

    private int $importedAuthorCount = 0;

    private int $updatedReferenceCount = 0;

    private int $importedMediaCount = 0;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('ReferenceSeeder embeds archived production data and cannot run in production.');
        }

        foreach (['references', 'persons', 'reference_contributors'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Run migrations before seeding references; target table [{$table}] is missing.");
            }
        }

        // Works first so parent parts resolve without remapping.
        $works = array_filter(self::REFERENCES, static fn (array $row): bool => empty($row['parent_reference_id']));
        $parts = array_filter(self::REFERENCES, static fn (array $row): bool => ! empty($row['parent_reference_id']));

        OwnerContext::withOwner(null, function () use ($works, $parts): void {
            DB::transaction(function () use ($works, $parts): void {
                foreach ([$works, $parts] as $rows) {
                    foreach ($rows as $row) {
                        $this->importReference($row);
                    }
                }
            });
        });

        $this->command?->table(
            ['Seeded data', 'Count'],
            [
                ['New references', $this->importedReferenceCount],
                ['Updated references', $this->updatedReferenceCount],
                ['New author persons', $this->importedAuthorCount],
                ['Media records created (files stay on S3)', $this->importedMediaCount],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importReference(array $row): void
    {
        $sourceId = (string) $row['id'];
        $title = trim((string) ($row['title'] ?? ''));

        if ($title === '') {
            return;
        }

        $reference = Reference::query()->whereKey($sourceId)->first()
            ?? Reference::query()->where('slug', $row['slug'])->first();

        $isNew = ! $reference instanceof Reference;

        if (! $reference instanceof Reference) {
            $reference = new Reference;
            $reference->forceFill([
                'id' => Reference::query()->whereKey($sourceId)->exists() ? (string) Str::orderedUuid() : $sourceId,
            ]);
        }

        $parentId = $this->resolveParentId($row['parent_reference_id'] ?? null, $sourceId);
        $recordKind = $parentId !== null ? ReferenceRecordKind::Part->value : ReferenceRecordKind::Work->value;

        $reference->forceFill([
            'title' => $title,
            'slug' => $this->resolveSlug((string) ($row['slug'] ?? ''), $title, $reference),
            'type' => $this->mapType((string) ($row['type'] ?? 'book')),
            'record_kind' => $recordKind,
            'parent_id' => $parentId,
            'reference_parts' => $parentId !== null ? $this->buildReferenceParts($row) : null,
            'year' => $this->parseYear($row['publication_year'] ?? null),
            'publisher' => $this->nullableTrimmed($row['publisher'] ?? null),
            'description' => $this->nullableTrimmed($row['description'] ?? null),
            'status' => $this->mapStatus((string) ($row['status'] ?? 'verified')),
            'published_at' => $row['created_at'] ?? $reference->published_at ?? now(),
            'verified_at' => $row['created_at'] ?? $reference->verified_at ?? now(),
            'last_state_change_at' => $row['updated_at'] ?? now(),
            'created_at' => $row['created_at'] ?? $reference->created_at ?? now(),
            'updated_at' => $row['updated_at'] ?? now(),
        ]);
        $reference->saveQuietly();

        $authorIds = [];

        if ($recordKind === ReferenceRecordKind::Work->value) {
            foreach ($this->parseAuthors((string) ($row['author'] ?? '')) as $authorName) {
                $authorIds[] = (string) $this->findOrCreateAuthor($authorName)->getKey();
            }

            $reference->syncContributors(ReferenceContributorRole::Author, (new Person)->getMorphClass(), $authorIds);
        }

        $this->importMedia($row, $reference);

        if ($isNew) {
            $this->importedReferenceCount++;
        } else {
            $this->updatedReferenceCount++;
        }
    }

    /**
     * Import cover/gallery rows as metadata only; the files themselves stay
     * on the production S3 bucket.
     *
     * @param  array<string, mixed>  $row
     */
    private function importMedia(array $row, Reference $reference): void
    {
        foreach ($row['media'] ?? [] as $media) {
            if (! is_array($media)) {
                continue;
            }

            $exists = DB::table('media')
                ->where('model_type', $reference->getMorphClass())
                ->where('model_id', $reference->getKey())
                ->where('collection_name', $media['collection_name'])
                ->when(
                    $media['collection_name'] === 'gallery',
                    fn ($query) => $query->whereRaw("custom_properties->>'production_reference_source_media_id' = ?", [(string) $media['source_media_id']]),
                )
                ->exists();

            if ($exists) {
                continue;
            }

            $uuid = (string) ($media['uuid'] ?? '');

            if ($uuid === '' || DB::table('media')->where('uuid', $uuid)->exists()) {
                $uuid = (string) Str::orderedUuid();
            }

            DB::table('media')->insert([
                'model_type' => $reference->getMorphClass(),
                'model_id' => $reference->getKey(),
                'uuid' => $uuid,
                'collection_name' => $media['collection_name'],
                'name' => $media['name'],
                'file_name' => $media['file_name'],
                'mime_type' => $media['mime_type'],
                'disk' => $media['disk'],
                'conversions_disk' => $media['conversions_disk'],
                'size' => $media['size'],
                'manipulations' => json_encode($media['manipulations'] ?? []),
                'custom_properties' => json_encode(array_merge(
                    is_array($media['custom_properties'] ?? null) ? $media['custom_properties'] : [],
                    ['production_reference_source_media_id' => $media['source_media_id']],
                )),
                'generated_conversions' => json_encode($media['generated_conversions'] ?? []),
                'responsive_images' => json_encode($media['responsive_images'] ?? []),
                'order_column' => $media['order_column'] ?? 1,
                'created_at' => $media['created_at'],
                'updated_at' => $media['updated_at'],
            ]);

            $this->importedMediaCount++;
        }
    }

    /**
     * @return list<string>
     */
    private function parseAuthors(string $raw): array
    {
        $names = [];

        foreach (explode(',', $raw) as $chunk) {
            $name = trim(preg_replace('/\s+/', ' ', $chunk) ?? '');

            if ($name === '') {
                continue;
            }

            $names[mb_strtolower($name)] = $name;
        }

        return array_values($names);
    }

    private function findOrCreateAuthor(string $name): Person
    {
        $person = Person::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($person instanceof Person) {
            return $person;
        }

        $person = new Person;
        $person->forceFill([
            'id' => (string) Str::orderedUuid(),
            'name' => $name,
            'slug' => app(GeneratePersonSlugAction::class)->handle($name),
            'status' => 'verified',
            'speaker_status' => SpeakerStatus::Inactive->value,
            'allow_public_event_submission' => false,
            'verified_at' => now(),
            'last_state_change_at' => now(),
        ]);
        $person->saveQuietly();

        $this->importedAuthorCount++;

        return $person;
    }

    private function mapType(string $sourceType): string
    {
        return match (mb_strtolower(trim($sourceType))) {
            'kitab', 'book' => ReferenceType::Book->value,
            'article' => ReferenceType::Article->value,
            'video' => ReferenceType::Video->value,
            default => ReferenceType::Book->value,
        };
    }

    private function mapStatus(string $sourceStatus): string
    {
        return match (mb_strtolower(trim($sourceStatus))) {
            'verified' => 'verified',
            default => 'pending',
        };
    }

    private function resolveParentId(mixed $sourceParentId, string $sourceId): ?string
    {
        if (! is_string($sourceParentId) && ! is_numeric($sourceParentId)) {
            return null;
        }

        $parentId = trim((string) $sourceParentId);

        if ($parentId === '' || $parentId === $sourceId) {
            return null;
        }

        return Reference::query()->whereKey($parentId)->exists() ? $parentId : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{type: string, value: string|null, label: string|null}>
     */
    private function buildReferenceParts(array $row): array
    {
        $type = ReferencePartType::tryFrom(mb_strtolower(trim((string) ($row['part_type'] ?? ''))))
            ?? ReferencePartType::Jilid;

        return [[
            'type' => $type->value,
            'value' => $this->nullableTrimmed($row['part_number'] ?? null),
            'label' => $this->nullableTrimmed($row['part_label'] ?? null),
        ]];
    }

    private function resolveSlug(string $sourceSlug, string $title, Reference $reference): string
    {
        $candidate = Str::slug(trim($sourceSlug) !== '' ? $sourceSlug : $title) ?: 'rujukan';

        $taken = Reference::query()
            ->where('slug', $candidate)
            ->whereKeyNot($reference->getKey() ?? '')
            ->exists();

        if (! $taken) {
            return $candidate;
        }

        return app(GenerateReferenceSlugAction::class)->handle($title, (string) ($reference->getKey() ?? null));
    }

    private function parseYear(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1000 && $value <= 2100 ? $value : null;
        }

        $trimmed = trim((string) $value);

        if (! preg_match('/^(1\d{3}|20\d{2}|21\d{2})$/', $trimmed)) {
            return null;
        }

        return (int) $trimmed;
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
