<?php

namespace App\Filament\Pages;

use App\Models\LuantiServer;
use App\Models\LuantiWorldTemplate;
use App\Services\LuantiWorldTemplateRegistry;
use App\Services\PortalWorldTemplates;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
            Action::make('import')->label('Vorlage ins Portal importieren')
                ->schema([FileUpload::make('bundle')->label('Veröffentlichtes Vorlagen-ZIP')->required()->storeFiles(false)
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])->maxSize(12288)
                    ->helperText('Alle Vorlagen der Portal-Bibliothek werden bei der Erstellung eines Servers bereitgestellt.')])
                ->action(function (array $data, Schema $schema): void {
                    $this->saveWorld($schema, fn () => app(PortalWorldTemplates::class)->import($this->actor(), $data['bundle']->getRealPath()), 'Vorlage im Portal gespeichert');
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(LuantiWorldTemplateRegistry::class)->query($this->actor()))->columns([
            TextColumn::make('name')->label('Name')->searchable()->sortable(),
            TextColumn::make('server.name')->label('Server')->searchable()->sortable()->placeholder('Portal-Bibliothek'),
            TextColumn::make('template_identifier')->label('Vorlagenkennung')->searchable(),
            IconColumn::make('is_selectable')->label('Beantragbar')->boolean(),
            IconColumn::make('is_available')->label('Verfügbar')->boolean(),
            TextColumn::make('bundle_sha256')->label('Speicherort')
                ->formatStateUsing(fn (): string => 'Portal')->placeholder('Externer Server'),
            TextColumn::make('commissioned_at')->label('Bereitgestellt')->dateTime('d.m.Y H:i')->timezone('Europe/Berlin')
                ->placeholder(fn (LuantiWorldTemplate $record): string => $record->library_identifier !== null ? 'Im Portal gespeichert' : 'Manuell angelegt'),
        ])->recordActions([
            Action::make('edit')->label('Bearbeiten')
                ->fillForm(fn (LuantiWorldTemplate $record): array => $record->only(['luanti_server_id', 'name', 'template_identifier', 'is_selectable']))
                ->schema($this->worldFields())->action(function (LuantiWorldTemplate $record, array $data, Schema $schema): void {
                    $this->saveWorld($schema, fn () => app(LuantiWorldTemplateRegistry::class)->update($this->actor(), $record, $data), 'Gespeichert');
                }),
        ])->emptyStateHeading('Noch keine Luanti-Weltvorlagen')
            ->emptyStateDescription('Importiere veröffentlichte Vorlagen in die Portal-Bibliothek. Neue Server erhalten alle Vorlagen bei der Einrichtung.');
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
            Select::make('luanti_server_id')->label('Server')->required(fn (?LuantiWorldTemplate $record): bool => $record?->library_identifier === null)->searchable()
                ->options(fn (): array => LuantiServer::query()->orderBy('name')->pluck('name', 'id')->all())
                ->disabled()->dehydrated(),
            TextInput::make('name')->label('Name')->required()->maxLength(255),
            TextInput::make('template_identifier')->label('Vorlagenkennung')->required()->maxLength(255)
                ->disabled()->dehydrated()
                ->helperText('Kennung einer veröffentlichten Weltvorlage. Neue Server erhalten alle Portal-Vorlagen bei der Einrichtung.'),
            Toggle::make('is_selectable')->label('Beantragbar')->default(true)->required()
                ->helperText('Deaktivierte Welten bleiben in bestehenden Anträgen erhalten.'),
        ];
    }
}
