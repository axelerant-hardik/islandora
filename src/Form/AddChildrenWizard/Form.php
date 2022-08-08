<?php

namespace Drupal\islandora\Form\AddChildrenWizard;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\ctools\Wizard\FormWizardBase;
use Drupal\islandora\IslandoraUtils;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Bulk children addition wizard base form.
 */
class Form extends FormWizardBase {

  /**
   * The Islandora Utils service.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected IslandoraUtils $utils;

  /**
   * The current node ID.
   *
   * @var string|mixed|null
   */
  protected string $nodeId;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $currentRoute;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructor.
   */
  public function __construct(
    SharedTempStoreFactory $tempstore,
    FormBuilderInterface $builder,
    ClassResolverInterface $class_resolver,
    EventDispatcherInterface $event_dispatcher,
    RouteMatchInterface $route_match,
    $tempstore_id,
    AccountProxyInterface $current_user,
    $machine_name = NULL,
    $step = NULL
  ) {
    parent::__construct($tempstore, $builder, $class_resolver, $event_dispatcher, $route_match, $tempstore_id,
      $machine_name, $step);

    $this->nodeId = $this->routeMatch->getParameter('node');
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function getParameters() : array {
    return array_merge(
      parent::getParameters(),
      [
        'tempstore_id' => 'islandora.upload_children',
        'current_user' => \Drupal::service('current_user'),
        'batch_processor' => \Drupal::service('islandora.upload_children.batch_processor'),
      ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineName() {
    return strtr("islandora_add_children_wizard__{userid}__{nodeid}", [
      '{userid}' => $this->currentUser->id(),
      '{nodeid}' => $this->nodeId,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations($cached_values) {
    $ops = [];

    $ops['child_type'] = [
      'title' => $this->t('Type of children'),
      'form' => TypeSelectionForm::class,
      'values' => [
        'node' => $this->nodeId,
      ],
    ];
    $ops['child_files'] = [
      'title' => $this->t('Files for children'),
      'form' => FileSelectionForm::class,
      'values' => [
        'node' => $this->nodeId,
      ],
    ];

    return $ops;
  }

  /**
   * {@inheritdoc}
   */
  public function getNextParameters($cached_values) {
    return parent::getNextParameters($cached_values) + ['node' => $this->nodeId];
  }

  /**
   * {@inheritdoc}
   */
  public function getPreviousParameters($cached_values) {
    return parent::getPreviousParameters($cached_values) + ['node' => $this->nodeId];
  }

}
