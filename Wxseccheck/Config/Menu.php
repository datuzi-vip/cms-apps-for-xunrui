<?php

/**
 * 菜单配置
 */

return [
    'admin' => [
        'app' => [
            'left' => [
                'app-plugin' => [
                    'link' => [
                        'app-wxseccheck' => [
                            'name' => '微信内容安全',
                            'icon' => 'fa fa-shield',
                            'uri' => 'wxseccheck/config/index',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
