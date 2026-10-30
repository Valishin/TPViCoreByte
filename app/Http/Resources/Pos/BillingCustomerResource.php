<?php

namespace App\Http\Resources\Pos;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class BillingCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'legal_name' => $this->legal_name,
            'tax_id' => $this->tax_id,
            'tax_id_type' => $this->tax_id_type?->value,
            'fiscal_address' => $this->fiscal_address,
            'fiscal_postal_code' => $this->fiscal_postal_code,
            'fiscal_city' => $this->fiscal_city,
            'fiscal_province' => $this->fiscal_province,
            'fiscal_country' => $this->fiscal_country,
            'billing_email' => $this->billing_email,
            'accepts_email_invoices' => $this->email_invoices_accepted_at !== null,
            'has_fiscal_data' => $this->hasFiscalData(Setting::query()->value('invoice_require_address') ?? true),
        ];
    }
}
