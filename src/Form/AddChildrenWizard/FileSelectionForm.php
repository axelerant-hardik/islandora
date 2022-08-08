<?php

namespace Drupal\islandora\Form\AddChildrenWizard;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\islandora\IslandoraUtils;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Children addition wizard's second step.
 */
class FileSelectionForm extends FormBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|null
   */
  protected ?EntityTypeManagerInterface $entityTypeManager;

  /**
   * The widget plugin manager service.
   *
   * @var \Drupal\Core\Field\WidgetPluginManager|null
   */
  protected ?PluginManagerInterface $widgetPluginManager;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface|null
   */
  protected ?EntityFieldManagerInterface $entityFieldManager;

  /**
   * The database connection serivce.
   *
   * @var \Drupal\Core\Database\Connection|null
   */
  protected ?Connection $database;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface|null
   */
  protected ?AccountProxyInterface $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = parent::create($container);

    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->widgetPluginManager = $container->get('plugin.manager.field.widget');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->database = $container->get('database');
    $instance->currentUser = $container->get('current_user');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'islandora_add_children_wizard_file_selection';
  }

  /**
   * Helper; get the media type, based off discovering from form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\media\MediaTypeInterface
   *   The target media type.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getMediaType(FormStateInterface $form_state): MediaTypeInterface {
    return $this->doGetMediaType($form_state->getTemporaryValue('wizard'));
  }

  /**
   * Helper; get media type, given our required values.
   *
   * @param array $values
   *   An associative array which must contain at least:
   *   - media_type: The machine name of the media type to load.
   *
   * @return \Drupal\media\MediaTypeInterface
   *   The loaded media type.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function doGetMediaType(array $values): MediaTypeInterface {
    /** @var \Drupal\media\MediaTypeInterface $media_type */
    return $this->entityTypeManager->getStorage('media_type')->load($values['media_type']);
  }

  /**
   * Helper; get field instance, based off discovering from form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The field definition.
   */
  protected function getField(FormStateInterface $form_state): FieldDefinitionInterface {
    $cached_values = $form_state->getTemporaryValue('wizard');

    $field = $this->doGetField($cached_values);
    $field->getFieldStorageDefinition()->set('cardinality', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    return $field;
  }

  /**
   * Helper; get field instance, given our required values.
   *
   * @param array $values
   *   See ::doGetMediaType() for which values are required.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The target field.
   */
  protected function doGetField(array $values): FieldDefinitionInterface {
    $media_type = $this->doGetMediaType($values);
    $media_source = $media_type->getSource();
    $source_field = $media_source->getSourceFieldDefinition($media_type);

    $fields = $this->entityFieldManager->getFieldDefinitions('media', $media_type->id());

    return $fields[$source_field->getFieldStorageDefinition()->getName()] ??
      $media_source->createSourceField();
  }

  /**
   * Helper; get widget for the field, based on discovering from form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Field\WidgetInterface
   *   The widget.
   */
  protected function getWidget(FormStateInterface $form_state): WidgetInterface {
    return $this->doGetWidget($this->getField($form_state));
  }

  /**
   * Helper; get the base widget for the given field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field for which get obtain the widget.
   *
   * @return \Drupal\Core\Field\WidgetInterface
   *   The widget.
   */
  protected function doGetWidget(FieldDefinitionInterface $field): WidgetInterface {
    return $this->widgetPluginManager->getInstance([
      'field_definition' => $field,
      'form_mode' => 'default',
      'prepare' => TRUE,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Using the media type selected in the previous step, grab the
    // media bundle's "source" field, and create a multi-file upload widget
    // for it, with the same kind of constraints.
    $field = $this->getField($form_state);
    $items = FieldItemList::createInstance($field, $field->getName(), $this->getMediaType($form_state)->getTypedData());

    $form['#tree'] = TRUE;
    $form['#parents'] = [];
    $widget = $this->getWidget($form_state);
    $form['files'] = $widget->form(
      $items,
      $form,
      $form_state
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $cached_values = $form_state->getTemporaryValue('wizard');

    $widget = $this->getWidget($form_state);
    $builder = (new BatchBuilder())
      ->setTitle($this->t('Creating children...'))
      ->setInitMessage($this->t('Initializing...'))
      ->setFinishCallback([$this, 'batchProcessFinished']);
    $values = $form_state->getValue($this->doGetField($cached_values)->getName());
    $massaged_values = $widget->massageFormValues($values, $form, $form_state);
    foreach ($massaged_values as $delta => $file) {
      $builder->addOperation(
        [$this, 'batchProcess'],
        [$delta, $file, $cached_values]
      );
    }
    batch_set($builder->toArray());
    $form_state->setRedirectUrl(Url::fromUri("internal:/node/{$cached_values['node']}/members"));
  }

  /**
   * Implements callback_batch_operation() for our child addition batch.
   */
  public function batchProcess($delta, $info, array $values, &$context) {
    $transaction = $this->database->startTransaction();

    try {
      $taxonomy_term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

      /** @var \Drupal\file\FileInterface $file */
      $file = $this->entityTypeManager->getStorage('file')->load($info['target_id']);
      $file->setPermanent();
      if ($file->save() !== SAVED_UPDATED) {
        throw new \Exception("Failed to update file '{$file->id()}' to be permanent.");
      }

      $node_storage = $this->entityTypeManager->getStorage('node');
      $parent = $node_storage->load($values['node']);

      // Create a node (with the filename?) (and also belonging to the target
      // node).
      /** @var \Drupal\node\NodeInterface $node */
      $node = $node_storage->create([
        'type' => $values['bundle'],
        'title' => $file->getFilename(),
        IslandoraUtils::MEMBER_OF_FIELD => $parent,
        'uid' => $this->currentUser->id(),
        'status' => NodeInterface::PUBLISHED,
        IslandoraUtils::MODEL_FIELD => ($values['model'] ?
          $taxonomy_term_storage->load($values['model']) :
          NULL),
      ]);

      if ($node->save() !== SAVED_NEW) {
        throw new \Exception("Failed to create node for file '{$file->id()}'.");
      }

      // Create a media with the file attached and also pointing at the node.
      $field = $this->doGetField($values);

      $media_values = array_merge(
        [
          'bundle' => $values['media_type'],
          'name' => $file->getFilename(),
          IslandoraUtils::MEDIA_OF_FIELD => $node,
          IslandoraUtils::MEDIA_USAGE_FIELD => ($values['use'] ?
            $taxonomy_term_storage->loadMultiple($values['use']) :
            NULL),
          'uid' => $this->currentUser->id(),
          // XXX: Published... no constant?
          'status' => 1,
        ],
        [
          $field->getName() => [
            $info,
          ],
        ]
      );
      $media = $this->entityTypeManager->getStorage('media')->create($media_values);
      if ($media->save() !== SAVED_NEW) {
        throw new \Exception("Failed to create media for file '{$file->id()}.");
      }

      $context['results'] = array_merge_recursive($context['results'], [
        'validation_violations' => $this->validationClassification([
          $file,
          $media,
          $node,
        ]),
      ]);
      $context['results']['count'] += 1;
    }
    catch (HttpExceptionInterface $e) {
      $transaction->rollBack();
      throw $e;
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw new HttpException(500, $e->getMessage(), $e);
    }
  }

  /**
   * @param array $entities
   *
   * @return array
   */
  protected function validationClassification(array $entities) {
    $violations = [];

    foreach ($entities as $entity) {
      $entity_violations = $entity->validate();
      if ($entity_violations->count() > 0) {
        $violations[$entity->getEntityTypeId()][$entity->id()] = $entity_violations->count();
      }
    }

    return $violations;
  }

  /**
   * Implements callback_batch_finished() for our child addition batch.
   */
  public function batchProcessFinished($success, $results, $operations): void {
    if ($success) {
      $this->messenger()->addMessage($this->formatPlural(
        $results['count'],
        'Added 1 child node.',
        'Added @count child nodes.'
      ));
      foreach ($results['validation_violations'] ?? [] as $entity_type => $info) {
        foreach ($info as $id => $count) {
          $this->messenger()->addWarning($this->formatPlural(
            $count,
            '1 validation error present in <a href=":uri">bulk created entity of type %type, with ID %id</a>.',
            '@count validation errors present in <a href=":uri">bulk created entity of type %type, with ID %id</a>.',
            [
              '%type' => $entity_type,
              ':uri' => Url::fromRoute("entity.{$entity_type}.canonical", [$entity_type => $id])->toString(),
              '%id' => $id,
            ]
          ));
        }
      }
    }
    else {
      $this->messenger()->addError($this->t('Encountered an error when adding children.'));
    }
  }

}
