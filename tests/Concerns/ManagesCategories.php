<?php

namespace Tests\Concerns;

use App\Actions\Categories\ManageCategory;
use App\Models\Category;
use App\Models\User;
use App\Support\CategoryManagement;
use Illuminate\Support\Str;

trait ManagesCategories
{
    protected function categoryAdmin(): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin);

        return $admin;
    }

    protected function categoryEdit(Category $category, array $input, ?string $version = null, ?string $token = null): array
    {
        return app(ManageCategory::class)->edit($category->id, array_merge($category->only(['name', 'description']), $input), $version ?? CategoryManagement::version($category->fresh()), $token ?? (string) Str::uuid());
    }

    protected function categoryActivation(Category $category, bool $active, ?string $version = null, ?array $impact = null, ?string $token = null, bool $confirmed = true): array
    {
        $category = $category->fresh();

        return app(ManageCategory::class)->setActive($category->id, $active, $version ?? CategoryManagement::version($category, true), $impact ?? CategoryManagement::impact($category), $token ?? (string) Str::uuid(), $confirmed);
    }
}
