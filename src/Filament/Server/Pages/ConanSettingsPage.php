<?php

namespace Dtektion\ConanSettingsEditor\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Repositories\Daemon\DaemonServerRepository;
use Dtektion\ConanSettingsEditor\Services\ConanIniMapper;
use Dtektion\ConanSettingsEditor\Services\ConanServerDetector;
use Dtektion\ConanSettingsEditor\Services\ConanSettingsFileService;
use Dtektion\ConanSettingsEditor\Services\ConanServerVariableService;
use Dtektion\ConanSettingsEditor\Services\ConanSettingsSchema;
use Dtektion\ConanSettingsEditor\Services\PelicanServerStateService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\Attributes\Locked;
use Throwable;

class ConanSettingsPage extends Page
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $slug = 'conan-settings';

    protected static ?int $navigationSort = 30;

    protected string $view = 'conan-settings-editor::filament.server.pages.conan-settings-page';

    #[Locked]
    public bool $isSafeToEdit = false;

    #[Locked]
    public string $stateLabel = 'Unknown';

    #[Locked]
    public string $stateMessage = '';

    #[Locked]
    public string $settingsPath = '';

    #[Locked]
    public bool $settingsFileExists = false;

    #[Locked]
    public ?string $settingsFileError = null;

    /** @var array<string, mixed> */
    #[Locked]
    public array $parsedTyped = [];

    /** @var array<string, string> */
    #[Locked]
    public array $parsedRaw = [];

    /** @var array<int, array{name: string, path: string, size: mixed, modified: mixed}> */
    #[Locked]
    public array $backups = [];

    #[Locked]
    public ?string $detectedMode = null;

    #[Locked]
    public int $typedKeyCount = 0;

    #[Locked]
    public int $unknownKeyCount = 0;

    /** @var array<string, mixed> form bound values keyed by ini_key */
    public array $formData = [];

    public string $fieldSearch = '';

    public bool $showMissingSchemaFields = false;

    public bool $awaitingPowerSettle = false;

    public ?bool $expectedSafeToEdit = null;

    public int $powerPollTicks = 0;

    /** Egg SRV_NAME (startup -ServerName); not an INI key on this install. */
    public string $serverNameValue = '';

    public string $savedServerNameValue = '';

    public bool $hasServerNameVariable = false;

    /** Egg SRV_PW (startup -ServerPassword). */
    public string $serverPasswordValue = '';

    public string $savedServerPasswordValue = '';

    public bool $hasServerPasswordVariable = false;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return parent::canAccess()
            && $server !== null
            && ConanServerDetector::isConanServer($server)
            && (bool) user()?->can(SubuserPermission::FileReadContent, $server);
    }

    public static function getNavigationLabel(): string
    {
        return 'Conan Settings';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Conan Exiles';
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(
        PelicanServerStateService $serverStateService,
        ConanSettingsFileService $fileService,
        ConanServerVariableService $variables,
    ): void {
        $server = Filament::getTenant();
        $schema = $this->schema();

        $this->hasServerNameVariable = $variables->has($server, 'SRV_NAME');
        $this->serverNameValue = (string) ($variables->get($server, 'SRV_NAME') ?? '');
        $this->savedServerNameValue = $this->serverNameValue;
        $this->hasServerPasswordVariable = $variables->has($server, 'SRV_PW');
        $this->serverPasswordValue = (string) ($variables->get($server, 'SRV_PW') ?? '');
        $this->savedServerPasswordValue = $this->serverPasswordValue;

        $this->settingsPath = $fileService->resolveExistingPath(
            $server,
            $schema->pathFallbacks('ServerSettings.ini')
        ) ?? (string) $schema->pathFor('ServerSettings.ini');

        $this->isSafeToEdit = $serverStateService->isSafeToEdit($server);
        $this->stateLabel = $serverStateService->getStateLabel($server);
        $this->stateMessage = $serverStateService->getStatusMessage($server);

        try {
            $this->settingsFileExists = $fileService->exists($server, $this->settingsPath);
            if ($this->settingsFileExists) {
                $contents = $fileService->read($server, $this->settingsPath);
                $this->hydrateFromContents($contents);
                $this->backups = $fileService->listBackups($server, $this->settingsPath);
            } else {
                $this->settingsFileError = 'ServerSettings.ini was not found. Start the server once so Conan can generate it.';
            }
        } catch (Throwable $e) {
            report($e);
            $this->settingsFileError = 'Could not read ServerSettings.ini via Wings.';
        }

        $this->fillFormState();
    }

    private function fillFormState(): void
    {
        $this->form->fill($this->formData);
    }

    protected function getFormSchema(): array
    {
        $schema = $this->schema();
        $components = [];

        $components[] = Section::make('Server status')
            ->schema([
                TextInput::make('_state')
                    ->label('Power state')
                    ->formatStateUsing(fn () => $this->stateLabel)
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('_path')
                    ->label('Settings path')
                    ->formatStateUsing(fn () => $this->settingsPath)
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('_mode')
                    ->label('Detected combat mode')
                    ->formatStateUsing(fn () => $this->detectedMode ?? 'unknown')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('_counts')
                    ->label('Mapped keys')
                    ->formatStateUsing(fn () => "{$this->typedKeyCount} typed / {$this->unknownKeyCount} unknown · ".count($this->liveIniKeys())." live keys (runtime)")
                    ->disabled()
                    ->dehydrated(false),
            ])
            ->description(fn (): string => $this->stateMessage.($this->settingsFileError ? ' '.$this->settingsFileError : ''))
            ->columns(2);

        $components[] = Section::make('Server identity & access')
            ->description('Browser name and join password come from Pelican egg variables (startup flags). Description/MOTD, admin password, and region are ServerSettings.ini keys. Restart after name/password changes.')
            ->schema([
                TextInput::make('__server_name')
                    ->label('Server name')
                    ->helperText(fn (): string => $this->hasServerNameVariable
                        ? 'Egg variable SRV_NAME → startup -ServerName. Shown in the multiplayer browser. Applied on next start.'
                        : 'SRV_NAME egg variable not found — set name under Pelican Startup / Variables.')
                    ->disabled(fn (): bool => ! $this->isSafeToEdit || ! $this->hasServerNameVariable)
                    ->maxLength(64),
                Textarea::make('ServerMessageOfTheDay')
                    ->label('Server description (Message of the Day)')
                    ->helperText('INI key ServerMessageOfTheDay. Conan has no separate ServerDescription field; this is the public MOTD/description players see on connect.')
                    ->rows(3)
                    ->disabled(fn (): bool => ! $this->isSafeToEdit || ! $this->settingsFileExists)
                    ->columnSpanFull(),
                TextInput::make('__server_password')
                    ->label('Join password')
                    ->helperText(fn (): string => $this->hasServerPasswordVariable
                        ? 'Egg variable SRV_PW → startup -ServerPassword. Also mirrored to INI ServerPassword on save when present. Empty = public.'
                        : 'SRV_PW egg variable missing; edit ServerPassword under Access if shown, or add egg variable.')
                    ->password()
                    ->revealable()
                    ->disabled(fn (): bool => ! $this->isSafeToEdit || ! $this->hasServerPasswordVariable),
                TextInput::make('AdminPassword')
                    ->label('Admin password')
                    ->helperText('INI AdminPassword for MakeMeAdmin <password>. Not the join password. Leave blank in the form only if you intend to clear it.')
                    ->password()
                    ->revealable()
                    ->disabled(fn (): bool => ! $this->isSafeToEdit || ! $this->settingsFileExists),
                TextInput::make('serverRegion')
                    ->label('Server region')
                    ->helperText('INI serverRegion — matchmaking/list region. Wrong value can hide the server in the browser. Integer enum (0 = common default/auto on many setups).')
                    ->numeric()
                    ->integer()
                    ->disabled(fn (): bool => ! $this->isSafeToEdit || ! $this->settingsFileExists),
            ])
            ->columns(2)
            ->collapsed(false);

        $components[] = Section::make('Combat mode preset')
            ->description('Sets PVPEnabled, CombatModeModifier, and CanDamagePlayerOwnedStructures together. Requires save + restart.')
            ->schema([
                Select::make('__mode_preset')
                    ->label('Apply mode')
                    ->options([
                        'pve' => 'PvE',
                        'pve-c' => 'PvE-Conflict',
                        'pvp' => 'PvP',
                    ])
                    ->placeholder('Select to apply keys…')
                    ->live()
                    ->afterStateUpdated(function (?string $state): void {
                        if ($state === null || $state === '') {
                            return;
                        }
                        $this->applyModePreset($state);
                    })
                    ->dehydrated(false),
            ])
            ->collapsed(false);

        $components[] = Section::make('Filters')
            ->schema([
                TextInput::make('__field_search')
                    ->label('Search settings')
                    ->placeholder('Filter by key or label…')
                    ->live(debounce: 300)
                    ->dehydrated(false)
                    ->afterStateUpdated(fn ($state) => $this->fieldSearch = (string) ($state ?? '')),
                Toggle::make('__show_missing')
                    ->label('Show schema fields not yet present in the live INI')
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(fn ($state) => $this->showMissingSchemaFields = (bool) $state),
            ])
            ->columns(2)
            ->collapsed();

        $search = strtolower(trim($this->fieldSearch));

        foreach ($schema->groups() as $group) {
            $groupId = $group['id'];
            if (in_array($groupId, ['Engine', 'Rcon', 'Meta'], true)) {
                // Engine/Game.ini paths not always present; keep SS-focused for v0.1 UI
                if ($groupId !== 'Meta') {
                    continue;
                }
            }

            $liveKeys = $this->liveIniKeys();
            $fields = array_values(array_filter(
                $schema->fieldsForGroup($groupId),
                function (array $field) use ($search, $liveKeys): bool {
                    if (($field['file'] ?? '') !== 'ServerSettings.ini') {
                        return false;
                    }
                    $key = (string) ($field['ini_key'] ?? '');
                    // Surfaced in Server identity & access section
                    if (in_array($key, [
                        'ServerMessageOfTheDay',
                        'ServerPassword',
                        'AdminPassword',
                        'serverRegion',
                    ], true)) {
                        return false;
                    }
                    $presentLive = $key !== '' && isset($liveKeys[$key]);
                    if (! $presentLive && ! $this->showMissingSchemaFields) {
                        return false;
                    }
                    if ($search === '') {
                        return true;
                    }
                    $hay = strtolower(($field['ini_key'] ?? '').' '.($field['label'] ?? '').' '.($field['help'] ?? ''));

                    return str_contains($hay, $search);
                }
            ));

            if ($fields === []) {
                continue;
            }

            $formFields = [];
            foreach ($fields as $field) {
                $formFields[] = $this->makeInput($field);
            }

            $components[] = Section::make($group['label'] ?? $groupId)
                ->schema($formFields)
                ->columns(2)
                ->collapsed($groupId === 'Advanced Present Only' || $groupId === 'PVP Schedule' || $groupId === 'Meta')
                ->collapsible();
        }

        if ($this->backups !== []) {
            $backupLines = collect($this->backups)->take(10)->map(
                fn (array $b): string => $b['name']
            )->implode(', ');
            $components[] = Section::make('Backups')
                ->description('Newest first (up to 10 shown): '.$backupLines)
                ->collapsed()
                ->schema([]);
        }

        $components[] = Section::make('About')
            ->description('Schema-driven editor. Mapping informed by balnaimi/conan-exiles-server (independent implementation). See plugin CREDITS.md.')
            ->collapsed()
            ->schema([]);

        return $components;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->keyBindings(['mod+s'])
                ->visible(fn (): bool => $this->settingsFileExists)
                ->disabled(fn (): bool => ! $this->canSave())
                ->authorize(fn (): bool => (bool) user()?->can(SubuserPermission::FileUpdate, Filament::getTenant()))
                ->action(fn () => $this->writeSettings()),
            Action::make('resetChanges')
                ->label('Reset form')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => $this->settingsFileExists)
                ->action(fn () => $this->resetFormFromFile()),
            Action::make('stopServer')
                ->label('Stop server')
                ->icon('tabler-player-stop')
                ->color('danger')
                ->visible(fn (): bool => ! $this->isSafeToEdit)
                ->authorize(fn (): bool => (bool) user()?->can(SubuserPermission::ControlStop, Filament::getTenant()))
                ->requiresConfirmation()
                ->action(fn () => $this->sendPowerAction('stop')),
            Action::make('startServer')
                ->label('Start server')
                ->icon('tabler-player-play')
                ->color('success')
                ->visible(fn (): bool => $this->isSafeToEdit)
                ->authorize(fn (): bool => (bool) user()?->can(SubuserPermission::ControlStart, Filament::getTenant()))
                ->requiresConfirmation()
                ->action(fn () => $this->sendPowerAction('start')),
        ];
    }

    public function canSave(): bool
    {
        return $this->isSafeToEdit
            && $this->settingsFileExists
            && $this->settingsFileError === null;
    }

    public function writeSettings(): void
    {
        $server = Filament::getTenant();
        $state = app(PelicanServerStateService::class);
        $files = app(ConanSettingsFileService::class);
        $mapper = new ConanIniMapper($this->schema());

        if (! (bool) user()?->can(SubuserPermission::FileUpdate, $server)) {
            Notification::make()->title('Permission denied')->danger()->send();

            return;
        }

        if (! $state->isSafeToEdit($server)) {
            Notification::make()
                ->title('Server must be stopped')
                ->body('Stop the server before changing Conan settings.')
                ->warning()
                ->send();

            return;
        }

        $changes = $this->changedValues();
        $newName = trim((string) ($this->formData['__server_name'] ?? $this->serverNameValue));
        $nameDirty = $this->hasServerNameVariable && $newName !== $this->savedServerNameValue;
        $newJoinPw = (string) ($this->formData['__server_password'] ?? $this->serverPasswordValue);
        $joinPwDirty = $this->hasServerPasswordVariable && $newJoinPw !== $this->savedServerPasswordValue;
        $identityIniDirty = false;
        foreach (['ServerMessageOfTheDay', 'AdminPassword', 'serverRegion', 'ServerPassword'] as $identityKey) {
            if (! array_key_exists($identityKey, $this->formData)) {
                continue;
            }
            $old = $this->parsedTyped[$identityKey] ?? $this->parsedRaw[$identityKey] ?? null;
            $new = $this->formData[$identityKey];
            if ((string) $old !== (string) $new) {
                $identityIniDirty = true;
                break;
            }
        }
        if ($changes === [] && ! $nameDirty && ! $joinPwDirty && ! $identityIniDirty) {
            Notification::make()->title('No changes to save')->info()->send();

            return;
        }

        try {
            $nameNotes = [];
            $vars = app(ConanServerVariableService::class);
            $newName = trim((string) ($this->formData['__server_name'] ?? $this->serverNameValue));
            if ($this->hasServerNameVariable && $newName !== $this->savedServerNameValue) {
                if ($newName === '') {
                    Notification::make()->title('Server name cannot be empty')->warning()->send();

                    return;
                }
                $vars->set($server, 'SRV_NAME', $newName);
                $this->serverNameValue = $newName;
                $this->savedServerNameValue = $newName;
                $nameNotes[] = 'SRV_NAME updated';
            }
            $newJoinPw = (string) ($this->formData['__server_password'] ?? $this->serverPasswordValue);
            if ($this->hasServerPasswordVariable && $newJoinPw !== $this->savedServerPasswordValue) {
                $vars->set($server, 'SRV_PW', $newJoinPw);
                $this->serverPasswordValue = $newJoinPw;
                $this->savedServerPasswordValue = $newJoinPw;
                // Mirror into INI when file present so in-game and launch agree
                $changes['ServerPassword'] = $newJoinPw;
                $nameNotes[] = 'SRV_PW updated';
            }

            $wroteIni = 0;
            if ($this->settingsFileExists) {
                $contents = $files->read($server, $this->settingsPath);
                $backupPath = $this->settingsPath.'.bak-'.gmdate('Ymd-His');
                $files->copy($server, $this->settingsPath, $backupPath);

                // Ensure identity INI keys are included even if only those changed
                foreach (['ServerMessageOfTheDay', 'AdminPassword', 'serverRegion', 'ServerPassword'] as $identityKey) {
                    if (! array_key_exists($identityKey, $this->formData) || array_key_exists($identityKey, $changes)) {
                        continue;
                    }
                    $old = $this->parsedTyped[$identityKey] ?? $this->parsedRaw[$identityKey] ?? null;
                    $new = $this->formData[$identityKey];
                    if ((string) $old !== (string) $new) {
                        $changes[$identityKey] = $new;
                    }
                }

                if ($changes !== []) {
                    $updated = $mapper->merge($contents, $changes, 'ServerSettings.ini');
                    $files->write($server, $this->settingsPath, $updated);
                    $wroteIni = count($changes);
                    $this->hydrateFromContents($updated);
                } else {
                    $this->formData['__server_name'] = $this->serverNameValue;
                    $this->formData['__server_password'] = $this->serverPasswordValue;
                    $this->fillFormState();
                }
                $this->backups = $files->listBackups($server, $this->settingsPath);
            }

            if ($wroteIni === 0 && $nameNotes === []) {
                Notification::make()->title('No changes to save')->info()->send();

                return;
            }

            $this->formData['__server_name'] = $this->serverNameValue;
            $this->formData['__server_password'] = $this->serverPasswordValue;
            $this->fillFormState();

            $parts = [];
            if ($wroteIni > 0) {
                $parts[] = "Wrote {$wroteIni} INI key(s)";
            }
            $parts = array_merge($parts, $nameNotes);
            Notification::make()
                ->title('Settings saved')
                ->body(implode('. ', $parts).'. Backup created where applicable. Restart the server to apply name/MOTD in the browser.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()
                ->title('Save failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function resetFormFromFile(): void
    {
        $this->formData = $this->buildFormData($this->parsedTyped);
        $this->fillFormState();
        Notification::make()->title('Form reset from file')->success()->send();
    }

    public function applyModePreset(string $mode): void
    {
        $mapper = new ConanIniMapper($this->schema());
        $this->formData = $mapper->applyModePreset($this->formData, $mode);
        // clear selector residual
        unset($this->formData['__mode_preset']);
        $this->fillFormState();
        Notification::make()
            ->title('Mode preset applied')
            ->body("Applied {$mode} keys in the form. Press Save to write the INI.")
            ->success()
            ->send();
    }

    private function hydrateFromContents(string $contents): void
    {
        $mapper = new ConanIniMapper($this->schema());
        $parsed = $mapper->parse($contents, 'ServerSettings.ini');
        $this->parsedTyped = $parsed['typed'];
        $this->parsedRaw = [];
        foreach ($parsed['sections'] as $pairs) {
            foreach ($pairs as $k => $v) {
                $this->parsedRaw[$k] = $v;
            }
        }
        $this->typedKeyCount = count($parsed['typed']);
        $this->unknownKeyCount = count($parsed['unknown']);
        $this->detectedMode = $mapper->detectMode($parsed['typed']);
        $this->formData = $this->buildFormData($parsed['typed']);
        foreach (['ServerMessageOfTheDay', 'AdminPassword', 'serverRegion', 'ServerPassword'] as $identityKey) {
            if (! array_key_exists($identityKey, $this->formData)) {
                $this->formData[$identityKey] = $this->parsedTyped[$identityKey]
                    ?? $this->parsedRaw[$identityKey]
                    ?? '';
            }
        }
        $this->formData['__server_name'] = $this->serverNameValue;
        $this->formData['__server_password'] = $this->serverPasswordValue;
        $this->settingsFileError = null;
    }

    /** @param  array<string, mixed>  $typed */
    private function buildFormData(array $typed): array
    {
        $data = $typed;
        // Ensure schema fields shown empty rather than missing
        $liveKeys = $this->liveIniKeys();
        foreach ($this->schema()->fieldsForFile('ServerSettings.ini') as $field) {
            $key = $field['ini_key'];
            if (! array_key_exists($key, $data)) {
                // Only seed defaults for keys not actually on the live file
                if (isset($liveKeys[$key])) {
                    continue;
                }
                $data[$key] = $field['default'] ?? null;
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function changedValues(): array
    {
        $changes = [];
        $index = $this->schema()->iniKeyIndex('ServerSettings.ini');
        foreach ($this->formData as $key => $new) {
            if (str_starts_with((string) $key, '__') || ! isset($index[$key])) {
                continue;
            }
            $field = $index[$key];
            if (($field['editable'] ?? true) === false || ($field['read_only'] ?? false) === true) {
                continue;
            }
            $old = $this->parsedTyped[$key] ?? null;
            if ($this->valuesEqual($field, $old, $new)) {
                continue;
            }
            if (($new === null || $new === '') && in_array($field['type'], ['integer', 'float'], true)) {
                continue;
            }
            $changes[$key] = $this->coerce($field, $new);
        }

        return $changes;
    }

    private function valuesEqual(array $field, mixed $a, mixed $b): bool
    {
        $type = $field['type'] ?? 'string';
        if ($type === 'boolean') {
            return $this->toBool($a) === $this->toBool($b);
        }
        if (in_array($type, ['integer', 'float'], true) && is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return (string) $a === (string) $b;
    }

    private function coerce(array $field, mixed $value): mixed
    {
        return match ($field['type'] ?? 'string') {
            'boolean' => $this->toBool($value),
            'integer' => is_numeric($value) ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            default => $value,
        };
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function makeInput(array $field): mixed
    {
        $key = $field['ini_key'];
        $name = $key;
        $label = $field['label'] ?? $key;
        $help = trim((string) ($field['help'] ?? ''));
        if ($help === '') {
            $help = $label;
        }
        // Keep INI key visible for search/support without cluttering the label
        $help = rtrim($help, '.').'. INI key: '.$key.'.';
        $disabled = ! $this->isSafeToEdit || (($field['editable'] ?? true) === false) || (($field['read_only'] ?? false) === true);
        $type = $field['type'] ?? 'string';

        if ($type === 'boolean' || ($field['ini_style'] ?? null) === 'TrueFalse') {
            return Toggle::make($name)
                ->label($label)
                ->helperText($help)
                ->disabled($disabled)
                ->inline(false);
        }

        if ($type === 'integer') {
            return TextInput::make($name)
                ->label($label)
                ->helperText($help)
                ->numeric()
                ->integer()
                ->disabled($disabled);
        }

        if ($type === 'float') {
            return TextInput::make($name)
                ->label($label)
                ->helperText($help)
                ->numeric()
                ->disabled($disabled);
        }

        if ($type === 'password') {
            return TextInput::make($name)
                ->label($label)
                ->helperText($help)
                ->password()
                ->revealable()
                ->disabled($disabled);
        }

        if ($type === 'text' || $name === 'ServerMessageOfTheDay') {
            return Textarea::make($name)
                ->label($label)
                ->helperText($help)
                ->rows(3)
                ->disabled($disabled);
        }

        return TextInput::make($name)
            ->label($label)
            ->helperText($help)
            ->disabled($disabled);
    }

    private function sendPowerAction(string $action): void
    {
        $server = Filament::getTenant();
        try {
            app(DaemonServerRepository::class)->setServer($server)->power($action);
            $this->awaitingPowerSettle = true;
            $this->expectedSafeToEdit = $action === 'stop';
            $this->powerPollTicks = 0;
            Notification::make()
                ->title('Power action sent')
                ->body(ucfirst($action).' requested. Status will refresh automatically…')
                ->success()
                ->send();
            $this->refreshPowerState($server);
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Power action failed')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Livewire poll target after start/stop until power state settles.
     */
    public function pollLiveState(): void
    {
        if (! $this->shouldPollLiveState()) {
            return;
        }

        $server = Filament::getTenant();
        $prevSafe = $this->isSafeToEdit;
        $prevLabel = $this->stateLabel;

        $this->refreshPowerState($server);

        if ($this->awaitingPowerSettle) {
            $this->powerPollTicks++;
            $settled = $this->expectedSafeToEdit === null
                || $this->isSafeToEdit === $this->expectedSafeToEdit;
            $label = strtolower($this->stateLabel);
            $terminal = in_array($label, ['offline', 'exited', 'running', 'online'], true);
            if ($settled || ($this->powerPollTicks >= 3 && $terminal && $prevLabel !== $this->stateLabel) || $this->powerPollTicks >= 40) {
                if ($settled || ($terminal && $this->powerPollTicks >= 3)) {
                    $this->awaitingPowerSettle = false;
                    $this->expectedSafeToEdit = null;
                    Notification::make()
                        ->title('Server state updated')
                        ->body('Power is now: '.$this->stateLabel.($this->isSafeToEdit ? ' — editing enabled.' : ' — stop the server to edit.'))
                        ->success()
                        ->send();
                }
            }
        }

        if ($prevSafe !== $this->isSafeToEdit || $prevLabel !== $this->stateLabel || $this->awaitingPowerSettle) {
            // Rebuild form so disabled state on inputs matches new power gate
            $this->cachedSchemas = [];
            $this->hasCachedForms = false;
            $this->fillFormState();
        }
    }

    public function shouldPollLiveState(): bool
    {
        return $this->awaitingPowerSettle;
    }

    private function refreshPowerState(mixed $server): void
    {
        $state = app(PelicanServerStateService::class);
        $state->clearStatusCache();
        $this->isSafeToEdit = $state->isSafeToEdit($server);
        $this->stateLabel = $state->getStateLabel($server);
        $this->stateMessage = $state->getStatusMessage($server);
    }


    /**
     * Runtime set of INI keys present on the live ServerSettings.ini (not schema snapshot).
     *
     * @return array<string, true>
     */
    private function liveIniKeys(): array
    {
        $keys = [];
        foreach (array_keys($this->parsedRaw) as $key) {
            $keys[(string) $key] = true;
        }
        foreach (array_keys($this->parsedTyped) as $key) {
            $keys[(string) $key] = true;
        }

        return $keys;
    }

    private function schema(): ConanSettingsSchema
    {
        return ConanSettingsSchema::load();
    }

    /**
     * Form state path so Filament binds $formData.
     */
    protected function getFormStatePath(): ?string
    {
        return 'formData';
    }
}
