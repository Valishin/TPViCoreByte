<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\BillingCustomerResource;
use App\Models\Customer;
use App\Services\Billing\BillingCustomers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Datos fiscales de los destinatarios de factura, desde la caja. */
class BillingCustomerController extends Controller
{
    public function __construct(private BillingCustomers $customers) {}

    public function index(Request $request): JsonResponse
    {
        $query = (string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q'];

        return response()->json(['data' => BillingCustomerResource::collection($this->customers->search($query))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(BillingCustomers::rules(null, $request->all()));

        return response()->json(['data' => new BillingCustomerResource($this->customers->save($data))], 201);
    }

    /** Añade o corrige los datos fiscales de un cliente (p. ej. uno de reparto). */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        abort_if($customer->isAnonymized(), 404);
        $data = $request->validate(BillingCustomers::rules($customer, $request->all()));

        return response()->json(['data' => new BillingCustomerResource($this->customers->save($data, $customer))]);
    }
}
