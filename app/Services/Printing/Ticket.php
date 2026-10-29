<?php

namespace App\Services\Printing;

/**
 * Contenido de un ticket, independiente de cómo se imprima.
 *
 * Se construye con métodos encadenables y PrinterManager lo traduce a ESC/POS
 * (impresora real) o a texto legible (modo simulado).
 */
class Ticket
{
    public const ALIGN_LEFT = 'left';

    public const ALIGN_CENTER = 'center';

    public const ALIGN_RIGHT = 'right';

    /** @var list<array{type: string, ...}> */
    private array $operations = [];

    public function line(string $text = '', string $align = self::ALIGN_LEFT, bool $bold = false, bool $large = false, bool $reverse = false): static
    {
        $this->operations[] = compact('text', 'align', 'bold', 'large', 'reverse') + ['type' => 'line'];

        return $this;
    }

    public function title(string $text): static
    {
        return $this->line($text, self::ALIGN_CENTER, bold: true, large: true);
    }

    /** Dos textos en la misma línea: uno a la izquierda y otro a la derecha (importes). */
    public function columns(string $left, string $right, bool $bold = false): static
    {
        $this->operations[] = ['type' => 'columns', 'left' => $left, 'right' => $right, 'bold' => $bold];

        return $this;
    }

    public function separator(string $char = '-'): static
    {
        $this->operations[] = ['type' => 'separator', 'char' => $char];

        return $this;
    }

    public function feed(int $lines = 1): static
    {
        $this->operations[] = ['type' => 'feed', 'lines' => $lines];

        return $this;
    }

    /** Pulso al cajón portamonedas conectado a la impresora (RJ11). */
    public function pulse(): static
    {
        $this->operations[] = ['type' => 'pulse'];

        return $this;
    }

    public function cut(): static
    {
        $this->operations[] = ['type' => 'cut'];

        return $this;
    }

    /** Reconstruye un ticket guardado (print_jobs.content) para reimprimirlo igual. */
    public static function fromOperations(array $operations): self
    {
        $ticket = new self;
        $ticket->operations = array_values($operations);

        return $ticket;
    }

    /** @return list<array{type: string, ...}> */
    public function operations(): array
    {
        return $this->operations;
    }
}
