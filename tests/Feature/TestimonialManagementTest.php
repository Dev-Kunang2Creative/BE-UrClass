<?php

namespace Tests\Feature;

use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestimonialManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->student = User::factory()->create(['role' => 'user']);
    }

    public function test_guest_can_fetch_active_testimonials_from_landing_endpoint(): void
    {
        Testimonial::create([
            'name' => 'Rizky Pratama',
            'role' => 'Lolos FK UI 2024',
            'program' => 'UTBK-SNBT',
            'quote' => 'Timer per subtest di UrClass beneran ngelatih ketenangan.',
            'rating' => 5,
            'color_theme' => 'pink',
            'order_no' => 1,
            'is_active' => true,
        ]);

        Testimonial::create([
            'name' => 'Hidden User',
            'role' => 'Test Inactive',
            'program' => 'CPNS',
            'quote' => 'Testimoni yang tidak aktif.',
            'rating' => 4,
            'order_no' => 2,
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/landing/testimonials');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Rizky Pratama')
            ->assertJsonPath('data.0.program', 'UTBK-SNBT');
    }

    public function test_admin_can_crud_testimonials(): void
    {
        // 1. Create
        $payload = [
            'name' => 'Dina Kartika',
            'role' => 'Lolos SKD Kemenkeu 2024',
            'program' => 'CPNS',
            'quote' => 'Simulasi CAT-nya mirip banget sama ujian BKN.',
            'rating' => 5,
            'color_theme' => 'yellow',
            'order_no' => 1,
            'is_active' => true,
        ];

        $createResponse = $this->actingAs($this->admin)->postJson('/api/admin/testimonials', $payload);
        $createResponse->assertCreated();
        $id = $createResponse->json('data.id');

        $this->assertDatabaseHas('testimonials', [
            'id' => $id,
            'name' => 'Dina Kartika',
            'program' => 'CPNS',
        ]);

        // 2. Read List
        $listResponse = $this->actingAs($this->admin)->getJson('/api/admin/testimonials');
        $listResponse->assertOk()->assertJsonCount(1, 'data');

        // 3. Update
        $updateResponse = $this->actingAs($this->admin)->putJson("/api/admin/testimonials/{$id}", [
            'name' => 'Dina Kartika S.E.',
            'role' => 'Lolos SKD Kemenkeu 2024',
            'program' => 'CPNS',
            'quote' => 'Updated quote ulasan.',
            'rating' => 5,
            'color_theme' => 'yellow',
            'order_no' => 1,
            'is_active' => true,
        ]);
        $updateResponse->assertOk();
        $this->assertDatabaseHas('testimonials', [
            'id' => $id,
            'name' => 'Dina Kartika S.E.',
        ]);

        // 4. Delete
        $deleteResponse = $this->actingAs($this->admin)->deleteJson("/api/admin/testimonials/{$id}");
        $deleteResponse->assertOk();
        $this->assertDatabaseMissing('testimonials', ['id' => $id]);
    }

    public function test_non_admin_cannot_manage_testimonials(): void
    {
        $this->actingAs($this->student)->getJson('/api/admin/testimonials')->assertForbidden();
        $this->actingAs($this->student)->postJson('/api/admin/testimonials', [])->assertForbidden();
    }
}
