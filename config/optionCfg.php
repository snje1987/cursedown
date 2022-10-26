<?php
use Minifw\Console\Option;
use Org\Snje\Cursedown\App;

return [
    'oppositePrefix' => 'no-',
    'comment' => [
        'usage: cursedown [action] [options]',
        '下载curseforge整合包的工具',
    ],
    'global' => [
        'config' => [
            'alias' => ['c'],
            'comment' => '配置文件的路径，默认为用户主目录下的 `.cursedown/config.json`',
            'default' => '',
            'type' => Option::PARAM_PATH
        ],
        'api' => [
            'alias' => ['a'],
            'comment' => '使用的api, 可以是curseforge或modpacks',
            'default' => '',
            'paramValues' => App::API_LIST,
            'type' => Option::PARAM_ENUM,
        ],
    ],
    'actions' => [
        'config' => [
            'comment' => ['修改或查询程序的各项配置'],
            'options' => [
                'get' => [
                    'alias' => 'g',
                    'comment' => '查询配置项',
                    'default' => [],
                    'type' => Option::PARAM_STRING,
                ],
                'set' => [
                    'alias' => 's',
                    'comment' => '修改配置项',
                    'default' => [],
                    'type' => [Option::PARAM_STRING, Option::PARAM_STRING],
                ],
            ]
        ],
        'search' => [
            'comment' => ['搜索一个整合包'],
            'options' => [
                'name' => [
                    'alias' => 's',
                    'default' => null,
                    'comment' => '整合包名称',
                    'type' => Option::PARAM_STRING,
                ]
            ]
        ],
        'info' => [
            'comment' => ['获取整合包信息'],
            'options' => [
                'id' => [
                    'default' => '',
                    'alias' => 'id',
                    'comment' => '整合包ID',
                    'type' => Option::PARAM_STRING,
                ],
                'path' => [
                    'default' => '',
                    'alias' => 'p',
                    'comment' => '整合包保存路径',
                    'type' => Option::PARAM_DIR,
                ],
            ]
        ],
        'download' => [
            'comment' => ['下载一个整合包'],
            'options' => [
                'id' => [
                    'default' => '',
                    'alias' => 'id',
                    'comment' => '整合包ID, 如果是未发布的整合包，可以指定为0',
                    'type' => Option::PARAM_INT,
                ],
                'overrides' => [
                    'default' => '',
                    'alias' => 'o',
                    'comment' => '游戏文件保存目录',
                    'type' => Option::PARAM_STRING,
                ],
                'path' => [
                    'default' => null,
                    'alias' => 'p',
                    'comment' => '整合包保存路径',
                    'type' => Option::PARAM_PATH,
                ],
            ]
        ],
        'modify' => [
            'comment' => ['修改一个整合包'],
            'options' => [
                'path' => [
                    'default' => null,
                    'alias' => 'p',
                    'comment' => '整合包保存路径',
                    'type' => Option::PARAM_PATH,
                ],
                'rm' => [
                    'default' => null,
                    'alias' => 'r',
                    'comment' => '要移除的模组ID',
                    'type' => Option::PARAM_ARRAY,
                    'dataType' => Option::PARAM_INT,
                ],
            ]
        ],
        'help' => [
            'comment' => '显示本信息',
        ],
    ]
];
