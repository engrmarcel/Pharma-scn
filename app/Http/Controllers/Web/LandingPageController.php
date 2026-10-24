<?php

namespace App\Http\Controllers\Web;

use App\Domain\Platform\Services\BrandingService;
use App\Domain\Platform\Services\LandingPageService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** The public landing page (content edited at /admin/landing-page) and the media uploaded for it. */
class LandingPageController extends Controller
{
    public function show(LandingPageService $landing, BrandingService $branding): View
    {
        return view('landing', ['c' => $landing->content(), 'brand' => $branding->platform()]);
    }

    /** Images and videos uploaded in the editor; names are random, so only published media are reachable. */
    public function media(string $file): Response
    {
        $path = 'landing/'.$file;
        $disk = Storage::disk(config('platform.landing.media_disk'));
        abort_unless($disk->exists($path), 404);

        // Streamed through the storage API, so it works for a local disk and for object storage alike.
        return $disk->response($path, null, ['Cache-Control' => 'public, max-age=604800', 'X-Content-Type-Options' => 'nosniff']);
    }
}
