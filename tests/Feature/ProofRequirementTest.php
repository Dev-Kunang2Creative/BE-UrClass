<?php

namespace Tests\Feature;

use App\Models\ProofRequirement;
use App\Models\Tryout;
use App\Models\User;
use App\Models\UserTryoutAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Syarat bukti pendaftaran tryout gratis.
 *
 * Yang mudah rusak diam-diam di sini: jumlah bukti yang diminta server harus
 * mengikuti daftar syarat, dan tiap bukti harus tetap bisa dikenali menjawab
 * syarat mana - termasuk setelah syaratnya diubah admin.
 */
class ProofRequirementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $siswa;

    protected Tryout $tryoutGratis;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@urclass.test']);
        $this->siswa = User::factory()->create(['role' => 'user', 'email' => 'siswa@urclass.test', 'kategori' => 'utbk']);

        $this->tryoutGratis = Tryout::create([
            'title' => 'TO UTBK Gratis',
            'category' => 'UTBK',
            'kategori' => 'utbk',
            'use_irt' => true,
            'is_free' => true,
            'is_published' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    private function buatSyarat(int $jumlah): \Illuminate\Support\Collection
    {
        return collect(range(1, $jumlah))->map(fn ($i) => ProofRequirement::create([
            'title' => "Syarat {$i}",
            'instruction' => "Instruksi syarat {$i}",
            'order_no' => $i,
            'is_active' => true,
        ]));
    }

    public function test_peserta_membaca_syarat_aktif_saja(): void
    {
        $this->buatSyarat(2);
        ProofRequirement::create(['title' => 'Nonaktif', 'order_no' => 9, 'is_active' => false]);

        $response = $this->actingAs($this->siswa)->getJson('/api/proof-requirements');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame('Syarat 1', $response->json('data.0.title'));
    }

    /**
     * Inti fiturnya: jumlah bukti yang diminta mengikuti daftar syarat, bukan
     * angka yang ditulis di kode. Tiga syarat berarti tiga unggahan.
     */
    public function test_pendaftaran_meminta_satu_bukti_per_syarat(): void
    {
        $syarat = $this->buatSyarat(3);

        // Dua dari tiga: ditolak, dan pesannya menyebut syarat yang terlewat.
        $kurang = $this->actingAs($this->siswa)->post("/api/tryouts/{$this->tryoutGratis->id}/enroll", [
            'proofs' => [
                $syarat[0]->id => UploadedFile::fake()->image('a.jpg'),
                $syarat[1]->id => UploadedFile::fake()->image('b.jpg'),
            ],
        ]);

        $kurang->assertStatus(422);
        $this->assertStringContainsString(
            'Syarat 3',
            json_encode($kurang->json('errors')),
        );

        // Lengkap: diterima.
        $lengkap = $this->actingAs($this->siswa)->post("/api/tryouts/{$this->tryoutGratis->id}/enroll", [
            'proofs' => $syarat->mapWithKeys(fn ($s) => [
                $s->id => UploadedFile::fake()->image("{$s->order_no}.jpg"),
            ])->all(),
        ]);

        $lengkap->assertOk();

        $access = UserTryoutAccess::where('user_id', $this->siswa->id)->first();
        $this->assertCount(3, $access->proof_images);
        $this->assertCount(3, $access->proof_details);
    }

    /**
     * Judul syarat ikut disimpan, bukan hanya id-nya: syarat bisa diubah atau
     * dihapus admin setelah pendaftaran masuk, dan bukti lama tetap harus bisa
     * dibaca apa maksudnya saat ditinjau.
     */
    public function test_bukti_tetap_bernama_setelah_syaratnya_dihapus(): void
    {
        $syarat = $this->buatSyarat(2);

        $this->actingAs($this->siswa)->post("/api/tryouts/{$this->tryoutGratis->id}/enroll", [
            'proofs' => $syarat->mapWithKeys(fn ($s) => [
                $s->id => UploadedFile::fake()->image("{$s->order_no}.jpg"),
            ])->all(),
        ])->assertOk();

        // Syarat kedua dihapus admin setelah bukti masuk.
        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/proof-requirements/{$syarat[1]->id}")
            ->assertOk();

        $daftar = $this->actingAs($this->admin)->getJson('/api/admin/tryout-proof-images');
        $daftar->assertOk();

        $items = $daftar->json('data.0.proof_items');
        $this->assertCount(2, $items);
        $this->assertSame(['Syarat 1', 'Syarat 2'], array_column($items, 'title'));
    }

    /** Pendaftaran lama tanpa proof_details tetap tampil, tanpa judul karangan. */
    public function test_bukti_lama_tanpa_keterangan_tetap_tampil(): void
    {
        UserTryoutAccess::create([
            'user_id' => $this->siswa->id,
            'tryout_id' => $this->tryoutGratis->id,
            'proof_image' => 'proof-images/lama.jpg',
            'proof_images' => ['proof-images/lama.jpg', 'proof-images/lama2.jpg'],
            'proof_details' => null,
            'granted_at' => now(),
        ]);

        $items = $this->actingAs($this->admin)
            ->getJson('/api/admin/tryout-proof-images')
            ->json('data.0.proof_items');

        $this->assertCount(2, $items);
        $this->assertNull($items[0]['title']);
        $this->assertNotEmpty($items[0]['url']);
    }

    public function test_admin_bisa_crud_syarat(): void
    {
        $buat = $this->actingAs($this->admin)->postJson('/api/admin/proof-requirements', [
            'title' => 'Bukti tag teman',
            'instruction' => 'Tag 3 temanmu di kolom komentar.',
            'icon' => 'users',
        ]);

        $buat->assertStatus(201)->assertJsonPath('data.title', 'Bukti tag teman');
        $id = $buat->json('data.id');

        // Tanpa order_no, syarat baru masuk di akhir - bukan menyelip ke depan.
        $this->assertSame(1, $buat->json('data.order_no'));

        $this->actingAs($this->admin)->putJson("/api/admin/proof-requirements/{$id}", [
            'title' => 'Bukti tag 5 teman',
            'icon' => 'users',
        ])->assertOk()->assertJsonPath('data.title', 'Bukti tag 5 teman');

        // Judul kembar ditolak.
        $this->actingAs($this->admin)->postJson('/api/admin/proof-requirements', [
            'title' => 'Bukti tag 5 teman',
        ])->assertStatus(422)->assertJsonValidationErrors('title');
    }

    /** Username Instagram apa adanya dibakukan jadi URL yang bisa dibuka. */
    public function test_tautan_instagram_dibakukan_dari_username(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/proof-requirements', [
            'title' => 'Bukti follow',
            'icon' => 'instagram',
            'link_url' => '@urclass',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.link_url', 'https://www.instagram.com/urclass/')
            // Tautan tanpa label tetap perlu teks tombol.
            ->assertJsonPath('data.link_label', 'Buka tautan');
    }

    /**
     * Tryout gratis tanpa syarat sama sekali berarti bukti tidak diminta, jadi
     * syarat terakhir tidak boleh hilang sebagai efek samping menyunting.
     */
    public function test_syarat_terakhir_tidak_bisa_dihapus_atau_dinonaktifkan(): void
    {
        $syarat = $this->buatSyarat(1)->first();

        $this->actingAs($this->admin)
            ->deleteJson("/api/admin/proof-requirements/{$syarat->id}")
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/proof-requirements/{$syarat->id}", [
                'title' => $syarat->title,
                'is_active' => false,
            ])
            ->assertStatus(422);

        $this->assertTrue($syarat->fresh()->is_active);
    }

    public function test_urutan_disimpan_sekaligus(): void
    {
        $syarat = $this->buatSyarat(3);

        $this->actingAs($this->admin)->putJson('/api/admin/proof-requirements/reorder', [
            'ids' => [$syarat[2]->id, $syarat[0]->id, $syarat[1]->id],
        ])->assertOk();

        $this->assertSame(
            ['Syarat 3', 'Syarat 1', 'Syarat 2'],
            ProofRequirement::orderBy('order_no')->pluck('title')->all(),
        );
    }

    public function test_peserta_tidak_boleh_mengatur_syarat(): void
    {
        $syarat = $this->buatSyarat(2)->first();

        $this->actingAs($this->siswa)
            ->postJson('/api/admin/proof-requirements', ['title' => 'Nyelundup'])
            ->assertForbidden();

        $this->actingAs($this->siswa)
            ->deleteJson("/api/admin/proof-requirements/{$syarat->id}")
            ->assertForbidden();
    }

    /**
     * Tab yang masih memuat antarmuka lama mengirim proof_images[] tanpa
     * keterangan syarat. Urutannya satu-satunya petunjuk, jadi dipetakan
     * berurutan - supaya pendaftaran tidak gagal beberapa menit setelah deploy.
     */
    public function test_bentuk_lama_proof_images_masih_diterima(): void
    {
        $this->buatSyarat(2);

        $this->actingAs($this->siswa)->post("/api/tryouts/{$this->tryoutGratis->id}/enroll", [
            'proof_images' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
            ],
        ])->assertOk();

        $access = UserTryoutAccess::where('user_id', $this->siswa->id)->first();
        $this->assertCount(2, $access->proof_images);
        $this->assertSame(['Syarat 1', 'Syarat 2'], array_column($access->proof_details, 'title'));
    }

    public function test_gambar_terlalu_besar_ditolak_dengan_menyebut_syaratnya(): void
    {
        $syarat = $this->buatSyarat(1)->first();
        ProofRequirement::create(['title' => 'Syarat 2', 'order_no' => 2, 'is_active' => true]);

        $response = $this->actingAs($this->siswa)->post("/api/tryouts/{$this->tryoutGratis->id}/enroll", [
            'proofs' => [
                $syarat->id => UploadedFile::fake()->image('besar.jpg')->size(3000),
                ProofRequirement::where('title', 'Syarat 2')->first()->id => UploadedFile::fake()->image('ok.jpg'),
            ],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Syarat 1', json_encode($response->json('errors')));
        $this->assertStringContainsString('2MB', json_encode($response->json('errors')));
    }
}
