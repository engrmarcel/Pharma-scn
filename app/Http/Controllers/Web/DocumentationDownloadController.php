<?php

namespace App\Http\Controllers\Web;

use App\Domain\Platform\Services\DocumentationService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** PDF downloads of the platform documentation: one document, or all of them in one file. */
class DocumentationDownloadController extends Controller
{
    public function __invoke(DocumentationService $docs, ?string $slug = null): BinaryFileResponse
    {
        Gate::authorize('platform.documentation.view');
        abort_if($slug !== null && ! $docs->exists($slug), 404);

        return response()->download($docs->pdf($slug), $docs->filename($slug), ['Content-Type' => 'application/pdf']);
    }
}
