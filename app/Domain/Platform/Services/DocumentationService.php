<?php

namespace App\Domain\Platform\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The user documentation (docs/manual/*.md): rendered for the administration panel and
 * as PDF, one document or all of them together. Diagrams (docs/manual/diagrams/*.svg) are
 * embedded, so the HTML and the PDF are self-contained. PDFs are cached by content hash
 * and rebuilt when a manual or diagram changes.
 */
class DocumentationService
{
    /** Reading order; slug => [file, title, audience, summary]. */
    public const DOCUMENTS = [
        'setup' => ['01-setup.md', 'Setup and installation', 'System administrators', 'Requirements, installation, configuration, background processes, mobile app build and releases.'],
        'administration' => ['02-administration.md', 'Administration guide', 'Platform staff and organization administrators', 'Security policy, organizations, countries, catalogue, compliance, branding and operations.'],
        'user-guide' => ['03-user-guide.md', 'User guide', 'People in member organizations', 'Day-to-day use: inventory, purchasing, sales, POS, field force app, marketplace, traceability, analytics.'],
        'application-flows' => ['04-application-flows.md', 'Application flows', 'Everyone', 'Architecture and the main business flows, as diagrams with explanations.'],
        'api' => ['05-api-guide.md', 'API guide', 'Developers', 'Authentication, conventions, errors, the areas of the API and worked examples.'],
        'integration' => ['06-external-integration.md', 'Connecting an external system', 'Integration engineers', 'Gateway connectors, message formats, signing, webhooks, go-live checklist.'],
    ];

    public function path(string $file = ''): string
    {
        return base_path('docs/manual'.($file === '' ? '' : '/'.$file));
    }

    public function exists(string $slug): bool
    {
        return isset(self::DOCUMENTS[$slug]);
    }

    /** @return array{slug: string, title: string, audience: string, summary: string, html: string, toc: list<array{id: string, text: string}>, updated_at: int} */
    public function document(string $slug): array
    {
        if (! $this->exists($slug)) {
            throw new InvalidArgumentException("Unknown document {$slug}.");
        }
        [$file, $title, $audience, $summary] = self::DOCUMENTS[$slug];
        $markdown = File::get($this->path($file));
        // The first heading is the document title, shown separately.
        $markdown = preg_replace('/\A#\s+.+\R+/', '', $markdown, 1);
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        // Embed diagrams so pages and PDFs carry them.
        $html = preg_replace_callback('#(?:<p>)?<img src="diagrams/([a-z0-9\-]+\.svg)" alt="([^"]*)"\s*/?>(?:</p>)?#', function ($m) {
            $svg = $this->path('diagrams/'.$m[1]);

            return is_file($svg)
                ? '<figure class="doc-diagram"><img src="data:image/svg+xml;base64,'.base64_encode(File::get($svg)).'" alt="'.$m[2].'"><figcaption>'.$m[2].'</figcaption></figure>'
                : $m[0];
        }, $html);

        // Anchors for the second-level headings, collected as the table of contents.
        $toc = [];
        $html = preg_replace_callback('#<h2>(.*?)</h2>#s', function ($m) use (&$toc, $slug) {
            $text = trim(strip_tags($m[1]));
            $id = $slug.'-'.Str::slug($text);
            $toc[] = ['id' => $id, 'text' => html_entity_decode($text)];

            return '<h2 id="'.$id.'">'.$m[1].'</h2>';
        }, $html);

        return ['slug' => $slug, 'title' => $title, 'audience' => $audience, 'summary' => $summary, 'html' => $html, 'toc' => $toc,
            'updated_at' => filemtime($this->path($file))];
    }

    /** @return list<array> every document, in reading order */
    public function all(): array
    {
        return array_map(fn ($slug) => $this->document($slug), array_keys(self::DOCUMENTS));
    }

    /**
     * PDF of one document, or of all of them (null). Returns the path of the cached file.
     */
    public function pdf(?string $slug = null): string
    {
        $docs = $slug === null ? $this->all() : [$this->document($slug)];
        $brand = app(BrandingService::class)->platform();
        $hash = substr(hash('sha256', json_encode(array_map(fn ($d) => [$d['slug'], md5($d['html'])], $docs))
            .md5_file(resource_path('views/docs/pdf.blade.php')).json_encode($brand)), 0, 16);
        $target = storage_path('app/docs-cache/'.($slug ?? 'all').'-'.$hash.'.pdf');
        if (! is_file($target)) {
            File::ensureDirectoryExists(dirname($target));
            foreach (File::glob(storage_path('app/docs-cache/'.($slug ?? 'all').'-*.pdf')) as $old) {
                File::delete($old);
            }
            Pdf::loadView('docs.pdf', ['docs' => $docs, 'brand' => $brand, 'combined' => $slug === null, 'generatedAt' => now()])
                ->setPaper('a4')->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
                ->save($target);
        }

        return $target;
    }

    public function filename(?string $slug = null): string
    {
        $name = Str::slug(app(BrandingService::class)->platform()['display_name']);

        return $slug === null ? "{$name}-documentation.pdf" : "{$name}-".Str::slug(self::DOCUMENTS[$slug][1]).'.pdf';
    }
}
