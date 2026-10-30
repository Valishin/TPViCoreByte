<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Services\Verifactu\VerifactuStatus;
use App\Support\SystemDeclaration;
use Illuminate\Http\JsonResponse;

/** Avisos de VERI*FACTU para la caja y «Acerca de» con la declaración responsable. */
class VerifactuController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json(['notices' => VerifactuStatus::notices()]);
    }

    public function about(): JsonResponse
    {
        return response()->json(SystemDeclaration::data());
    }
}
