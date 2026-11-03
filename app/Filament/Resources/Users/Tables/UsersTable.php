<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ColorColumn::make('color')->label(''),
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('role')->label('Rol')->badge()
                    ->color(fn (UserRole $state) => match ($state) {
                        UserRole::Admin => 'danger',
                        UserRole::Manager => 'warning',
                        UserRole::Waiter => 'info',
                        UserRole::Courier => 'success',
                    }),
                TextColumn::make('email')->label('Email')->placeholder('—')->searchable(),
                TextColumn::make('username')->label('Usuario')->placeholder('—')->searchable()->toggleable(),
                IconColumn::make('pin')->label('PIN')->boolean()->state(fn ($record) => filled($record->pin)),
                IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('role')->label('Rol')->options(UserRole::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('Si tiene pedidos o cobros no se podrá borrar: desactívalo.'),
            ]);
    }
}
