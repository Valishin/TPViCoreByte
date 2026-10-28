<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Services\Pos\CatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Carta con ETag: si el cliente ya tiene esa versión, 304 sin cuerpo. */
class CatalogController extends Controller
{
    public function __invoke(Request $request, CatalogService $catalog): Response
    {
        $payload = $catalog->get();
        $etag = '"'.$payload['version'].'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response(json_encode($payload, JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/json',
            'ETag' => $etag,
            'Cache-Control' => 'no-cache',
        ]);
    }
}
