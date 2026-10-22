<?php

return [
    'component_layout' => 'components.layouts.app',

    'temporary_file_upload' => [
        'middleware' => ['auth', 'throttle:60,1'],
        'disk' => 'local',
        'rules' => ['required', 'file', 'max:10240'],
        'preview_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],
];
