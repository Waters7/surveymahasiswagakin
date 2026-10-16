<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppVersion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    use RefreshDatabase;

    private const COMMIT = '5438748f0c2a9e1b7d3c6a4f8e2b1d0c9a7e6f5b';

    private string $originalBasePath;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBasePath = $this->app->basePath();
        $this->sandbox = sys_get_temp_dir().'/gakin-version-'.uniqid();
        File::ensureDirectoryExists($this->sandbox.'/.git/refs/heads');
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBasePath);
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    public function test_label_reads_version_file_and_branch_commit(): void
    {
        File::put($this->sandbox.'/VERSION', "1.2.3\n");
        File::put($this->sandbox.'/.git/HEAD', "ref: refs/heads/main\n");
        File::put($this->sandbox.'/.git/refs/heads/main', self::COMMIT."\n");
        $this->app->setBasePath($this->sandbox);

        $this->assertSame('v1.2.3 · 5438748', app(AppVersion::class)->label());
    }

    public function test_label_uses_packed_refs_and_falls_back_without_version_file(): void
    {
        File::put($this->sandbox.'/.git/HEAD', "ref: refs/heads/main\n");
        File::put($this->sandbox.'/.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n".self::COMMIT." refs/heads/main\n");
        $this->app->setBasePath($this->sandbox);

        $this->assertSame('v'.AppVersion::FALLBACK.' · 5438748', app(AppVersion::class)->label());
    }

    public function test_label_omits_commit_when_code_is_not_a_git_checkout(): void
    {
        File::put($this->sandbox.'/VERSION', '1.2.3');
        File::deleteDirectory($this->sandbox.'/.git');
        $this->app->setBasePath($this->sandbox);

        $this->assertSame('v1.2.3', app(AppVersion::class)->label());
    }

    public function test_login_page_and_app_footer_show_the_version(): void
    {
        $label = app(AppVersion::class)->label();
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create(['active' => true, 'must_change_password' => false]);
        $user->assignRole('surveyor');

        $this->get(route('login'))->assertOk()->assertSee('Survey GAKIN '.$label);
        $this->actingAs($user)->get('/')->assertOk()->assertSee('<span class="app-version" title="Versi aplikasi dan kode commit yang sedang tayang">'.$label.'</span>', false);
    }
}
