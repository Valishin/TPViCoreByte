<?php

namespace App\Filament\Components;

use App\Support\Format;
use Closure;
use Filament\Forms\Components\TextInput;

/**
 * Campo de importe: el usuario escribe euros con coma ("12,50") y se guarda
 * en céntimos (1250).
 */
class MoneyInput extends TextInput
{
    protected bool $allowNegative = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->suffix('€')
            ->inputMode('decimal')
            ->placeholder('0,00')
            ->extraInputAttributes(['class' => 'text-end'])
            ->formatStateUsing(fn ($state) => is_numeric($state) ? Format::moneyInput((int) $state) : $state)
            ->dehydrateStateUsing(fn ($state) => blank($state) ? null : Format::parseMoney($state))
            ->rule(fn (MoneyInput $component): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component) {
                if (blank($value)) {
                    return;
                }
                $cents = Format::parseMoney($value);
                if ($cents === null) {
                    $fail('Escribe un importe válido, por ejemplo 12,50.');
                } elseif ($cents < 0 && ! $component->allowNegative) {
                    $fail('El importe no puede ser negativo.');
                }
            });
    }

    public function allowNegative(bool $condition = true): static
    {
        $this->allowNegative = $condition;

        return $this;
    }
}
