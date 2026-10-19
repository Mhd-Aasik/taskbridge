<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Laravel',
            'PHP',
            'JavaScript',
            'UI/UX Design',
            'Graphic Design',
            'Content Writing',
            'Digital Marketing',
            'Data Entry',
        ] as $name) {
            Skill::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }
    }
}
