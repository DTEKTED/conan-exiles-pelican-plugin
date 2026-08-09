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

    /**
     * When true and the server is stopped, saving the load order automatically queues
     * SteamCMD Workshop downloads for any IDs that do not yet have a .pak on disk.
     * Download never requires the game process to be running (and must not run while it is).
     */
    private const AUTO_DOWNLOAD_WHEN_OFFLINE = true;

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

    public string $configPlatform = 'LinuxServer';

    public string $configPlatformSource = '';

    public string $osHint = 'linux';

    public bool $discoveredFromDisk = false;

    public string $discoveryNote = '';

    public bool $discoveryNotified = false;

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

    /** @var list<string> paks on disk not in current load order */
    #[Locked]
    public array $orphanPaks = [];

    /** @var list<array{line: string, enabled: bool, pak_name: ?string}> */
    #[Locked]
    public array $modlistEntries = [];

    /** @var list<array<string, mixed>> mount/extract status per workshop id + orphans */
    #[Locked]
    public array $mountStatus = [];

    /** @var list<string> */
    #[Locked]
    public array $mountedPaksFromLog = [];

    /** Cached modlist.txt preview text for form fill */
    public string $modlistPreview = '';

    public string $addIdInput = '';

    public string $bulkImport = '';

    public bool $isDirty = false;

    public ?string $installJobId = null;

    public string $installStatus = '';

    public string $installMessage = '';

    public bool $installInProgress = false;

    /** Number of pending+running install jobs for this server. */
    public int $installQueueDepth = 0;

    public int $installPendingCount = 0;

    public string $installQueueSummary = 'Queue empty';

    /** When true, poll until power state matches expectedSafeToEdit (or timeout). */
    public bool $awaitingPowerSettle = false;

    public ?bool $expectedSafeToEdit = null;

    public int $powerPollTicks = 0;

    /** Previous install status for terminal-transition detection. */
    public string $lastNotifiedInstallStatus = '';

    /** Last job id we notified as finished (avoids re-toast when queue advances). */
    public string $lastNotifiedInstallJobId = '';

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
        if ($this->discoveredFromDisk && $this->discoveryNote !== '' && ! $this->discoveryNotified) {
            $this->discoveryNotified = true;
            Notification::make()
                ->title('Existing mods discovered on disk')
                ->body($this->discoveryNote.' With the server stopped, click Save load order once to persist the panel manifest — other ServerSettings are left alone.')
                ->info()
                ->send();
        }
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
            $this->configPlatform = (string) ($info['config_platform'] ?? 'LinuxServer');
            $this->configPlatformSource = (string) ($info['config_platform_source'] ?? '');
            $this->osHint = (string) ($info['os_hint'] ?? 'linux');
            $this->discoveredFromDisk = (bool) ($info['discovered_from_disk'] ?? false);
            $this->discoveryNote = (string) ($info['discovery_note'] ?? '');
            $this->paksOnDisk = $info['paks_on_disk'];
            $this->orphanPaks = array_values($info['orphan_paks'] ?? []);
            $this->modlistEntries = $info['modlist_entries'];
            $this->mountStatus = array_values($info['mount_status'] ?? []);
            $this->mountedPaksFromLog = array_values($info['mounted_paks_from_log'] ?? []);
            $this->modlistPreview = (string) ($info['modlist_preview']
                ?? $modList->formatModlistPreview($this->modlistEntries, $this->mountStatus));
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
                    TextInput::make('configPlatform')
                        ->label('Config platform')
                        ->formatStateUsing(fn () => $this->configPlatform
                            .($this->configPlatformSource !== '' ? ' ('.$this->configPlatformSource.')' : ''))
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('discoveryNote')
                        ->label('Disk discovery')
                        ->formatStateUsing(fn () => $this->discoveryNote !== '' ? $this->discoveryNote : '—')
                        ->visible(fn (): bool => $this->discoveredFromDisk || $this->discoveryNote !== '')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    TextInput::make('paksSummary')
                        ->label('Paks on disk (Mods/)')
                        ->formatStateUsing(function (): string {
                            if ($this->paksOnDisk === []) {
                                return '(none yet — stop server, add Workshop IDs, Save to auto-download, or use Download missing paks now)';
                            }
                            $managed = array_values(array_diff($this->paksOnDisk, $this->orphanPaks));
                            $parts = [];
                            if ($managed !== []) {
                                $parts[] = 'in load order: '.implode(', ', $managed);
                            }
                            if ($this->orphanPaks !== []) {
                                $parts[] = 'ORPHANS (not loaded): '.implode(', ', $this->orphanPaks);
                            }

                            return implode(' | ', $parts) ?: implode(', ', $this->paksOnDisk);
                        })
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->columns(2),

            Section::make('Load order (Workshop IDs)')
                ->description('Top = loads first. With the server stopped, Add & save / Save writes the load order and queues Workshop .pak downloads for missing IDs. Removing a mod also deletes its .pak from Mods/ on save. Orphan paks are not loaded.')
                ->schema([
                    ViewField::make('mod_list')
                        ->label('')
                        ->view('conan-settings-editor::filament.server.pages.partials.mod-list')
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),


            Section::make('Orphan paks (on disk, not in load order)')
                ->description('These .pak files are under Mods/ but not in the load order (server will not load them). New saves auto-delete paks removed from the list; use this button for leftovers from older plugin versions.')
                ->visible(fn (): bool => $this->orphanPaks !== [])
                ->schema([
                    Textarea::make('orphanPaksList')
                        ->label('Orphan files')
                        ->formatStateUsing(fn () => implode("\n", $this->orphanPaks))
                        ->rows(4)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ])
                ->footerActions([
                    Action::make('purgeOrphans')
                        ->label('Delete orphan paks from disk')
                        ->color('danger')
                        ->icon('heroicon-o-trash')
                        ->disabled(fn (): bool => ! $this->isSafeToEdit || $this->orphanPaks === [])
                        ->requiresConfirmation()
                        ->modalHeading('Delete orphan .pak files?')
                        ->modalDescription('Permanently deletes .pak files under Mods/ that are not in the current load order. ExtractedMods caches are left alone.')
                        ->action(fn () => $this->purgeOrphanPaks()),
                ]),

            Section::make('Add mod')
                ->description(fn (): string => $this->isSafeToEdit
                    ? 'Server is offline: Add & save writes the load order immediately and queues any missing .pak downloads.'
                    : 'Server is running: Add only updates the in-memory list. Stop the server, then Save load order.')
                ->schema([
                    TextInput::make('addIdInput')
                        ->label('Workshop ID or URL')
                        ->placeholder('880454836 or https://steamcommunity.com/sharedfiles/filedetails/?id=880454836')
                        ->disabled(fn (): bool => ! $this->isSafeToEdit)
                        ->live(onBlur: true),
                ])
                ->footerActions([
                    Action::make('addMod')
                        ->label(fn (): string => $this->isSafeToEdit ? 'Add & save' : 'Add to list')
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
                        ->label(fn (): string => $this->isSafeToEdit ? 'Replace list & save' : 'Replace list (then Save)')
                        ->color('warning')
                        ->disabled(fn (): bool => ! $this->isSafeToEdit)
                        ->requiresConfirmation()
                        ->action(fn () => $this->applyBulkImport()),
                ]),


            Section::make('Workshop download / install')
                ->description('Default: with the server stopped, Save auto-queues SteamCMD downloads for missing paks. Additional mods enqueue behind any active job (FIFO). Worker stages .pak into Mods/ and writes modlist.txt. Never download while the server is running.')
                ->schema([
                    TextInput::make('installStatus')
                        ->label('Install job status')
                        ->formatStateUsing(fn () => $this->installStatus !== '' ? $this->installStatus : 'idle')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('installJobId')
                        ->label('Active / preferred job')
                        ->formatStateUsing(fn () => $this->installJobId ?? '—')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('installQueueDepth')
                        ->label('Queue depth')
                        ->formatStateUsing(fn () => (string) $this->installQueueDepth)
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('installPendingCount')
                        ->label('Pending jobs')
                        ->formatStateUsing(fn () => (string) $this->installPendingCount)
                        ->disabled()
                        ->dehydrated(false),
                    Textarea::make('installMessage')
                        ->label('Progress / queue')
                        ->formatStateUsing(fn () => $this->formatInstallProgressDisplay())
                        ->rows(4)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ])
                ->footerActions([
                    Action::make('downloadInstall')
                        ->label(fn (): string => $this->installInProgress || $this->installQueueDepth > 0
                            ? 'Queue missing paks'
                            : 'Download missing paks now')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('primary')
                        ->disabled(fn (): bool => ! $this->canStartInstall())
                        ->requiresConfirmation()
                        ->modalHeading(fn (): string => $this->installInProgress || $this->installQueueDepth > 0
                            ? 'Enqueue Workshop downloads?'
                            : 'Download Workshop paks while server is stopped?')
                        ->modalDescription('SteamCMD runs against the volume only — the game server must stay stopped. New IDs are merged into a pending job or queued behind the active job. Start the server only after the queue is empty and jobs succeed.')
                        ->action(fn () => $this->startInstall(auto: false)),
                    Action::make('refreshInstall')
                        ->label('Refresh job status')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->action(function (): void {
                            $this->refreshInstallStatus(app(ConanWorkshopInstallService::class));
                            if (in_array($this->installStatus, ['succeeded', 'failed', 'done'], true) && $this->installQueueDepth === 0) {
                                $this->reloadFromServer();
                            }
                            $this->uncacheForm();
                            $this->fillFormState();
                        }),
                ])
                ->columns(2),

            Section::make('modlist.txt (pak-level)')
                ->description('Pak-level load list (modlist.txt). Updates when you add/save mods. Pending Workshop IDs appear as comments until paks are downloaded. Status tags come from last boot log + ExtractedMods.')
                ->collapsed(false)
                ->schema([
                    Textarea::make('modlistPreview')
                        ->label('Current modlist.txt')
                        ->rows(8)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    Textarea::make('mountStatusSummary')
                        ->label('Mount status (last boot + server extracts)')
                        ->formatStateUsing(fn () => $this->formatMountStatusSummary())
                        ->rows(6)
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(fn (): string => $this->isDirty ? 'Save load order' : 'Saved')
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
                ->disabled(fn (): bool => $this->installInProgress || $this->installQueueDepth > 0)
                ->authorize(fn (): bool => (bool) user()?->can(SubuserPermission::ControlStart, Filament::getTenant()))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => ($this->installInProgress || $this->installQueueDepth > 0)
                    ? 'A Workshop install is still queued or running. Wait for it to finish before starting the game server.'
                    : 'Start the Conan server process?')
                ->action(function (): void {
                    if ($this->installInProgress || $this->installQueueDepth > 0) {
                        Notification::make()
                            ->title('Cannot start while Workshop install is active')
                            ->body('Wait for the download queue to finish (queue depth '.$this->installQueueDepth.').')
                            ->warning()
                            ->send();

                        return;
                    }
                    $this->sendPowerAction('start');
                }),
        ];
    }

    public function canSave(): bool
    {
        // R2: only enable Save when there is something to write (dirty + offline).
        return $this->isSafeToEdit && $this->isDirty;
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
        $this->afterListMutation('Reordered', 'reorder');
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
        $this->afterListMutation('Removed: '.$title, 'remove');
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
        $this->afterListMutation('Added: '.$title, 'add');
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
        if ($ids === [] && $this->savedWorkshopIds !== []) {
            Notification::make()
                ->title('Empty bulk replace blocked')
                ->body('Refusing to replace the load order with an empty list while mods are configured. Clear mods one-by-one or type a confirmed empty import after removing all IDs deliberately.')
                ->danger()
                ->send();

            return;
        }
        $this->workshopIds = $ids;
        $this->metaById = app(SteamWorkshopService::class)->getDetails($ids);
        $this->afterListMutation('Bulk list set ('.count($ids).' mods)', 'bulk');
    }

    /**
     * @param  string  $source  Context for notifications: add|remove|reorder|bulk|manual
     */
    public function saveList(string $source = 'manual'): void
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
            $beforePaks = $modList->listPaks($server);
            $modList->saveWorkshopOrder($server, $this->workshopIds);
            $this->reloadFromServer();
            $removed = array_values(array_diff($beforePaks, $this->paksOnDisk));
            $body = 'Load order written to modlist.txt.';
            if ($removed !== []) {
                $body .= ' Deleted from disk: '.implode(', ', $removed).'.';
            }
            if ($this->orphanPaks !== []) {
                $body .= ' Remaining orphans: '.implode(', ', $this->orphanPaks).'.';
            }

            $title = match ($source) {
                'add' => 'Added and saved (server offline)',
                'remove' => 'Removed and saved (server offline)',
                'reorder' => 'Reordered and saved (server offline)',
                'bulk' => 'Bulk list saved (server offline)',
                default => 'Load order saved',
            };
            Notification::make()
                ->title($title)
                ->body($body)
                ->success()
                ->send();

            // Default: download missing paks while offline (before next start).
            // Queues even if another job is already running (R1).
            if (self::AUTO_DOWNLOAD_WHEN_OFFLINE && $this->isSafeToEdit && $this->idsNeedingPakDownload() !== []) {
                $this->startInstall(auto: true);
            }
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Save failed')->body($e->getMessage())->danger()->send();
        }
    }

    private function afterListMutation(string $summary, string $source = 'manual'): void
    {
        if ($this->installInProgress || $this->installQueueDepth > 0) {
            Notification::make()
                ->title('Workshop install in progress')
                ->body('Wait for the download queue to finish before changing the load order (prevents races with the worker).')
                ->warning()
                ->send();
            $this->reloadFromServer();

            return;
        }

        $this->isDirty = $this->workshopIds !== $this->savedWorkshopIds;
        $this->bulkImport = implode("\n", $this->workshopIds);
        $this->uncacheForm();
        $this->fillFormState();

        if (self::AUTO_SAVE && $this->isSafeToEdit) {
            // R2: auto-save path — toast titles make outcome explicit.
            $this->saveList($source);

            return;
        }

        // Online / no auto-save: list changed in memory only.
        $body = $this->isSafeToEdit
            ? 'Click Save load order to write ServerModList.'
            : 'Server is running — stop it, then click Save load order. Refresh will discard unsaved list edits.';
        if ($source === 'add') {
            $body = $this->isSafeToEdit
                ? 'Added — click Save load order to write to disk.'
                : 'Added to list only — stop the server, then Save load order (Add & save when offline).';
        }

        Notification::make()
            ->title($summary)
            ->body($body)
            ->info()
            ->send();
    }


    public function canStartInstall(): bool
    {
        // R1: allow queueing while a job is already in progress.
        return $this->isSafeToEdit
            && $this->workshopIds !== []
            && (bool) user()?->can(SubuserPermission::FileUpdate, Filament::getTenant());
    }

    public function startInstall(bool $auto = false): void
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
                ->body('Workshop paks are downloaded while the game is offline. Stop the server, then Save (auto-download) or use Download missing paks now.')
                ->warning()
                ->send();

            return;
        }
        if ($this->workshopIds === []) {
            Notification::make()->title('Load order is empty')->warning()->send();

            return;
        }

        // Always preserve full load order on disk. Auto path only *downloads* missing IDs;
        // manual path re-downloads the full list (repair) but still keeps order.
        $loadOrder = $this->workshopIds;
        $downloadIds = $auto ? $this->idsNeedingPakDownload() : $this->workshopIds;
        if ($downloadIds === []) {
            if (! $auto) {
                Notification::make()
                    ->title('Nothing to download')
                    ->body('Every Workshop ID in the load order already has a .pak on disk.')
                    ->info()
                    ->send();
            }

            return;
        }

        try {
            // Persist current ID order first so disk matches UI (and survives worker merge).
            app(ConanModListService::class)->saveWorkshopOrder($server, $loadOrder);
            $queued = $installer->enqueue($server, $loadOrder, $downloadIds);
            $this->refreshInstallStatus($installer);

            $downloadCount = count($queued['download_ids'] ?? $downloadIds);
            $orderCount = count($queued['load_order_ids'] ?? $queued['workshop_ids'] ?? $loadOrder);
            $depth = (int) ($queued['queue_depth'] ?? $this->installQueueDepth);
            $merged = (bool) ($queued['merged'] ?? false);
            $already = (bool) ($queued['already_queued'] ?? false);

            $title = match (true) {
                $already => 'Already in download queue',
                $merged => 'Merged into pending download job',
                $depth > 1 => 'Workshop install enqueued',
                $auto => 'Downloading paks before start',
                default => 'Workshop install queued',
            };

            $bodyParts = [
                'Job '.($queued['job_id'] ?? '?'),
                $downloadCount.' download(s)',
                $orderCount.' mod(s) in load order (preserved)',
                'queue depth '.$depth,
            ];
            if ($auto) {
                array_unshift($bodyParts, 'Server is stopped — SteamCMD will fetch missing paks without replacing other mods.');
            }
            if (! empty($queued['running_job_id']) && ($queued['job_id'] ?? '') !== $queued['running_job_id']) {
                $bodyParts[] = 'waiting behind '.$queued['running_job_id'];
            }
            $bodyParts[] = 'Progress updates automatically; start the server only after the queue is empty and jobs succeed.';

            Notification::make()
                ->title($title)
                ->body(implode(' ', array_map(static fn ($p) => rtrim((string) $p, '.').'.', $bodyParts)))
                ->success()
                ->send();
            $this->uncacheForm();
            $this->fillFormState();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Could not queue install')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Workshop IDs in the load order that do not yet have a mapped .pak on disk.
     *
     * @return list<string>
     */
    public function idsNeedingPakDownload(): array
    {
        $need = [];
        $statusById = $this->mountStatusByWorkshopId();
        $paks = array_fill_keys($this->paksOnDisk, true);

        foreach ($this->workshopIds as $id) {
            $row = $statusById[$id] ?? null;
            $pak = is_array($row) ? ($row['pak_name'] ?? null) : null;
            $onDisk = is_string($pak) && $pak !== '' && isset($paks[$pak]);
            $status = is_array($row) ? (string) ($row['status'] ?? '') : 'missing_pak';
            if (! $onDisk || $status === 'missing_pak') {
                $need[] = $id;
            }
        }

        return $need;
    }

    public function refreshInstallStatus(?ConanWorkshopInstallService $installer = null): void
    {
        $installer ??= app(ConanWorkshopInstallService::class);
        $server = Filament::getTenant();
        $uuid = (string) data_get($server, 'uuid');

        $summary = $uuid !== ''
            ? $installer->queueSummaryForServer($uuid)
            : [
                'queue_depth' => 0,
                'pending_count' => 0,
                'running_count' => 0,
                'running_job_id' => null,
                'pending_job_ids' => [],
                'active_ids' => [],
                'summary' => 'Queue empty',
            ];
        $this->installQueueDepth = (int) ($summary['queue_depth'] ?? 0);
        $this->installPendingCount = (int) ($summary['pending_count'] ?? 0);
        $this->installQueueSummary = (string) ($summary['summary'] ?? 'Queue empty');

        $job = null;
        if ($this->installJobId) {
            $tracked = $installer->getJob($this->installJobId);
            // Keep tracking a known active job; otherwise fall through to preferred.
            if (is_array($tracked) && in_array($tracked['bucket'] ?? '', ['pending', 'running'], true)) {
                $job = $tracked;
            }
        }
        if ($job === null && $uuid !== '') {
            $job = $installer->preferredJobForServer($uuid);
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
        // Normalize bucket names to status
        if (in_array($status, ['pending', 'running'], true) === false && in_array($job['bucket'] ?? '', ['pending', 'running'], true)) {
            $status = (string) $job['bucket'];
        }
        $this->installStatus = $status;
        $this->installInProgress = in_array($status, ['pending', 'running'], true) || $this->installQueueDepth > 0;

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
        $idsInJob = $job['workshop_ids'] ?? [];
        if (is_array($idsInJob) && $idsInJob !== []) {
            $msg .= ' · job ids: '.implode(', ', array_slice(array_map('strval', $idsInJob), 0, 6));
            if (count($idsInJob) > 6) {
                $msg .= '…';
            }
        }
        $this->installMessage = $msg;
    }

    private function formatInstallProgressDisplay(): string
    {
        $parts = [];
        if ($this->installQueueSummary !== '') {
            $parts[] = $this->installQueueSummary;
        }
        if ($this->installMessage !== '') {
            $parts[] = $this->installMessage;
        }
        if ($parts === []) {
            return 'No install job yet. With the server stopped, Save auto-queues missing-pak downloads. Further adds enqueue behind the active job.';
        }

        return implode("\n", $parts);
    }


    public function purgeOrphanPaks(): void
    {
        $server = Filament::getTenant();
        if (! (bool) user()?->can(SubuserPermission::FileUpdate, $server)) {
            Notification::make()->title('Permission denied')->danger()->send();

            return;
        }
        $this->refreshPowerState(app(PelicanServerStateService::class), $server);
        if (! $this->isSafeToEdit) {
            Notification::make()->title('Server must be stopped')->warning()->send();

            return;
        }
        try {
            $deleted = app(ConanModListService::class)->purgeOrphanPaks($server);
            $this->reloadFromServer();
            Notification::make()
                ->title('Orphan paks deleted')
                ->body($deleted === [] ? 'Nothing to delete.' : ('Deleted: '.implode(', ', $deleted)))
                ->success()
                ->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Delete failed')->body($e->getMessage())->danger()->send();
        }
    }


    private function formatPaksSummary(): string
    {
        if ($this->paksOnDisk === []) {
            return '(none — use Download Workshop paks to install into Mods/)';
        }
        $managed = array_values(array_diff($this->paksOnDisk, $this->orphanPaks));
        $parts = [];
        if ($managed !== []) {
            $parts[] = 'in load order: '.implode(', ', $managed);
        }
        if ($this->orphanPaks !== []) {
            $parts[] = 'ORPHANS (not loaded): '.implode(', ', $this->orphanPaks);
        }

        return implode(' | ', $parts) ?: implode(', ', $this->paksOnDisk);
    }

    private function formatMountStatusSummary(): string
    {
        if ($this->mountStatus === []) {
            return '(no mods in load order and no orphan paks)';
        }
        $lines = [];
        foreach ($this->mountStatus as $row) {
            $wid = $row['workshop_id'] ?? '—';
            $pak = $row['pak_name'] ?? '(no pak)';
            $status = $row['status'] ?? 'unknown';
            $label = $row['label'] ?? '';
            $lines[] = sprintf('[%s] id=%s  %s  — %s', $status, $wid, $pak, $label);
        }
        if ($this->mountedPaksFromLog !== []) {
            $lines[] = '';
            $lines[] = 'Log Mounting mod pak (last boot): '.implode(', ', $this->mountedPaksFromLog);
        }

        return implode("\n", $lines);
    }

    /** @return array<string, array<string, mixed>> keyed by workshop id */
    public function mountStatusByWorkshopId(): array
    {
        $out = [];
        foreach ($this->mountStatus as $row) {
            $wid = $row['workshop_id'] ?? null;
            if ($wid !== null && $wid !== '') {
                $out[(string) $wid] = $row;
            }
        }

        return $out;
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
                'configPlatform' => $this->configPlatform
                    .($this->configPlatformSource !== '' ? ' ('.$this->configPlatformSource.')' : ''),
                'discoveryNote' => $this->discoveryNote !== '' ? $this->discoveryNote : '—',
                'paksSummary' => $this->formatPaksSummary(),
                'orphanPaksList' => implode("\n", $this->orphanPaks),
                'modlistPreview' => $this->modlistPreview !== ''
                    ? $this->modlistPreview
                    : '(file missing or empty — stop server, add Workshop IDs, Save to auto-download paks)',
                'mountStatusSummary' => $this->formatMountStatusSummary(),
                'addIdInput' => $this->addIdInput,
                'bulkImport' => $this->bulkImport,
                'installStatus' => $this->installStatus !== '' ? $this->installStatus : 'idle',
                'installJobId' => $this->installJobId ?? '—',
                'installQueueDepth' => (string) $this->installQueueDepth,
                'installPendingCount' => (string) $this->installPendingCount,
                'installMessage' => $this->formatInstallProgressDisplay(),
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
        $prevJobId = $this->installJobId;
        $prevDepth = $this->installQueueDepth;

        $this->refreshPowerState(app(PelicanServerStateService::class), $server);

        if ($this->installInProgress || $this->installQueueDepth > 0 || in_array($this->installStatus, ['pending', 'running'], true) || $this->installJobId) {
            $this->refreshInstallStatus(app(ConanWorkshopInstallService::class));
        }

        // Detect a tracked job reaching terminal state (including when queue advances to next job).
        $jobFinished = $prevJobId
            && $prevJobId !== ''
            && $this->lastNotifiedInstallJobId !== $prevJobId
            && (
                // Same job now terminal
                ($this->installJobId === $prevJobId && in_array($this->installStatus, ['succeeded', 'failed', 'done'], true) && $prevInstall !== $this->installStatus)
                // Or job rotated away (finished and next preferred job is different / none)
                || ($this->installJobId !== $prevJobId && in_array($prevInstall, ['pending', 'running'], true))
            );

        if ($jobFinished) {
            $this->lastNotifiedInstallJobId = (string) $prevJobId;
            $finishedJob = app(ConanWorkshopInstallService::class)->getJob((string) $prevJobId);
            $finishedStatus = is_array($finishedJob)
                ? (string) ($finishedJob['status'] ?? $finishedJob['bucket'] ?? 'unknown')
                : $this->installStatus;
            if ($finishedStatus === 'done') {
                $finishedStatus = 'succeeded';
            }

            $this->reloadFromServer();
            $this->refreshInstallStatus(app(ConanWorkshopInstallService::class));

            if ($finishedStatus === 'succeeded' || $finishedStatus === 'done') {
                $body = $this->installQueueDepth > 0
                    ? 'Job '.$prevJobId.' complete. '.$this->installQueueDepth.' job(s) still in queue — downloads continue automatically.'
                    : ($this->installMessage !== '' ? $this->installMessage : 'Paks and modlist.txt updated. Queue empty.');
                Notification::make()
                    ->title($this->installQueueDepth > 0 ? 'Workshop job complete (queue continues)' : 'Workshop install complete')
                    ->body($body)
                    ->success()
                    ->send();
            } elseif ($finishedStatus === 'failed') {
                Notification::make()
                    ->title('Workshop install failed')
                    ->body(is_array($finishedJob) ? (string) ($finishedJob['error'] ?? $this->installMessage) : $this->installMessage)
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
            || $prevDepth !== $this->installQueueDepth
            || $prevJobId !== $this->installJobId
            || $jobFinished;

        // Always refresh form while install is active so progress text stays live
        if ($changed || $this->installInProgress || $this->awaitingPowerSettle || $this->installQueueDepth > 0) {
            $this->uncacheForm();
            $this->fillFormState();
        }
    }

    public function shouldPollLiveState(): bool
    {
        return $this->installInProgress
            || $this->installQueueDepth > 0
            || $this->awaitingPowerSettle
            || in_array($this->installStatus, ['pending', 'running'], true);
    }

    protected function getFormStatePath(): ?string
    {
        return null;
    }
}
