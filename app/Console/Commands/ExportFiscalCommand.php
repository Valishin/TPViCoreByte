<?php

namespace App\Console\Commands;

use App\Services\Fiscal\FiscalExportService;
use App\Support\AllowedInReadOnly;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('tpv:export-fiscal {--from= : Fecha inicial (AAAA-MM-DD)} {--to= : Fecha final (AAAA-MM-DD), por defecto hoy}')]
#[Description('Exporta tickets, rectificativos y cierres Z del periodo a un ZIP (CSV + PDF)')]
#[AllowedInReadOnly]
class ExportFiscalCommand extends Command
{
    public function handle(FiscalExportService $export): int
    {
        $from = Carbon::parse($this->option('from') ?? today()->startOfMonth());
        $to = Carbon::parse($this->option('to') ?? today());

        $path = $export->export($from, $to);
        $this->info("Exportado: {$path}");

        return self::SUCCESS;
    }
}
