<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Web Development' => 'Websites, web applications, and integrations.',
            'Mobile App Development' => 'Applications for Android and iOS.',
            'Design and Creative' => 'Branding, graphics, and user experience design.',
            'Writing and Translation' => 'Copywriting, editing, and translation.',
            'Digital Marketing' => 'Search, social media, and marketing campaigns.',
            'Video and Animation' => 'Video editing, motion graphics, and animation.',
            'Data and Analytics' => 'Data preparation, reporting, and analysis.',
            'Administrative Support' => 'Research, data entry, and virtual assistance.',
        ];

        foreach ($categories as $name => $description) {
            // Only insert missing reference entries; preserve administrator edits.
            Category::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'description' => $description],
            );
        }
    }
}
