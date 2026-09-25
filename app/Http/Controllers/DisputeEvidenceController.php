<?php

namespace App\Http\Controllers;

use App\Models\DisputeEvidence;
use App\Models\SeriesMatch;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A dispute screenshot for admins (route group `admin`). The file lives on
 * the private disk; there is no public URL for it.
 */
class DisputeEvidenceController extends Controller
{
    public function __invoke(SeriesMatch $match, DisputeEvidence $evidence): StreamedResponse
    {
        abort_unless($evidence->series_match_id === $match->id, 404);

        return Storage::disk('local')->response($evidence->path, $evidence->name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
