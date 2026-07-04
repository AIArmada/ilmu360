<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['id' => 7, 'code' => 'ar', 'name' => 'Arabic', 'name_native' => 'العربية', 'dir' => 'rtl'],
            ['id' => 30, 'code' => 'zh', 'name' => 'Chinese', 'name_native' => '中文', 'dir' => 'ltr'],
            ['id' => 40, 'code' => 'en', 'name' => 'English', 'name_native' => 'English', 'dir' => 'ltr'],
            ['id' => 64, 'code' => 'id', 'name' => 'Indonesian', 'name_native' => 'Bahasa Indonesia', 'dir' => 'ltr'],
            ['id' => 74, 'code' => 'jv', 'name' => 'Javanese', 'name_native' => 'ꦧꦱꦗꦮ', 'dir' => 'ltr'],
            ['id' => 101, 'code' => 'ms', 'name' => 'Malay', 'name_native' => 'bahasa Melayu', 'dir' => 'ltr'],
            ['id' => 154, 'code' => 'ta', 'name' => 'Tamil', 'name_native' => 'தமிழ்', 'dir' => 'ltr'],
        ];

        foreach ($languages as $language) {
            DB::table('languages')->updateOrInsert(
                ['id' => $language['id']],
                $language,
            );
        }
    }
}
