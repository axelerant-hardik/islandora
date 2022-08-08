<?php

namespace Drupal\islandora\Form\AddChildrenWizard;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\islandora\IslandoraUtils;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Children addition wizard's first step.
 */
class TypeSelectionForm extends FormBase {

  /**
   * Cacheable metadata that is instantiated and used internally.
   *
   * @var \Drupal\Core\Cache\CacheableMetadata|null
   */
  protected ?CacheableMetadata $cacheableMetadata = NULL;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface|null
   */
  protected ?EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|null
   */
  protected ?EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|null
   */
  protected ?EntityFieldManagerInterface $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);

    $instance->entityTypeBundleInfo = $container->get('entity_type.bundle.info');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->entityFieldManager = $container->get('entity_field.manager');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'islandora_add_children_type_selection';
  }

  /**
   * Memoization for ::getNodeBundleOptions().
   *
   * @var array|null
   */
  protected ?array $nodeBundleOptions = NULL;

  /**
   * Indicate presence of model field on node bundles.
   *
   * Populated as a side effect of ::getNodeBundleOptions().
   *
   * @var array|null
   */
  protected ?array $nodeBundleHasModelField = NULL;

  /**
   * Helper; get the node bundle options available to the current user.
   *
   * @return array
   *   An associative array mapping node bundle machine names to their human-
   *   readable labels.
   */
  protected function getNodeBundleOptions() : array {
    if ($this->nodeBundleOptions === NULL) {
      $this->nodeBundleOptions = [];
      $this->nodeBundleHasModelField = [];

      $access_handler = $this->entityTypeManager->getAccessControlHandler('node');
      foreach ($this->entityTypeBundleInfo->getBundleInfo('node') as $bundle => $info) {
        $access = $access_handler->createAccess(
          $bundle,
          NULL,
          [],
          TRUE
        );
        $this->cacheableMetadata->addCacheableDependency($access);
        if (!$access->isAllowed()) {
          continue;
        }
        $this->nodeBundleOptions[$bundle] = $info['label'];
        $fields = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
        $this->nodeBundleHasModelField[$bundle] = array_key_exists(IslandoraUtils::MODEL_FIELD, $fields);
      }
    }

    return $this->nodeBundleOptions;
  }

  /**
   * Generates a mapping of taxonomy term IDs to their names.
   *
   * @return \Generator
   *   The mapping of taxonomy term IDs to their names.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getModelOptions() : \Generator {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadTree('islandora_models', 0, NULL, TRUE);
    foreach ($terms as $term) {
      yield $term->id() => $term->getName();
    }
  }

  /**
   * Helper; map node bundles supporting the "has model" field, for #states.
   *
   * @return \Generator
   *   Yields associative array mapping the string 'value' to the bundles which
   *   have the given field.
   */
  protected function mapModelStates() : \Generator {
    $this->getNodeBundleOptions();
    foreach (array_keys(array_filter($this->nodeBundleHasModelField)) as $bundle) {
      yield ['value' => $bundle];
    }
  }

  /**
   * Memoization for ::getMediaBundleOptions().
   *
   * @var array|null
   */
  protected ?array $mediaBundleOptions = NULL;

  /**
   * Indicate presence of usage field on media bundles.
   *
   * Populated as a side effect in ::getMediaBundleOptions().
   *
   * @var array|null
   */
  protected ?array $mediaBundleUsageField = NULL;

  /**
   * Helper; get options for media types.
   *
   * @return array
   *   An associative array mapping the machine name of the media type to its
   *   human-readable label.
   */
  protected function getMediaBundleOptions() : array {
    if ($this->mediaBundleOptions === NULL) {
      $this->mediaBundleOptions = [];
      $this->mediaBundleUsageField = [];

      $access_handler = $this->entityTypeManager->getAccessControlHandler('media');
      foreach ($this->entityTypeBundleInfo->getBundleInfo('media') as $bundle => $info) {
        $access = $access_handler->createAccess(
          $bundle,
          NULL,
          [],
          TRUE
        );
        $this->cacheableMetadata->addCacheableDependency($access);
        if (!$access->isAllowed()) {
          continue;
        }
        $this->mediaBundleOptions[$bundle] = $info['label'];
        $fields = $this->entityFieldManager->getFieldDefinitions('media', $bundle);
        $this->mediaBundleUsageField[$bundle] = array_key_exists(IslandoraUtils::MEDIA_USAGE_FIELD, $fields);
      }
    }

    return $this->mediaBundleOptions;
  }

  /**
   * Helper; list the terms of the "islandora_media_use" vocabulary.
   *
   * @return \Generator
   *   Generates term IDs as keys mapping to term names.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getMediaUseOptions() {
    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadTree('islandora_media_use', 0, NULL, TRUE);

    foreach ($terms as $term) {
      yield $term->id() => $term->getName();
    }
  }

  /**
   * Helper; map media types supporting the usage field for use with #states.
   *
   * @return \Generator
   *   Yields associative array mapping the string 'value' to the bundles which
   *   have the given field.
   */
  protected function mapUseStates(): \Generator {
    $this->getMediaBundleOptions();
    foreach (array_keys(array_filter($this->mediaBundleUsageField)) as $bundle) {
      yield ['value' => $bundle];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->cacheableMetadata = CacheableMetadata::createFromRenderArray($form)
      ->addCacheContexts([
        'url',
        'url.query_args',
      ]);
    $cached_values = $form_state->getTemporaryValue('wizard');

    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#description' => $this->t('Each child created will have this content type.'),
      '#empty_value' => '',
      '#default_value' => $cached_values['bundle'] ?? '',
      '#options' => $this->getNodeBundleOptions(),
      '#required' => TRUE,
    ];

    $model_states = iterator_to_array($this->mapModelStates());
    $form['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#description' => $this->t('Each child will be tagged with this model.'),
      '#options' => iterator_to_array($this->getModelOptions()),
      '#empty_value' => '',
      '#default_value' => $cached_values['model'] ?? '',
      '#states' => [
        'visible' => [
          ':input[name="bundle"]' => $model_states,
        ],
        'required' => [
          ':input[name="bundle"]' => $model_states,
        ],
      ],
    ];
    $form['media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Media Type'),
      '#description' => $this->t('Each media created will have this type.'),
      '#empty_value' => '',
      '#default_value' => $cached_values['media_type'] ?? '',
      '#options' => $this->getMediaBundleOptions(),
      '#required' => TRUE,
    ];
    $use_states = iterator_to_array($this->mapUseStates());
    $form['use'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Usage'),
      '#description' => $this->t('Defined by <a target="_blank" href=":url">Portland Common Data Model: Use Extension</a>. "Original File" will trigger creation of derivatives.', [
        ':url' => 'https://pcdm.org/2015/05/12/use',
      ]),
      '#options' => iterator_to_array($this->getMediaUseOptions()),
      '#default_value' => $cached_values['use'] ?? [],
      '#states' => [
        'visible' => [
          ':input[name="media_type"]' => $use_states,
        ],
        'required' => [
          ':input[name="media_type"]' => $use_states,
        ],
      ],
    ];

    $this->cacheableMetadata->applyTo($form);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $keys = [
      'bundle',
      'model',
      'media_type',
      'use',
    ];
    $cached_values = $form_state->getTemporaryValue('wizard');
    foreach ($keys as $key) {
      $cached_values[$key] = $form_state->getValue($key);
    }
    $form_state->setTemporaryValue('wizard', $cached_values);
  }

}
