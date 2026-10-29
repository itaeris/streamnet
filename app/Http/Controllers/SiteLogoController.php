<?php

namespace App\Http\Controllers;

use App\Services\SiteLogo;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SiteLogoController extends Controller
{
    public function __invoke(Request $request, SiteLogo $logos): Response
    {
        $domain = strtolower((string) $request->query('domain', ''));
        $image = $logos->fetch($domain);
        abort_unless(is_array($image), 404);

        return response($image['body'], 200, [
            'Content-Type' => $image['type'],
            'Cache-Control' => 'private, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
