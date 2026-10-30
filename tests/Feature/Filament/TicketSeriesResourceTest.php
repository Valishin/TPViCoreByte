<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TicketSeries\Pages\EditTicketSeries;
use App\Filament\Resources\TicketSeries\Pages\ListTicketSeries;
use App\Models\Setting;
use Livewire\Livewire;

class TicketSeriesResourceTest extends FilamentTestCase
{
    public function test_digits_are_configurable_with_a_minimum_of_five(): void
    {
        $series = Setting::current()->defaultTicketSeries;

        Livewire::test(EditTicketSeries::class, ['record' => $series->id])
            ->fillForm(['digits' => 4])->call('save')->assertHasFormErrors(['digits' => 'min']);

        Livewire::test(EditTicketSeries::class, ['record' => $series->id])
            ->fillForm(['digits' => 7])->call('save')->assertHasNoFormErrors();

        $this->assertSame(7, $series->refresh()->digits);
        Livewire::test(ListTicketSeries::class)->assertSee($this->docNumber($series->code, 1, 7));
    }
}
