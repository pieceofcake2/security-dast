<?php
return [
    'report' => ['language' => 'ja', 'dir' => 'tmp/security', 'project' => 'Acme'],
    'sast' => ['engine' => 'phpstan'],
    'dast' => ['failOn' => 'medium', 'minutes' => 3, 'ajaxSpider' => true, 'appEnv' => 'acceptance'],
];
