<?php

namespace Drupal\islandora\Form\AddChildrenWizard;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\islandora\IslandoraUtils;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access checker.
 *
 * The _wizard/_form route enhancers do not really allow for access checking
 * things, so let's roll it separately for now.
 */
class Access implements ContainerInjectionInterface {

  /**
   * The Islandora utils service.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected IslandoraUtils $utils;

  /**
   * Constructor.
   */
  public function __construct(IslandoraUtils $utils) {
    $this->utils = $utils;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) : self {
    return new static(
      $container->get('islandora.utils')
    );
  }

  /**
   * Check if the user can create any "Islandora" nodes and media.
   *
   * @param \Drupal\Core\Routing\RouteMatch $route_match
   *   The current routing match.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Whether we can or cannot show the "thing".
   */
  public function checkAccess(RouteMatch $route_match) : AccessResultInterface {
    $can_create_media = $this->utils->canCreateIslandoraEntity('media', 'media_type');
    $can_create_node = $this->utils->canCreateIslandoraEntity('node', 'node_type');

    if ($can_create_media && $can_create_node) {
      return AccessResult::allowed();
    }

    return AccessResult::forbidden();
  }

}
