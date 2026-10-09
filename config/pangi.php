<?php

return ['enabled' => (bool) env('PANGI_ENABLED', false), 'base_url' => env('PANGI_BASE_URL', 'https://pangi.poltekkes-manado.ac.id'), 'token' => env('PANGI_TOKEN'), 'timeout' => 20, 'retry_limit' => (int) env('PANGI_RETRY_LIMIT', 3), 'auto_prepare' => (bool) env('PANGI_AUTO_PREPARE', true)];
