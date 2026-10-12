<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrandAssetsTest extends TestCase
{
    public function test_login_page_uses_survey_gakin_logo_and_icons(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('src="/brand/gakin-logo-putih.svg"', false)
            ->assertSee('src="/brand/gakin-mark.svg"', false)
            ->assertSee('<link rel="icon" href="/brand/gakin-mark.svg" type="image/svg+xml">', false)
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false);
    }

    public function test_manifest_icons_exist_and_include_a_maskable_icon(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        $this->assertFileExists(public_path('brand/apple-touch-icon.png'));
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
    }
}
