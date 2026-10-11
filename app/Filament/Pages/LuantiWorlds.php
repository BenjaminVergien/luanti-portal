<?php

namespace App\Filament\Pages;

use App\Models\LuantiServer;
use App\Models\LuantiWorld;
use App\Services\LuantiWorldRegistry;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LuantiWorlds extends AccessTablePage
{
    protected static ?string $title = 'Luanti-Welten';

    protected static ?string $slug = 'welten';

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' && auth()->user() !== null
            && Gate::forUser(auth()->user())->allows('viewAny', LuantiWorld::class);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('Welt anlegen')->schema($this->worldFields())
            ->action(function (array $data, Schema $schema): void {
                $this->saveWorld($schema, fn () => app(LuantiWorldRegistry::class)->create($this->actor(), $data), 'Welt angelegt');
            })];
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(LuantiWorldRegistry::class)->query($this->actor()))->columns([
            TextColumn::make('name')->label('Name')->searchable()->sortable(),
            TextColumn::make('server.name')->label('Server')->searchable()->sortable(),
            TextColumn::make('world_identifier')->label('Weltkennung')->searchable(),
            IconColumn::make('is_selectable')->label('Beantragbar')->boolean(),
        ])->recordActions([
            Action::make('edit')->label('Bearbeiten')
                ->fillForm(fn (LuantiWorld $record): array => $record->only(['luanti_server_id', 'name', 'world_identifier', 'is_selectable']))
                ->schema($this->worldFields())->action(function (LuantiWorld $record, array $data, Schema $schema): void {
                    $this->saveWorld($schema, fn () => app(LuantiWorldRegistry::class)->update($this->actor(), $record, $data), 'Gespeichert');
                }),
        ])->emptyStateHeading('Noch keine Luanti-Welten')
            ->emptyStateDescription('Lege bestehende Welten an, damit Einrichtungen sie beantragen können.');
    }

    private function saveWorld(Schema $schema, Closure $save, string $message): void
    {
        try {
            $save();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$schema->getStatePath().'.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->saved($message);
    }

    /** @return list<Select|TextInput|Toggle> */
    private function worldFields(): array
    {
        return [
            Select::make('luanti_server_id')->label('Server')->required()->searchable()
                ->options(fn (): array => LuantiServer::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('world_identifier')->label('Weltkennung')->required()->maxLength(255)
                ->helperText('Kennung der bereits bestehenden Welt auf diesem Server.'),
            Toggle::make('is_selectable')->label('Beantragbar')->default(true)->required()
                ->helperText('Deaktivierte Welten bleiben in bestehenden Anträgen erhalten.'),
        ];
    }
}
