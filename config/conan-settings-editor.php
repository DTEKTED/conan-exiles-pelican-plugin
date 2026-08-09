<?php

return [
    /*
    | Relative path inside the server volume to the primary schema file.
    | Schema ships with the plugin; override only for development.
    */
    'schema_path' => null,

    /*
    | Preferred platform directory under ConanSandbox/Saved/Config/
    | auto        — detect from existing ServerSettings.ini + egg hints (default)
    | LinuxServer — force Linux Dedicated paths
    | WindowsServer — force Windows Dedicated paths
    |
    | Primary target for this project is Linux; Windows is supported for other users.
    */
    'config_platform' => 'auto',

    'config_platform_fallbacks' => [
        'LinuxServer',
        'WindowsServer',
    ],

    /*
    | Absolute path for install job queue (panel + worker). Null = plugin storage/jobs.
    */
    'jobs_path' => null,
];
