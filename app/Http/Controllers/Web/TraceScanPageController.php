<?php

namespace App\Http\Controllers\Web;

use App\Domain\Traceability\Services\ScanService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The public page a QR code opens: the recorded status of the pack, with the authenticity disclaimer. */
class TraceScanPageController extends Controller
{
    public function __invoke(Request $request, string $token, ScanService $scans): View
    {
        $result = $scans->scan($token, $request->ip());
        unset($result['identifier']);

        return view('trace.scan', ['result' => $result]);
    }
}
