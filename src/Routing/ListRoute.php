<?php

declare(strict_types=1);

namespace Drupal\websource_preorder\Routing;

use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route dynamique de la page « Toutes les précommandes » (chemin configurable).
 */
class ListRoute {

  public function routes(): RouteCollection {
    $routes = new RouteCollection();
    $slug = trim((string) \Drupal::config('websource_preorder.settings')->get('list_slug'), '/');
    if ($slug === '' || !preg_match('#^[a-z0-9][a-z0-9\-_/]*$#i', $slug)) {
      $slug = 'precommandes';
    }
    $routes->add('websource_preorder.list', new Route('/' . $slug, [
      '_controller' => '\Drupal\websource_preorder\Controller\PublicController::listPage',
      '_title_callback' => '\Drupal\websource_preorder\Controller\PublicController::listTitle',
    ], ['_permission' => 'view websource_preorder pages']));
    return $routes;
  }

}
