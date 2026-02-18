<?php

/**
 * @file
 * MediaOrderFixer module configuration.
 */

declare(strict_types=1);

namespace MediaOrderFixer;

use Laminas\Router\Http\Literal;
use MediaOrderFixer\Controller\Admin\IndexController;

return [
  'navigation' => [
    'AdminModule' => [
      [
        'label' => 'Media Order Fixer',
        'route' => 'admin/media-order-fixer',
        'resource' => IndexController::class,
        'privilege' => 'index',
      ],
    ],
  ],
  'controllers' => [
    'factories' => [
      IndexController::class => function ($services) {
        return new IndexController(
          $services->get('Omeka\\Connection'),
          $services->get('Omeka\\EntityManager')
        );
      },
    ],
  ],
  'router' => [
    'routes' => [
      'admin' => [
        'child_routes' => [
          'media-order-fixer' => [
            'type' => Literal::class,
            'options' => [
              'route' => '/media-order-fixer',
              'defaults' => [
                '__NAMESPACE__' => 'MediaOrderFixer\\Controller\\Admin',
                'controller' => IndexController::class,
                'action' => 'index',
              ],
            ],
            'may_terminate' => TRUE,
            'child_routes' => [
              'apply' => [
                'type' => Literal::class,
                'options' => [
                  'route' => '/apply',
                  'defaults' => [
                    'action' => 'apply',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ],
  ],
  'view_manager' => [
    'template_path_stack' => [
      __DIR__ . '/../view',
    ],
  ],
];
