<?php

namespace Tests\Feature\Tickets;

use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Exceptions\ImmutableTicketException;
use App\Filament\Pages\SetupWizard;
use App\Filament\Resources\TicketSeries\Pages\EditTicketSeries;
use App\Filament\Resources\TicketSeries\SeriesNumbering;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketSeries;
use App\Models\User;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Tickets\SeriesStartService;
use App\Services\Tickets\TicketIssuer;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Primer número de una serie (continuar la numeración de un sistema anterior):
 * solo el contador del año en curso, bloqueado tras el primer documento y con
 * auditoría.
 */
class SeriesStartServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TicketSeries $series;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 12:00:00');
        $this->createSettings();
        $this->admin = User::factory()->role(UserRole::Admin)->create();
        $this->series = Setting::current()->defaultTicketSeries;
        $this->series->update(['code' => 'T']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): SeriesStartService
    {
        return app(SeriesStartService::class);
    }

    private function issue(): Ticket
    {
        $order = app(OrderService::class)->open(OrderType::Bar, $this->admin);
        app(OrderLineBuilder::class)->addProduct($order, Product::factory()->price(500)->create());

        return app(TicketIssuer::class)->issue($order, $this->admin);
    }

    private function rejected(callable $call): string
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }
        $this->fail('Tenía que rechazarse');
    }

    public function test_before_issuing_the_first_number_can_be_set_and_changed(): void
    {
        $next = $this->service()->setFirstNumber($this->series, 1543, $this->admin);
        $this->assertSame('T-26-001543', $next->full);

        $this->service()->setFirstNumber($this->series, 1600, $this->admin);   // aún se puede cambiar
        $this->assertSame(1600, $this->service()->status($this->series)['next']);

        $ticket = $this->issue();
        $this->assertSame(1600, $ticket->number);
        $this->assertSame('T-26-001600', $ticket->full_number);
        $this->assertSame(1601, $this->issue()->number, 'Después sigue correlativa.');
    }

    public function test_locked_after_the_first_document_of_the_year(): void
    {
        $this->service()->setFirstNumber($this->series, 1543, $this->admin);
        $this->issue();

        $status = $this->service()->status($this->series);
        $this->assertTrue($status['locked']);
        $this->assertStringContainsString(SeriesStartService::LOCKED, $this->rejected(fn () => $this->service()->setFirstNumber($this->series, 5000, $this->admin)));
        $this->assertSame(1544, $this->service()->status($this->series)['next'], 'El contador no cambia.');
    }

    public function test_validated_against_the_last_issued_number_and_the_digits(): void
    {
        $this->issue();   // T-26-000001
        $this->rejected(fn () => $this->service()->setFirstNumber($this->series, 1, $this->admin));
        $this->rejected(fn () => $this->service()->setFirstNumber($this->series, 0, $this->admin));

        $other = TicketSeries::factory()->create(['code' => 'X', 'digits' => 5]);
        $this->assertStringContainsString('de 1 a 99999', $this->rejected(fn () => $this->service()->setFirstNumber($other, 100000, $this->admin)));
        $this->assertSame(0, $other->lastNumber());
    }

    public function test_each_change_is_audited_and_the_log_is_append_only(): void
    {
        $this->service()->setFirstNumber($this->series, 1543, $this->admin);
        $this->service()->setFirstNumber($this->series, 1543, $this->admin);   // sin cambios: no se apunta

        $log = AuditLog::query()->sole();
        $this->assertSame(AuditLog::SERIES_FIRST_NUMBER, $log->action);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertTrue($log->subject->is($this->series));
        $this->assertSame(['series' => 'T', 'year' => 2026, 'first_number' => 1], $log->old_values);
        $this->assertSame(['series' => 'T', 'year' => 2026, 'first_number' => 1543], $log->new_values);
        $this->assertSame('2026-03-10 12:00:00', $log->created_at->format('Y-m-d H:i:s'));

        $this->expectException(ImmutableTicketException::class);
        $log->update(['new_values' => ['first_number' => 1]]);
    }

    public function test_the_database_also_refuses_to_change_or_delete_audit_rows(): void
    {
        $this->service()->setFirstNumber($this->series, 1543, $this->admin);
        $id = AuditLog::query()->value('id');

        foreach ([fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'x']), fn () => DB::table('audit_logs')->where('id', $id)->delete()] as $query) {
            try {
                DB::transaction($query);
                $this->fail('El trigger tenía que impedirlo');
            } catch (QueryException $e) {
                $this->assertStringContainsString('solo admite inserciones', $e->getMessage());
            }
        }
    }

    public function test_only_the_current_year_counter_changes(): void
    {
        // Tickets del año pasado no bloquean este año ni se tocan.
        Carbon::setTestNow('2025-12-31 20:00:00');
        $old = $this->issue();
        Carbon::setTestNow('2026-03-10 12:00:00');

        $this->service()->setFirstNumber($this->series, 1543, $this->admin);

        $this->assertSame(1, $this->series->lastNumber(2025), 'El contador de 2025 sigue igual.');
        $this->assertSame('T-25-000001', $old->fresh()->full_number);
        $this->assertSame(1543, $this->issue()->number);

        // El año que viene la serie vuelve a empezar en 1.
        Carbon::setTestNow('2027-01-01 10:00:00');
        $this->assertSame('T-27-000001', $this->issue()->full_number);
        $this->assertSame(1543, $this->series->lastNumber(2026));
    }

    public function test_admin_series_page_button_with_confirmation(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin, 'web');

        Livewire::test(EditTicketSeries::class, ['record' => $this->series->getRouteKey()])
            ->assertActionVisible('primerNumero')
            ->mountAction('primerNumero')
            ->assertMountedActionModalSee(SeriesNumbering::CONFIRM)
            ->setActionData(['first_number' => 1543])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1543, $this->service()->status($this->series)['next']);
        $this->assertSame($this->admin->id, AuditLog::query()->sole()->user_id);

        $this->issue();
        Livewire::test(EditTicketSeries::class, ['record' => $this->series->getRouteKey()])
            ->assertActionHidden('primerNumero');
    }

    public function test_setup_wizard_fiscal_step_shows_the_numbering_section(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->admin, 'web');

        Livewire::test(SetupWizard::class)
            ->assertSee('Numeración')
            ->assertSee('Primer número de la serie: 1 · próximo: T-26-000001');

        $this->service()->setFirstNumber($this->series, 1543, $this->admin);
        $this->issue();
        Livewire::test(SetupWizard::class)
            ->assertSee('Último emitido: T-26-001543')
            ->assertSee(SeriesStartService::LOCKED);
    }
}
