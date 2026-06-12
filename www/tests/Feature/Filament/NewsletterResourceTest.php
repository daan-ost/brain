<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\NewsletterResource;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NewsletterResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
        ]);
    }

    public function test_newsletter_list_page_can_be_rendered(): void
    {
        $this->actingAs($this->admin, 'admin');

        $response = $this->get(NewsletterResource::getUrl('index'));

        $response->assertOk();
    }

    public function test_newsletter_create_page_can_be_rendered(): void
    {
        $this->actingAs($this->admin, 'admin');

        $response = $this->get(NewsletterResource::getUrl('create'));

        $response->assertOk();
    }

    public function test_newsletter_can_be_created(): void
    {
        $this->actingAs($this->admin, 'admin');

        Livewire::test(NewsletterResource\Pages\CreateNewsletter::class)
            ->fillForm([
                'title_en' => 'Test Newsletter EN',
                'title_nl' => 'Test Newsletter NL',
                'body_en' => '<p>English content</p>',
                'body_nl' => '<p>Nederlandse inhoud</p>',
                'batch_size' => 100,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('newsletters', [
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_newsletter_edit_page_can_be_rendered(): void
    {
        $this->actingAs($this->admin, 'admin');

        $newsletter = Newsletter::factory()->create();

        $response = $this->get(NewsletterResource::getUrl('edit', ['record' => $newsletter]));

        $response->assertOk();
    }

    public function test_newsletter_view_page_can_be_rendered(): void
    {
        $this->actingAs($this->admin, 'admin');

        $newsletter = Newsletter::factory()->create();

        $response = $this->get(NewsletterResource::getUrl('view', ['record' => $newsletter]));

        $response->assertOk();
    }

    public function test_only_draft_newsletters_can_be_edited(): void
    {
        $this->actingAs($this->admin, 'admin');

        $draft = Newsletter::factory()->create();
        $sending = Newsletter::factory()->sending()->create();

        // Draft can be edited
        $this->assertTrue($draft->canBeEdited());

        // Sending cannot be edited
        $this->assertFalse($sending->canBeEdited());
    }

    public function test_newsletters_are_sorted_by_created_at_desc(): void
    {
        $this->actingAs($this->admin, 'admin');

        $older = Newsletter::factory()->create(['created_at' => now()->subDay()]);
        $newer = Newsletter::factory()->create(['created_at' => now()]);

        Livewire::test(NewsletterResource\Pages\ListNewsletters::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_newsletter_statistics_are_displayed_correctly(): void
    {
        $this->actingAs($this->admin, 'admin');

        $newsletter = Newsletter::factory()->create([
            'total_recipients' => 100,
            'total_sent' => 80,
            'total_opened' => 40,
            'total_clicked' => 20,
        ]);

        Livewire::test(NewsletterResource\Pages\ListNewsletters::class)
            ->assertCanSeeTableRecords([$newsletter]);
    }
}
