<?php

declare(strict_types=1);

namespace MediaOrderFixer;

use Laminas\EventManager\EventInterface;
use Laminas\Mvc\MvcEvent;
use MediaOrderFixer\Controller\Admin\IndexController;
use Omeka\Module\AbstractModule;

/**
 * Module bootstrap class for MediaOrderFixer.
 */
class Module extends AbstractModule {

  /**
   * Return module configuration.
   */
  public function getConfig(): array {
    return include __DIR__ . '/config/module.config.php';
  }

  /**
   * Return autoloader configuration.
   */
  public function getAutoloaderConfig(): array {
    return [
      'Laminas\\Loader\\StandardAutoloader' => [
        'namespaces' => [
          __NAMESPACE__ => __DIR__ . '/src',
        ],
      ],
    ];
  }

  /**
   * Configure ACL permissions.
   */
  public function onBootstrap(EventInterface $event): void {
    if (!$event instanceof MvcEvent) {
      return;
    }
    $services = $event->getApplication()->getServiceManager();
    $acl = $services->get('Omeka\\Acl');
    $acl->allow(['global_admin', 'site_admin'], [IndexController::class]);
  }

}
