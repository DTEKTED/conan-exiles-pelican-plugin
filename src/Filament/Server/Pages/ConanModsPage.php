<?php

namespace Dtektion\ConanSettingsEditor\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Repositories\Daemon\DaemonServerRepository;
use Dtektion\ConanSettingsEditor\Services\ConanModListService;
use Dtektion\ConanSettingsEditor\Services\ConanServerDetector;
use Dtektion\ConanSettingsEditor\Services\PelicanServerStateService;
use Dtektion\ConanSettingsEditor\Services\ConanWorkshopInstallService;
use Dtektion\ConanSettingsEditor\Services\SteamWorkshopService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;
use Throwable;

class ConanModsPage extends Page
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $slug = 'conan-mods';

    protected static ?int $navigationSort = 31;

    protected string $view = 'conan-settings-editor::filament.server.pages.conan-mods-page';

    /** When true, add/remove/move/bulk immediately write ServerModList if offline. */
    private const AUTO_SAVE = true;

    #[Locked]
    public bool $isSafeToEdit = false;

    #[Locked]
    public string $stateLabel = 'Unknown';

    #[Locked]
    public string $stateMessage = '';

    #[Locked]
    public string $serverModListRaw = '';

    #[Locked]
    public string $serverModListMode = 'empty';

    #[Locked]
    public string $settingsPath = '';

    /** @var list<string> */
    public array $workshopIds = [];

    /** @var list<string> last saved order (for dirty detection) */
    #[Locked]
    public array $savedWorkshopIds = [];

    /** @var array<string, array<string, mixed>> */
    public array $metaById = [];

    /** @var list<string> */
    #[Locked]
    public array $paksOnDisk = [];

    /** @var list<array{line: string, enabled: bool, pak_name: ?string}> */
    #[Locked]
    public array $modlistEntries = [];

    public string $addIdInput = '';

    public string $bulkImport = '';

    public bool $isDirty = false;

    public ?string $installJobId = null;

    public string $installStatus = '';

    public string $installMessage = '';

    public bool $installInProgress = false;

    /** When true, poll until power state matches expectedSafeToEdit (or timeout). */
    public bool $awaitingPowerSettle = false;

    public ?bool $expectedSafeToEdit = null;

    public int $powerPollTicks = 0;

    /** Previous install status for terminal-transition detection. */
    public string $lastNotifiedInstallStatus = '';

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
        return 'Conan Mods';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Conan Exiles';
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->getFormSchema())
            ->statePath(null);
    }

    public function mount(
        PelicanServerStateService $stateService,
        ConanModListService $modList,
        SteamWorkshopService $workshop,
    ): void {
        $server = Filament::getTenant();
        $this->refreshPowerState($stateService, $server);
        $this->reloadFromServer($modList, $workshop);
        $this->refreshInstallStatus(app(ConanWorkshopInstallService::class));
    }

    public function reloadFromServer(?ConanModListService $modList = null, ?SteamWorkshopService $workshop = null): void
    {
        $modList ??= app(ConanModListService::class);
        $workshop ??= app(SteamWorkshopService::class);
        $server = Filament::getTenant();

        try {
            $info = $modList->inspect($server);
            $this->workshopIds = array_values($info['workshop_ids']);
            $this->savedWorkshopIds = $this->workshopIds;
            $this->serverModListRaw = $info['server_mod_list_raw'];
            $this->serverModListMode = $info['server_mod_list_mode'];
            $this->settingsPath = $info['settings_path'];
            $this->paksOnDisk = $info['paks_on_disk'];
            $this->modlistEntries = $info['modlist_entries'];
            $this->metaById = $workshop->getDetails($this->workshopIds);
            $this->isDirty = false;
            $this->bulkImport = implode("\n", $this->workshopIds);
            $this->uncacheForm();
            $this->fillFormState();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Could not load mod list')->body($e->getMessage())->danger()->send();
        }
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make('Server status')
                ->description($this->stateMessage)
                ->schema([
                    TextInput::make('stateLabel')->label('Power')->disabled()->dehydrated(false),
                    TextInput::make('serverModListMode')->label('ServerModList mode')->disabled()->dehydrated(false),
                    TextInput::make('serverModListRaw')->label('ServerModList on disk')->disabled()->dehydrated(false),
                    TextInput::make('settingsPath')->label('Settings path')->disabled()->dehydrated(false),
                    TextInput::make('paksSummary')
                        ->label('Paks on disk (Mods/)')
                        ->formatStateUsing(fn () => $this->paksOnDisk === []
                            ? '(none — use Download Workshop paks to install into Mods/)'
                            : implode(', ', $this->paksOnDisk))
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->columns(2),

            Section::make('Load order (Workshop IDs)')
                ->description('Top = loads first. Saved to ServerModList as comma-separated IDs. Clients need the same mods in the same order. Stop the server before saving.')
                ->schema([
                    ViewField::make('mod_list')
                        ->label('')
                        ->view('conan-settings-editor::filament.server.pages.partials.mod-list')
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),

            Section::make('Add mod')
                ->schema([
                    TextInput::make('addIdInput')
                        ->label('Workshop ID or URL')
                        ->placeholder('880454836 or https://steamcommunity.com/sharedfiles/filedetails/?id=880454836')
                        ->disabled(fn (): bool => ! $this->isSafeToEdit)
                        ->live(onBlur: true),
                ])
                ->footerActions([
                    Action::make('addMod')
                        ->label('Add to list')
                        ->icon('heroicon-o-plus')
                        ->disabled(fn (): bool => ! $this->isSafeToEdit)
                        ->action(fn () => $this->addFromInput()),
                ]),

            Section::make('Bulk import / export')
                ->collapsed()
                ->schema([
                    Textarea::make('bulkImport')
                        ->label('Workshop IDs (one per line or comma-separated). Apply replaces the full list.')
                        ->rows(5)
                        ->disabled(fn (): bool => ! $this->isSafeToEdit),
                ])
                ->footerActions([
                    Action::make('applyBulk')
                        ->label('Replace list from bulk import')
                        ->color('warning')
                        ->disabled(fn (): bool => ! $this->isSafeToEdit)
                        ->requiresConfirmation()
                        ->action(fn () => $this->applyBulkImport()),
                ]),


            Section::make('Workshop download / install')
                ->description('Queues a host worker job: SteamCMD download → stage .pak into Mods/ → atomic modlist.txt. Server should be stopped first. Progress and completion refresh automatically every few seconds.')
                ->schema([
                    TextInput::make('installStatus')
                        ->label('Install job status')
                        ->formatStateUsing(fn () => $this->installStatus !== '' ? $this->installStatus : 'idle')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('installJobId')
                        ->label('Job id')
                        ->formatStateUsing(fn () => $this->installJobId ?? '—')
                        ->disabled()
                        ->dehydrated(false),
                    Textarea::make('installMessage')
                        ->label('Progress')
                        ->formatStateUsing(fn () => $this->installMessage !== '' ? $this->installMessage : 'No install job yet.')
                        ->rows(3)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ])
                ->footerActions([
                    Action::make('downloadInstall')
                        ->label('Download Workshop paks')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('warning')
                        ->disabled(fn (): bool => ! $this->canStartInstall())
                        ->requiresConfirmation()
                        ->modalHeading('Download and install Workshop paks?')
                        ->modalDescription('SteamCMD will download each Workshop ID in the current load order into this server volume, copy .pak files into Mods/, write modlist.txt, and set ServerModList=modlist.txt. Stop the server first. Existing modlist.txt is backed up.')
                        ->action(fn () => $this->startInstall()),
                    Action::make('refreshInstall')
                        ->label('Refresh job status')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->action(function (): void {
                            $this->refreshInstallStatus(app(ConanWorkshopInstallService::class));
                            if (in_array($this->installStatus, ['succeeded', 'failed', 'done'], true)) {
                                $this->reloadFromServer();
                            }
                            $this->uncacheForm();
                            $this->fillFormState();
                        }),
                ])
                ->columns(2),

            Section::make('modlist.txt (pak-level)')
                ->description('Pak-level load list written by Download Workshop paks. ServerModList becomes modlist.txt after install; Workshop IDs stay in the plugin manifest for this UI.')
                ->collapsed()
                ->schema([
                    Textarea::make('modlistPreview')
                        ->label('Current modlist.txt')
                        ->formatStateUsing(fn () => $this->modlistEntries === []
                            ? '(file missing or empty)'
                            : collect($this->modlistEntries)->map(fn ($e) => $e['line'])->implode("\n"))
                        ->rows(6)
                        ->disabled()
                        ->dehydrated(false),
                ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save load order')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->keyBindings(['mod+s'])
                ->disabled(fn (): bool => ! $this->canSave())
                ->authorize(fn (): bool => (bool) user()?->can(SubuserPermission::FileUpdate, Filament::getTenant()))
                ->action(fn () => $this->saveList()),
            Action::make('refreshMeta')
                ->label('Refresh Workshop info')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $this->metaById = app(SteamWorkshopService::class)->getDetails($this->workshopIds, useCache: false);
                    $this->uncacheForm();
                    $this->fillFormState();
                    Notification::make()->title('Workshop info refreshed')->success()->send();
                }),
            Action::make('reload')
                ->label('Reload from disk')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->action(function (): void {
                    $this->refreshPowerState(app(PelicanServerStateService::class), Filament::getTenant());
                    $this->reloadFromServer();
                    Notification::make()->title('Reloaded from server files')->success()->send();
                }),
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
        return $this->isSafeToEdit;
    }

    public function move(int $index, int $delta): void
    {
        $target = $index + $delta;
        if ($target < 0 || $target >= count($this->workshopIds)) {
            return;
        }
        $ids = $this->workshopIds;
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
        $this->workshopIds = array_values($ids);
        $this->afterListMutation('Reordered');
    }

    public function removeAt(int $index): void
    {
        if (! isset($this->workshopIds[$index])) {
            return;
        }
        $removed = $this->workshopIds[$index];
        $ids = $this->workshopIds;
        unset($ids[$index]);
        $this->workshopIds = array_values($ids);
        $title = $this->metaById[$removed]['title'] ?? $removed;
        $this->afterListMutation('Removed: '.$title);
    }

    public function addFromInput(): void
    {
        // Prefer Livewire property; also accept form state if present
        $raw = trim($this->addIdInput);
        if ($raw === '') {
            try {
                $state = $this->form->getState();
                $raw = trim((string) ($state['addIdInput'] ?? ''));
            } catch (Throwable) {
            }
        }

        $ids = app(ConanModListService::class)->normalizeIdList([$raw]);
        if ($ids === []) {
            Notification::make()->title('Invalid Workshop ID')->body('Enter a numeric ID or full Workshop URL.')->warning()->send();

            return;
        }
        $id = $ids[0];
        if (in_array($id, $this->workshopIds, true)) {
            Notification::make()->title('Already in list')->info()->send();

            return;
        }
        $this->workshopIds[] = $id;
        $meta = app(SteamWorkshopService::class)->getDetails([$id]);
        $this->metaById = array_replace($this->metaById, $meta);
        $this->addIdInput = '';
        $title = $meta[$id]['title'] ?? $id;
        $this->afterListMutation('Added: '.$title);
    }

    public function applyBulkImport(): void
    {
        $raw = $this->bulkImport;
        if (trim($raw) === '') {
            try {
                $raw = (string) ($this->form->getState()['bulkImport'] ?? '');
            } catch (Throwable) {
            }
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $parts = preg_split('/[\n,]+/', $raw) ?: [];
        $ids = app(ConanModListService::class)->normalizeIdList($parts);
        $this->workshopIds = $ids;
        $this->metaById = app(SteamWorkshopService::class)->getDetails($ids);
        $this->afterListMutation('Bulk list set ('.count($ids).' mods)');
    }

    public function saveList(): void
    {
        $server = Filament::getTenant();
        $state = app(PelicanServerStateService::class);
        $modList = app(ConanModListService::class);

        if (! (bool) user()?->can(SubuserPermission::FileUpdate, $server)) {
            Notification::make()->title('Permission denied')->danger()->send();

            return;
        }
        $this->refreshPowerState($state, $server);
        if (! $state->isSafeToEdit($server)) {
            Notification::make()
                ->title('Server must be stopped')
                ->body('Stop the server, then save the load order. Refreshing the page reloads from disk.')
                ->warning()
                ->send();

            return;
        }

        try {
            $modList->saveWorkshopOrder($server, $this->workshopIds);
            $this->reloadFromServer();
            Notification::make()
                ->title('Load order saved to disk')
                ->body('ServerModList='.(implode(',', $this->workshopIds) ?: '(empty)').'. Start the server after paks exist under Mods/.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Save failed')->body($e->getMessage())->danger()->send();
        }
    }

    private function afterListMutation(string $summary): void
    {
        $this->isDirty = $this->workshopIds !== $this->savedWorkshopIds;
        $this->bulkImport = implode("\n", $this->workshopIds);
        $this->uncacheForm();
        $this->fillFormState();

        if (self::AUTO_SAVE && $this->isSafeToEdit) {
            $this->saveList();

            return;
        }

        Notification::make()
            ->title($summary)
            ->body($this->isSafeToEdit
                ? 'Click Save load order to write ServerModList (or stop the server to enable auto-save).'
                : 'Server is running — stop it, then Save load order. Refresh will discard unsaved list edits.')
            ->info()
            ->send();
    }


    public function canStartInstall(): bool
    {
        return $this->isSafeToEdit
            && ! $this->installInProgress
            && $this->workshopIds !== []
            && (bool) user()?->can(SubuserPermission::FileUpdate, Filament::getTenant());
    }

    public function startInstall(): void
    {
        $server = Filament::getTenant();
        $state = app(PelicanServerStateService::class);
        $installer = app(ConanWorkshopInstallService::class);

        if (! (bool) user()?->can(SubuserPermission::FileUpdate, $server)) {
            Notification::make()->title('Permission denied')->danger()->send();

            return;
        }
        $this->refreshPowerState($state, $server);
        if (! $state->isSafeToEdit($server)) {
            Notification::make()
                ->title('Server must be stopped')
                ->body('Stop the server before downloading/installing Workshop paks.')
                ->warning()
                ->send();

            return;
        }
        if ($this->workshopIds === []) {
            Notification::make()->title('Load order is empty')->warning()->send();

            return;
        }

        try {
            // Persist current ID order first so disk matches UI
            app(ConanModListService::class)->saveWorkshopOrder($server, $this->workshopIds);
            $queued = $installer->enqueue($server, $this->workshopIds);
            $this->installJobId = $queued['job_id'];
            $this->installStatus = 'pending';
            $this->installInProgress = true;
            $this->installMessage = 'Job queued. Waiting for conan-mod-worker…'
                .($installer->workerSeemsAlive() ? '' : ' (worker heartbeat not seen yet — ensure the worker container is running)');
            $this->uncacheForm();
            $this->fillFormState();
            Notification::make()
                ->title('Workshop install queued')
                ->body('Job '.$queued['job_id'].'. Progress updates automatically; large packs can take many minutes.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Could not queue install')->body($e->getMessage())->danger()->send();
        }
    }

    public function refreshInstallStatus(?ConanWorkshopInstallService $installer = null): void
    {
        $installer ??= app(ConanWorkshopInstallService::class);
        $server = Filament::getTenant();
        $uuid = (string) data_get($server, 'uuid');
        $job = null;
        if ($this->installJobId) {
            $job = $installer->getJob($this->installJobId);
        }
        if ($job === null && $uuid !== '') {
            $job = $installer->latestJobForServer($uuid);
        }
        if ($job === null) {
            $this->installJobId = null;
            $this->installStatus = '';
            $this->installMessage = $installer->workerSeemsAlive()
                ? 'Worker is online. No install jobs yet.'
                : 'No install jobs yet. Worker heartbeat not detected — start conan-mod-worker if downloads fail to leave pending.';
            $this->installInProgress = false;

            return;
        }

        $this->installJobId = (string) ($job['job_id'] ?? $this->installJobId);
        $status = (string) ($job['status'] ?? $job['bucket'] ?? 'unknown');
        if ($status === 'done') {
            $status = 'succeeded';
        }
        $this->installStatus = $status;
        $this->installInProgress = in_array($status, ['pending', 'running'], true);

        $progress = is_array($job['progress'] ?? null) ? $job['progress'] : [];
        $result = is_array($job['result'] ?? null) ? $job['result'] : [];
        $msg = (string) ($progress['message'] ?? $result['message'] ?? $job['error'] ?? $status);
        $completed = $progress['completed'] ?? $result['completed'] ?? null;
        $total = $progress['total'] ?? $result['total'] ?? null;
        if ($completed !== null && $total !== null) {
            $msg .= " ({$completed}/{$total})";
        }
        if (! empty($progress['current_id'])) {
            $msg .= ' · current '.$progress['current_id'];
        }
        if (! empty($result['paks']) && is_array($result['paks'])) {
            $msg .= ' · paks: '.implode(', ', array_slice($result['paks'], 0, 8));
            if (count($result['paks']) > 8) {
                $msg .= '…';
            }
        }
        $this->installMessage = $msg;
    }

    private function uncacheForm(): void
    {
        $this->hasCachedForms = false;
        $this->cachedSchemas = [];
    }

    private function fillFormState(): void
    {
        try {
            $this->form->fill([
                'stateLabel' => $this->stateLabel,
                'serverModListMode' => $this->serverModListMode,
                'serverModListRaw' => $this->serverModListRaw,
                'settingsPath' => $this->settingsPath,
                'addIdInput' => $this->addIdInput,
                'bulkImport' => $this->bulkImport,
                'installStatus' => $this->installStatus,
                'installJobId' => $this->installJobId,
                'installMessage' => $this->installMessage,
            ]);
        } catch (Throwable) {
            // Form may not be ready during early lifecycle.
        }
    }

    private function refreshPowerState(PelicanServerStateService $state, mixed $server): void
    {
        $fresh = app(PelicanServerStateService::class);
        $fresh->clearStatusCache();
        $this->isSafeToEdit = $fresh->isSafeToEdit($server);
        $this->stateLabel = $fresh->getStateLabel($server);
        $this->stateMessage = $fresh->getStatusMessage($server);
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
            $this->refreshPowerState(app(PelicanServerStateService::class), $server);
            $this->uncacheForm();
            $this->fillFormState();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Power action failed')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Livewire poll target: refresh power + install job, reload form when settled.
     */
    public function pollLiveState(): void
    {
        if (! $this->shouldPollLiveState()) {
            return;
        }

        $server = Filament::getTenant();
        $prevInstall = $this->installStatus;
        $prevMessage = $this->installMessage;
        $prevSafe = $this->isSafeToEdit;
        $prevLabel = $this->stateLabel;

        $this->refreshPowerState(app(PelicanServerStateService::class), $server);

        if ($this->installInProgress || in_array($this->installStatus, ['pending', 'running'], true) || $this->installJobId) {
            $this->refreshInstallStatus(app(ConanWorkshopInstallService::class));
        }

        $installJustFinished = $prevInstall !== $this->installStatus
            && in_array($this->installStatus, ['succeeded', 'failed', 'done'], true)
            && $this->lastNotifiedInstallStatus !== $this->installStatus;

        if ($installJustFinished) {
            $this->lastNotifiedInstallStatus = $this->installStatus;
            $this->reloadFromServer();
            if ($this->installStatus === 'succeeded') {
                Notification::make()
                    ->title('Workshop install complete')
                    ->body($this->installMessage !== '' ? $this->installMessage : 'Paks and modlist.txt updated.')
                    ->success()
                    ->send();
            } elseif ($this->installStatus === 'failed') {
                Notification::make()
                    ->title('Workshop install failed')
                    ->body($this->installMessage !== '' ? $this->installMessage : 'See job log for details.')
                    ->danger()
                    ->send();
            }
        }

        if ($this->awaitingPowerSettle) {
            $this->powerPollTicks++;
            $settled = $this->expectedSafeToEdit === null
                || $this->isSafeToEdit === $this->expectedSafeToEdit;
            // Also settle on stable terminal labels after a few ticks even if mismatch
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

        $changed = $prevSafe !== $this->isSafeToEdit
            || $prevLabel !== $this->stateLabel
            || $prevInstall !== $this->installStatus
            || $prevMessage !== $this->installMessage
            || $installJustFinished;

        // Always refresh form while install is active so progress text stays live
        if ($changed || $this->installInProgress || $this->awaitingPowerSettle) {
            $this->uncacheForm();
            $this->fillFormState();
        }
    }

    public function shouldPollLiveState(): bool
    {
        return $this->installInProgress
            || $this->awaitingPowerSettle
            || in_array($this->installStatus, ['pending', 'running'], true);
    }

    protected function getFormStatePath(): ?string
    {
        return null;
    }
}
