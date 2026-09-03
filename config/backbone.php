<?php

return [
    'routers' => [
        [
            'id' => 'crs_didi',
            'nama' => 'POP DIDI (CRS310)',
            'host' => env('ROUTER_CRS_DIDI_HOST', '192.168.16.3'),
            'user' => env('ROUTER_CRS_DIDI_USER', 'ts'),
            'pass' => env('ROUTER_CRS_DIDI_PASS', '1'),
            'port' => (int) env('ROUTER_CRS_DIDI_PORT', 8484),
            'interfaces' => [
                [
                    'label' => 'UPLINK UTAMA',
                    'name'  => 'sfp-sfpplus1-UPLINK-UTAMA',
                ],
                [
                    'label' => 'UPLINK CADANGAN',
                    'name'  => 'sfp-sfpplus2-UPLINK-CADANGAN',
                ],
                [
                    'label' => 'BACKUP POP BABATAN',
                    'name'  => 'sfp-sfpplus3-BACKUP POP BABATAN',
                ],
                [
                    'label' => 'POP KATIBUNG',
                    'name'  => 'sfp3-POP KATIBUNG',
                ],
            ],
        ],
        [
            'id' => 'crs_babatan',
            'nama' => 'POP BABATAN (CRS309)',
            'host' => env('ROUTER_CRS_BABATAN_HOST', '192.168.16.4'),
            'user' => env('ROUTER_CRS_BABATAN_USER', 'ts'),
            'pass' => env('ROUTER_CRS_BABATAN_PASS', '1'),
            'port' => (int) env('ROUTER_CRS_BABATAN_PORT', 8585),
            'interfaces' => [
                [
                    'label' => 'UPLINK UTAMA',
                    'name'  => 'sfp-sfpplus1-UPLINK-UTAMA',
                ],
                [
                    'label' => 'UPLINK CADANGAN',
                    'name'  => 'sfp-sfpplus2-UPLINK-CADANGAN',
                ],
                [
                    'label' => 'BACKUP TARAHAN',
                    'name'  => 'sfp-sfpplus4-BACKUP-TARAHAN',
                ],
            ],
        ],
        [
            'id' => 'crs_tarahan',
            'nama' => 'POP TARAHAN (CRS309)',
            'host' => env('ROUTER_CRS_TARAHAN_HOST', '192.168.16.5'),
            'user' => env('ROUTER_CRS_TARAHAN_USER', 'ts'),
            'pass' => env('ROUTER_CRS_TARAHAN_PASS', '1'),
            'port' => (int) env('ROUTER_CRS_TARAHAN_PORT', 8686),
            'interfaces' => [
                [
                    'label' => 'UPLINK UTAMA SDM',
                    'name'  => 'sfp-sfpplus1-UPLINK-UTAMA-SDM',
                ],
                [
                    'label' => 'UPLINK CADANGAN',
                    'name'  => 'sfp-sfpplus2-UPLINK-CADANGAN',
                ],
            ],
        ],
        [
            'id' => 'crs_kantor',
            'nama' => 'CRS NEW SERVER (CRS326)',
            'host' => env('ROUTER_CRS_KANTOR_HOST', '192.168.16.6'),
            'user' => env('ROUTER_CRS_KANTOR_USER', 'ts'),
            'pass' => env('ROUTER_CRS_KANTOR_PASS', '1'),
            'port' => (int) env('ROUTER_CRS_KANTOR_PORT', 8383),
            'interfaces' => [
                [
                    'label' => 'MITRA SUKABANJAR',
                    'name'  => 'sfp-sfpplus3-POP-SUKABANJAR',
                ],
                [
                    'label' => 'MITRA TAMAN AGUNG',
                    'name'  => 'sfp-sfpplus4-POP-TAMAN-AGUNG',
                ],
                [
                    'label' => 'POP DIDI UTAMA',
                    'name'  => 'sfp-sfpplus10-LINK-UTAMA-POP-DIDI',
                ],
                [
                    'label' => 'POP DIDI BACKUP',
                    'name'  => 'sfp-sfpplus11-LINK-BACKUP-POP-DIDI',
                ],
                [
                    'label' => 'POP BABATAN',
                    'name'  => 'sfp-sfpplus12-LINK UTAMA-POP-BABATAN',
                ],
                [
                    'label' => 'POP TARAHAN',
                    'name'  => 'sfp-sfpplus17-LINK-UTAMA-POP-TARAHAN',
                ],
            ],
        ],
        [
            'id' => 'crs_sukabanjar',
            'nama' => 'POP SUKABANJAR (CRS305)',
            'host' => env('ROUTER_CRS_SUKABANJAR_HOST', '192.168.16.9'),
            'user' => env('ROUTER_CRS_SUKABANJAR_USER', 'ts'),
            'pass' => env('ROUTER_CRS_SUKABANJAR_PASS', '1'),
            'port' => (int) env('ROUTER_CRS_SUKABANJAR_PORT', 8787),
            'interfaces' => [
                [
                    'label' => 'PUSAT SDM',
                    'name'  => 'sfp-sfpplus1-PUSAT-SDM',
                ],
                [
                    'label' => 'BACKUP',
                    'name'  => 'sfp-sfpplus2-BACKUP',
                ],
                [
                    'label' => 'HSGQ 4 EPON',
                    'name'  => 'sfp-sfpplus3-HSGQ-4-EPON',
                ],
                [
                    'label' => 'PORT SFP 4',
                    'name'  => 'sfp-sfpplus4',
                ],
            ],
        ],
    ],
];
