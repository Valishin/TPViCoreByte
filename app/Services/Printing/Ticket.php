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

    /**
     * Interlineado desde aquí (en puntos de 1/203", ESC 3): más aire entre
     * líneas, p. ej. en la parte de reparto. null vuelve al normal (ESC 2).
     */
    public function lineSpacing(?int $dots = null): static
    {
        $this->operations[] = ['type' => 'spacing', 'dots' => $dots === null ? null : max(1, min(255, $dots))];

        return $this;
    }

    /** Código QR centrado (p. ej. la dirección en Google Maps). size: 1-16 (tamaño del módulo). */
    public function qr(string $data, int $size = 6): static
    {
        $this->operations[] = ['type' => 'qr', 'data' => $data, 'size' => $size];

        return $this;
    }

    /**
     * QR tributario de VERI*FACTU como imagen ráster de tamaño fijo en papel
     * (30-40 mm, nivel M): App\Services\Verifactu\TaxQr. La imagen se genera al imprimir.
     */
    public function taxQr(string $url): static
    {
        $this->operations[] = ['type' => 'tax_qr', 'data' => $url];

        return $this;
    }

    /** Añade al final las operaciones de otro ticket. */
    public function append(self $other): static
    {
        array_push($this->operations, ...$other->operations());

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
