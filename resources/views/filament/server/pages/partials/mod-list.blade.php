{{-- Reactive list: reads Livewire $workshopIds / $metaById so reorders re-render. --}}
<div class="space-y-3" wire:key="mod-list-{{ implode('-', $this->workshopIds) }}-{{ count($this->workshopIds) }}">
    @if (count($this->workshopIds) === 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">No mods in the load order yet. Add a Workshop ID below.</p>
    @else
        @foreach ($this->workshopIds as $index => $id)
            @php
                $meta = $this->metaById[$id] ?? [];
                $title = $meta['title'] ?? ('Workshop '.$id);
                $desc = $meta['description'] ?? ($meta['error'] ?? '');
                $size = isset($meta['file_size']) ? number_format(((int) $meta['file_size']) / 1048576, 1).' MB' : '—';
                $url = $meta['url'] ?? "https://steamcommunity.com/sharedfiles/filedetails/?id={$id}";
                $legacy = is_string($title) && str_contains($title, 'Legacy');
            @endphp
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4" wire:key="mod-row-{{ $id }}-{{ $index }}">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="font-medium text-gray-950 dark:text-white">
                            {{ $index + 1 }}. {{ $title }}
                            @if (!empty($meta['banned']))
                                <span class="text-danger-600">[BANNED]</span>
                            @endif
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            ID <code class="text-xs">{{ $id }}</code> · {{ $size }}
                            @if ($legacy)
                                <span class="text-warning-600 dark:text-warning-400"> · ⚠ Legacy — verify Enhanced compatibility</span>
                            @endif
                        </div>
                        @if ($desc)
                            <p class="text-sm text-gray-600 dark:text-gray-300 line-clamp-3">{{ $desc }}</p>
                        @endif
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="text-sm text-primary-600 hover:underline">
                            Open on Steam Workshop
                        </a>
                    </div>
                    <div class="flex flex-wrap gap-2 shrink-0">
                        <x-filament::button size="sm" color="gray" wire:click="move({{ $index }}, -1)" :disabled="! $this->isSafeToEdit || $index === 0">
                            Up
                        </x-filament::button>
                        <x-filament::button size="sm" color="gray" wire:click="move({{ $index }}, 1)" :disabled="! $this->isSafeToEdit || $index >= count($this->workshopIds) - 1">
                            Down
                        </x-filament::button>
                        <x-filament::button size="sm" color="danger" wire:click="removeAt({{ $index }})" :disabled="! $this->isSafeToEdit" wire:confirm="Remove this mod from the load order? You still need to Save (or use auto-save).">
                            Remove
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endforeach
    @endif

    @if ($this->isDirty ?? false)
        <p class="text-sm text-warning-600 dark:text-warning-400 font-medium">
            Unsaved changes — click <strong>Save load order</strong> in the header (or changes auto-save when the server is stopped).
        </p>
    @endif
</div>
