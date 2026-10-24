<?php

namespace Tests\Feature\Settings;

use App\Models\Category;
use App\Models\User;
use App\Services\AccountPhotos;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AlpineControlBindingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('avatars');
        Storage::fake('local');
    }

    private function element(string $html, string $query): DOMElement
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $element = (new DOMXPath($document))->query($query)->item(0);
        $this->assertInstanceOf(DOMElement::class, $element);

        return $element;
    }

    private function evaluate(DOMElement $root, array $buttons, array $states): array
    {
        $bindings = array_map(fn (DOMElement $button) => $button->getAttribute('x-bind:disabled'), $buttons);
        $events = [];
        foreach (['start', 'finish', 'error', 'cancel'] as $name) {
            $events[$name] = $root->getAttribute('x-on:livewire-upload-'.$name);
        }
        $process = new Process(['node', base_path('tests/Frontend/evaluate-alpine-bindings.cjs')]);
        $process->setTimeout(10);
        $process->setInput(json_encode(['data' => $root->getAttribute('x-data'), 'bindings' => $bindings, 'events' => $events, 'states' => $states], JSON_THROW_ON_ERROR));
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('error', $result, 'Actual rendered Alpine binding failed JavaScript evaluation: '.($result['error'] ?? ''));

        return $result['results'];
    }

    private function photoControls(string $html): array
    {
        $this->assertStringNotContainsString('@js(', $html);
        $this->assertStringNotContainsString('@json(', $html);
        $root = $this->element($html, '//*[@aria-labelledby="photo-heading"]');
        $buttons = [
            'save' => $this->element($html, '//button[contains(., "Save photo")]'),
            'remove' => $this->element($html, '//button[contains(., "Remove photo")]'),
        ];
        foreach ($buttons as $button) {
            $this->assertSame('disabled', $button->getAttribute('wire:loading.attr'));
            $this->assertSame('photo,savePhoto,removePhoto', $button->getAttribute('wire:target'));
        }

        return [$root, $buttons];
    }

    public function test_actual_photo_html_has_an_executable_remove_binding(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Volt::test('settings.photo');
        $root = $this->element($component->html(), '//*[@aria-labelledby="photo-heading"]');
        $remove = $this->element($component->html(), '//button[contains(., "Remove photo")]');
        $results = $this->evaluate($root, ['remove' => $remove], [['wire' => $component->snapshot['data']]]);
        $this->assertTrue($results[0]['remove']);
    }

    public function test_actual_project_editor_html_has_an_executable_save_binding(): void
    {
        Category::factory()->create();
        $this->actingAs(User::factory()->create(['role' => 'client']));
        $component = Volt::test('client.projects.editor');
        $root = $this->element($component->html(), '//form');
        $save = $this->element($component->html(), '//button[@type="submit"]');
        $results = $this->evaluate($root, ['save' => $save], [['wire' => $component->snapshot['data']]]);
        $this->assertFalse($results[0]['save']);
    }

    #[DataProvider('roles')]
    public function test_photo_bindings_follow_uploads_replacements_and_removal_without_reinitializing_alpine(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);
        $component = Volt::test('settings.photo');
        [$root, $buttons] = $this->photoControls($component->html());
        $this->assertTrue($buttons['save']->hasAttribute('disabled'));
        $this->assertTrue($buttons['remove']->hasAttribute('disabled'));
        $states = [['wire' => $component->snapshot['data']]];

        $component->set('photo', UploadedFile::fake()->image('first.png'))->assertHasNoErrors();
        $states[] = ['wire' => $component->snapshot['data']];
        $states[] = ['events' => ['start']];
        $states[] = ['events' => ['finish']];
        $component->call('savePhoto')->assertHasNoErrors()->assertSet('hasSavedPhoto', true);
        $firstPath = $user->fresh()->avatar_path;
        $states[] = ['wire' => $component->snapshot['data']];
        [, $savedButtons] = $this->photoControls($component->html());
        $this->assertTrue($savedButtons['save']->hasAttribute('disabled'));
        $this->assertFalse($savedButtons['remove']->hasAttribute('disabled'));
        $this->assertStringNotContainsString($firstPath, $component->html());
        $this->assertStringNotContainsString($firstPath, json_encode($component->snapshot, JSON_THROW_ON_ERROR));

        $component->set('photo', UploadedFile::fake()->image('replacement.png'))->assertHasNoErrors();
        $states[] = ['wire' => $component->snapshot['data']];
        $states[] = ['events' => ['start', 'error']];
        $states[] = ['events' => ['start', 'cancel']];
        $component->call('savePhoto')->assertHasNoErrors()->assertSet('hasSavedPhoto', true);
        $replacementPath = $user->fresh()->avatar_path;
        $this->assertNotSame($firstPath, $replacementPath);
        $states[] = ['wire' => $component->snapshot['data']];

        // Validation failure must retain the saved-photo state and allow removal.
        $component->set('photo', UploadedFile::fake()->createWithContent('invalid.png', 'not an image'))
            ->assertHasErrors('photo')->assertSet('previewReady', false)->assertSet('hasSavedPhoto', true);
        $states[] = ['wire' => $component->snapshot['data'], 'events' => ['start', 'error']];
        $this->assertSame($replacementPath, $user->fresh()->avatar_path);

        $component->call('removePhoto')->assertHasNoErrors()->assertSet('hasSavedPhoto', false);
        $states[] = ['wire' => $component->snapshot['data']];
        $component->refresh()->assertSet('hasSavedPhoto', false);
        $states[] = ['wire' => $component->snapshot['data']];
        [, $removedButtons] = $this->photoControls($component->html());
        $this->assertTrue($removedButtons['save']->hasAttribute('disabled'));
        $this->assertTrue($removedButtons['remove']->hasAttribute('disabled'));
        $this->assertNull($user->fresh()->avatar_path);

        $expected = [
            [true, true, false], [false, true, false], [true, true, true], [false, true, false],
            [true, false, false], [false, false, false], [false, false, false], [false, false, false],
            [true, false, false], [true, false, false], [true, true, false], [true, true, false],
        ];
        $this->assertSame(array_map(fn ($state) => ['save' => $state[0], 'remove' => $state[1], 'uploading' => $state[2]], $expected), $this->evaluate($root, $buttons, $states));
        $this->get('/settings/profile')->assertOk()->assertDontSee('@js(', false);
    }

    public function test_saved_photo_binding_survives_failed_saves_removals_and_refreshes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $account = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('saved.png'));
        $path = $account->avatar_path;
        $component = Volt::test('settings.photo')->assertSet('hasSavedPhoto', true);
        [$root, $buttons] = $this->photoControls($component->html());
        $this->assertFalse($buttons['remove']->hasAttribute('disabled'));
        $states = [['wire' => $component->snapshot['data']]];
        $fail = true;
        User::saving(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Simulated photo persistence failure');
            }
        });
        $component->set('photo', UploadedFile::fake()->image('replacement.png'))->call('savePhoto')
            ->assertHasErrors('photo')->assertSet('hasSavedPhoto', true);
        $states[] = ['wire' => $component->snapshot['data']];
        $component->call('removePhoto')->assertHasErrors('photo')->assertSet('hasSavedPhoto', true);
        $states[] = ['wire' => $component->snapshot['data']];
        $this->assertSame($path, $user->fresh()->avatar_path);
        $this->assertSame([$path], Storage::disk('avatars')->allFiles());

        $fail = false;
        app(AccountPhotos::class)->remove($user->fresh());
        $component->refresh()->assertSet('hasSavedPhoto', false);
        $states[] = ['wire' => $component->snapshot['data']];
        app(AccountPhotos::class)->replace($user->fresh(), UploadedFile::fake()->image('other-session.png'));
        $component->refresh()->assertSet('hasSavedPhoto', true);
        $states[] = ['wire' => $component->snapshot['data']];
        $results = $this->evaluate($root, $buttons, $states);
        $this->assertSame([false, false, false, true, false], array_column($results, 'remove'));
    }

    public function test_project_save_binding_tracks_category_changes_and_upload_events(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));
        $component = Volt::test('client.projects.editor');
        $root = $this->element($component->html(), '//form');
        $save = $this->element($component->html(), '//button[@type="submit"]');
        $this->assertTrue($save->hasAttribute('disabled'));
        $states = [['wire' => $component->snapshot['data']]];
        $category = Category::factory()->create(['is_active' => true]);
        $component->refresh()->assertSet('hasActiveCategories', true);
        $states[] = ['wire' => $component->snapshot['data']];
        $states[] = ['events' => ['start']];
        $states[] = ['events' => ['finish']];
        $states[] = ['events' => ['start', 'error']];
        $states[] = ['events' => ['start', 'cancel']];
        $category->update(['is_active' => false]);
        $component->refresh()->assertSet('hasActiveCategories', false);
        $states[] = ['wire' => $component->snapshot['data']];
        $category->update(['is_active' => true]);
        $component->refresh()->assertSet('hasActiveCategories', true);
        $states[] = ['wire' => $component->snapshot['data']];
        $this->assertFalse($this->element($component->html(), '//button[@type="submit"]')->hasAttribute('disabled'));
        $this->assertSame([true, false, true, false, false, false, true, false], array_column($this->evaluate($root, ['save' => $save], $states), 'save'));
        $this->assertDatabaseCount('projects', 0);
    }

    #[DataProvider('protectedState')]
    public function test_derived_control_state_cannot_be_changed_by_form_input(string $name, string $property): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]));
        $component = Volt::test($name);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set($property, true);
    }

    public static function roles(): array
    {
        return ['client' => [User::ROLE_CLIENT], 'freelancer' => [User::ROLE_FREELANCER], 'admin' => [User::ROLE_ADMIN]];
    }

    public static function protectedState(): array
    {
        return ['photo' => ['settings.photo', 'hasSavedPhoto'], 'categories' => ['client.projects.editor', 'hasActiveCategories']];
    }
}
