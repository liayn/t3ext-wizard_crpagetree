<?php

return [
    'dependencies' => ['core', 'backend'],
    'tags' => [
        'backend.contextmenu',
    ],
    'imports' => [
        '@typo3/wizard-crpagetree/' => 'EXT:wizard_crpagetree/Resources/Public/JavaScript/',
    ],
];
