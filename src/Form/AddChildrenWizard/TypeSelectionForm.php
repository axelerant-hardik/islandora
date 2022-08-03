<?php

namespace Drupal\islandora\Form\AddChildrenWizard;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use function Symfony\Component\DependencyInjection\Loader\Configurator\iterator;

class TypeSelectionForm extends FormBase {

  protected ?CacheableMetadata $cacheableMetadata = NULL;
  protected ?EntityTypeBundleInfoInterface $entityTypeBundleInfo;
  protected ?EntityTypeManagerInterface $entityTypeManager;
  protected ?EntityFieldManagerInterface $entityFieldManager;


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

  protected ?array $nodeBundleOptions = NULL;
  protected ?array $nodeBundleHasModelField = NULL;
  //protected ?array $nodeBundleHasMemberOfField = NULL;
  protected function getNodeBundleOptions() : array {
    if ($this->nodeBundleOptions === NULL) {
      $this->nodeBundleOptions = [];
      $this->nodeBundleHasModelField = [];
      //$this->nodeBundleHasMemberOfField = [];

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
        //$this->nodeBundleHasMemberOfField[$bundle] = array_key_exists(IslandoraUtils::MEMBER_OF_FIELD, $fields);
        $this->nodeBundleHasModelField[$bundle] = array_key_exists(IslandoraUtils::MODEL_FIELD, $fields);
      }
    }

    return $this->nodeBundleOptions;
  }

  protected function getModelOptions() : \Traversable {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadTree('islandora_models', 0, NULL, TRUE);
    foreach ($terms as $term) {
      yield $term->id() => $term->getName();
    }
  }

  protected function mapModelStates() : \Traversable {
    $this->getNodeBundleOptions();
    foreach (array_keys(array_filter($this->nodeBundleHasModelField)) as $bundle) {
      yield ['value' => $bundle];
    }
  }

  protected ?array $mediaBundleOptions = NULL;
  protected ?array $mediaBundleUsageField = NULL;
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

  protected function getMediaUseOptions() {
    /** @var TermInterface[] $terms */
    $terms =  $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadTree('islandora_media_use', 0, NULL, TRUE);

    foreach ($terms as $term) {
      yield $term->id() => $term->getName();
    }
  }
  protected function mapUseStates() {
    $this->getMediaBundleOptions();
    foreach (array_keys(array_filter($this->mediaBundleUsageField)) as $bundle) {
      yield ['value' => $bundle];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $this->cacheableMetadata = CacheableMetadata::createFromRenderArray($form);

    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#description' => $this->t('Each child created will have this content type.'),
      '#options' => $this->getNodeBundleOptions(),
      '#required' => TRUE,
    ];

    $model_states = iterator_to_array($this->mapModelStates());
    $form['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Model'),
      '#description' => $this->t('Each child will be tagged with this model.'),
      '#options' => iterator_to_array($this->getModelOptions()),
      '#states' => [
        'visible' => [
          ':input[name="bundle"]' => $model_states,
        ],
        'required' => [
          ':input[name="bundle"]' => $model_states,
        ],
      ]
    ];
    $form['media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Media Type'),
      '#description' => $this->t('Each media created will have this type.'),
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
    // TODO: Implement submitForm() method.
  }
}
