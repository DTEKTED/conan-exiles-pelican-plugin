<?php

namespace Dtektion\ConanSettingsEditor;

use Filament\Contracts\Plugin;
use Filament\Panel;

class ConanSettingsEditorPlugin implements Plugin
{
    public function getId(): string
    {
        return 'conan-settings-editor';
    }

    public function register(Panel $panel): void
    {
        // UI pages land in later phases; schema services are available immediately.
        $id = str($panel->getId())->title();
        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/{$id}/Pages"),
            "Dtektion\\ConanSettingsEditor\\Filament\\{$id}\\Pages"
        );
    }

    public function boot(Panel $panel): void
    {
        $defaults = require plugin_path($this->getId(), 'config/conan-settings-editor.php');
        config([
            'conan-settings-editor' => array_replace_recursive(
                $defaults,
                config('conan-settings-editor', [])
            ),
        ]);
    }
}
