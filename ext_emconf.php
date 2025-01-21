<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Web>Func, Wizards, Create page tree',
    'description' => 'Wizard that will create a page tree for you. Feed it with a space indented tree structure of the desired pages and the pages will be created for you.',
    'category' => 'module',
    'version' => '7.0.1',
    'state' => 'stable',
    'uploadfolder' => 0,
    'author' => 'Michiel Roos',
    'author_email' => 'michiel@michielroos.com',
    'author_company' => 'Michiel Roos',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.99-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
