<?php

namespace Tests\Feature;

use App\Domain\Platform\Services\DocumentationService;
use Tests\FeatureTestCase;

/**
 * The documentation page in the administration panel: platform staff read the manuals and
 * download them as PDF, one by one or all together; others cannot.
 */
class DocumentationTest extends FeatureTestCase
{
    public function test_every_manual_and_every_referenced_diagram_exists(): void
    {
        $docs = app(DocumentationService::class);
        foreach (DocumentationService::DOCUMENTS as $slug => [$file]) {
            $this->assertFileExists($docs->path($file));
            preg_match_all('#\]\((diagrams/[a-z0-9\-]+\.svg)\)#', file_get_contents($docs->path($file)), $m);
            foreach ($m[1] as $diagram) {
                $this->assertFileExists($docs->path($diagram), "{$file} refers to {$diagram}");
            }
            $this->assertNotEmpty($docs->document($slug)['toc'], "{$slug} has sections");
        }
    }

    public function test_platform_staff_read_and_download_the_documentation(): void
    {
        [$owner] = $this->organizationMember();
        $this->get('/admin/documentation')->assertRedirect('/login');
        $this->actingAs($owner)->get('/admin/documentation')->assertForbidden();
        $this->actingAs($owner)->get('/admin/documentation/pdf')->assertForbidden();

        $officer = $this->platformUser('compliance_officer');
        $this->actingAs($officer)->get('/admin/documentation')->assertOk()
            ->assertSee('Download all (PDF)')->assertSee('Setup and installation')->assertSee('Connecting an external system')
            ->assertSee('php artisan migrate --force');
        $this->actingAs($officer)->get('/admin/documentation?doc=application-flows')->assertOk()
            ->assertSee('data:image/svg+xml;base64,', false)->assertSee('Order to cash');
        $this->actingAs($officer)->get('/admin/documentation?doc=api')->assertOk()->assertSee('Worked examples')->assertSee('Idempotency-Key');

        $all = $this->actingAs($officer)->get('/admin/documentation/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename=', $all->headers->get('Content-Disposition'));
        $this->assertStringContainsString('documentation.pdf', $all->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', file_get_contents($all->getFile()->getPathname()));

        $one = $this->actingAs($officer)->get('/admin/documentation/pdf/integration')->assertOk();
        $this->assertStringContainsString('connecting-an-external-system.pdf', $one->headers->get('Content-Disposition'));
        $this->assertLessThan(filesize($all->getFile()->getPathname()), filesize($one->getFile()->getPathname()));
        $this->actingAs($officer)->get('/admin/documentation/pdf/nope')->assertNotFound();

        // Operations staff have it too; the menu links to it.
        $ops = $this->platformUser('platform_operations_admin');
        $this->actingAs($ops)->get('/dashboard')->assertOk()->assertSee(route('admin.documentation'), false);
    }
}
