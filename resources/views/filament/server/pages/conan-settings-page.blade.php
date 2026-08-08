{{-- Poll after start/stop until power state settles. --}}
<div
    @if ($this->shouldPollLiveState())
        wire:poll.3s="pollLiveState"
    @endif
>
    <x-filament-panels::page
        id="form"
        :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
    >
        {{ $this->form }}
    </x-filament-panels::page>
</div>
