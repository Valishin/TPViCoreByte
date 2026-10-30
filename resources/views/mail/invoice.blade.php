Hola,

Te enviamos la {{ $invoice->type->isRectifying() ? 'factura rectificativa' : 'factura' }} {{ $invoice->fullNumber() }} del {{ $invoice->issued_at->format('d/m/Y') }}, por {{ \App\Support\Format::money($invoice->total) }}, en el PDF adjunto.

{{ $invoice->issuer['legal_name'] ?? '' }} · NIF {{ $invoice->issuer['tax_id'] ?? '' }}
{{ $invoice->issuer['address'] ?? '' }}

Recibes este correo porque aceptaste recibir tus facturas por email.
