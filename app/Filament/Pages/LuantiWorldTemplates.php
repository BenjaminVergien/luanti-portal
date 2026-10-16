<?php

namespace App\Filament\Pages;

use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Services\LuantiWorldTemplateRegistry;
use App\Services\LuantiWorldTemplateSynchronizer;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LuantiWorldTemplates extends AccessTablePage
{
    protected static ?string $title = 'Luanti-Weltvorlagen';

    protected static ?string $slug = 'welten';

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' && auth()->user() !== null
            && Gate::forUser(auth()->user())->allows('viewAny', LuantiWorldTemplate::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncWorlds')->label('Weltvorlagen synchronisieren')
                ->schema([Select::make('luanti_server_id')->label('Server')->required()->searchable()
                    ->options(fn (): array => LuantiServer::query()->whereNotNull('api_base_url')->whereNotNull('api_token')
                        ->orderBy('name')->pluck('name', 'id')->all())])
                ->action(function (array $data): void {
                    $result = app(LuantiWorldTemplateSynchronizer::class)->sync($this->actor(), LuantiServer::query()->findOrFail($data['luanti_server_id']));
                    Notification::make()->title($result['title'])->body($result['body'])
                        ->status($result['successful'] ? 'success' : 'danger')->send();
                }),
            Action::make('create')->label('Vorlageneintrag anlegen')->schema($this->worldFields())
                ->action(function (array $data, Schema $schema): void {
                    $this->saveWorld($schema, fn () => app(LuantiWorldTemplateRegistry::class)->create($this->actor(), $data), 'Welt angelegt');
                })];
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(LuantiWorldTemplateRegistry::class)->query($this->actor()))->columns([
            TextColumn::make('name')->label('Name')->searchable()->sortable(),
            TextColumn::make('server.name')->label('Server')->searchable()->sortable(),
            TextColumn::make('template_identifier')->label('Vorlagenkennung')->searchable(),
            IconColumn::make('is_selectable')->label('Beantragbar')->boolean(),
            IconColumn::make('is_available')->label('Auf Server vorhanden')->boolean(),
            TextColumn::make('last_discovered_at')->label('Zuletzt gefunden')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')->placeholder('Manuell angelegt'),
        ])->recordActions([
            Action::make('edit')->label('Bearbeiten')
                ->fillForm(fn (LuantiWorldTemplate $record): array => $record->only(['luanti_server_id', 'name', 'template_identifier', 'is_selectable']))
                ->schema($this->worldFields())->action(function (LuantiWorldTemplate $record, array $data, Schema $schema): void {
                    $this->saveWorld($schema, fn () => app(LuantiWorldTemplateRegistry::class)->update($this->actor(), $record, $data), 'Gespeichert');
                }),
        ])->emptyStateHeading('Noch keine Luanti-Weltvorlagen')
            ->emptyStateDescription('Synchronisiere die auf dem Server veröffentlichten Weltvorlagen.');
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
                ->options(fn (): array => LuantiServer::query()->orderBy('name')->pluck('name', 'id')->all())
                ->disabled(fn (?LuantiWorldTemplate $record): bool => $record?->last_discovered_at !== null)->dehydrated(),
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('template_identifier')->label('Vorlagenkennung')->required()->maxLength(255)
                ->disabled(fn (?LuantiWorldTemplate $record): bool => $record?->last_discovered_at !== null)->dehydrated()
                ->helperText('Kennung einer veröffentlichten Weltvorlage. Erst nach Synchronisierung beantragbar.'),
            Toggle::make('is_selectable')->label('Beantragbar')->default(true)->required()
                ->helperText('Deaktivierte Welten bleiben in bestehenden Anträgen erhalten.'),
        ];
    }
}
