<?php

return [
    /*
    | Relative path inside the server volume to the primary schema file.
    | Schema ships with the plugin; override only for development.
    */
    'schema_path' => null,

    /*
    | Preferred platform directory under ConanSandbox/Saved/Config/
    */
    'config_platform' => 'LinuxServer',

    'config_platform_fallbacks' => [
        'LinuxServer',
        'WindowsServer',
    ],

    /*
    | Absolute path for install job queue (panel + worker). Null = plugin storage/jobs.
    */
    'jobs_path' => null,
];
