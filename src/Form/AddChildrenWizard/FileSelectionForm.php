<?php

namespace Drupal\islandora\Form\AddChildrenWizard;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FileSelectionForm extends FormBase {

  protected ?EntityTypeManagerInterface $entityTypeManager;

  /**
   * The widget plugin manager service.
   *
   * @var WidgetPluginManager
   */
  protected ?PluginManagerInterface $widgetPluginManager;

  protected ?EntityFieldManagerInterface $entityFieldManager;

  protected ?Connection $database;

  protected ?AccountProxyInterface $currentUser;

  public static function create(ContainerInterface $container): self {
    $instance =  parent::create($container);

    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->widgetPluginManager = $container->get('plugin.manager.field.widget');
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->database = $container->get('database');
    $instance->currentUser = $container->get('current_user');

    return $instance;
  }

  public function getFormId() {
    return 'islandora_add_children_wizard_file_selection';
  }

  protected function getMediaType(FormStateInterface $form_state) : MediaTypeInterface {
    return $this->doGetMediaType($form_state->getTemporaryValue('wizard'));
  }

  protected function doGetMediaType(array $values) : MediaTypeInterface {
    /** @var MediaTypeInterface $media_type */
    return  $this->entityTypeManager->getStorage('media_type')->load($values['media_type']);
  }

  protected function getField(FormStateInterface $form_state) : FieldDefinitionInterface {
    $cached_values = $form_state->getTemporaryValue('wizard');

    $field = $this->doGetField($cached_values);
    $field->getFieldStorageDefinition()->set('cardinality', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    return $field;
  }

  protected function doGetField(array $values) : FieldDefinitionInterface {
    $media_type = $this->doGetMediaType($values);
    $media_source = $media_type->getSource();
    $source_field = $media_source->getSourceFieldDefinition($media_type);

    $fields = $this->entityFieldManager->getFieldDefinitions('media', $media_type->id());

    return isset($fields[$source_field->getFieldStorageDefinition()->getName()]) ?
      $fields[$source_field->getFieldStorageDefinition()->getName()] :
      $media_source->createSourceField();
  }

  protected function getWidget(FormStateInterface $form_state) : WidgetInterface {
    return $this->doGetWidget($this->getField($form_state));
  }

  protected function doGetWidget(FieldDefinitionInterface $field) : WidgetInterface {
    return $this->widgetPluginManager->getInstance([
      'field_definition' => $field,
      'form_mode' => 'default',
      'prepare' => TRUE,
    ]);
  }

  public function buildForm(array $form, FormStateInterface $form_state) : array {
    // TODO: Using the media type selected in the previous step, grab the
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

    dsm($form);
    dsm($form_state);
    dsm($this->doGetField($cached_values));
    dsm($this->doGetField($cached_values)->getName());

    $widget = $this->getWidget($form_state);
    $builder = (new BatchBuilder())
      ->setTitle($this->t('Creating children...'))
      ->setInitMessage($this->t('Initializing...'))
      ->setFinishCallback([$this, 'batchProcessFinished']);
    $values = $form_state->getValue($this->doGetField($cached_values)->getName());
    $massaged_values = $widget->massageFormValues($values, $form, $form_state);
    dsm($values);
    dsm($massaged_values);
    foreach ($massaged_values as $delta => $file) {
      dsm($file);
      $builder->addOperation([$this, 'batchProcess'], [$delta, $file, $form, $form_state, $cached_values]);
    }
    batch_set($builder->toArray());
    $form_state->setRedirectUrl(Url::fromUri("internal:/node/{$cached_values['node']}/members"));
  }

  public function batchProcess($delta, $info, array $form, FormStateInterface $form_state, array $values, &$context) {
    $transaction = \Drupal::database()->startTransaction();

    try {
      $taxonomy_term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

      dsm(func_get_args());
      /** @var FileInterface $file */
      $file = $this->entityTypeManager->getStorage('file')->load($info['target_id']);
      $file->setPermanent();
      if ($file->save() !== SAVED_UPDATED) {
        throw new \Exception("Failed to update file '{$file->id()}' to be permanent.");
      }

      $node_storage = $this->entityTypeManager->getStorage('node');
      $parent = $node_storage->load($values['node']);

      // Create a node (with the filename?) (and also belonging to the target node).
      $node = $node_storage->create([
        'type' => $values['bundle'],
        'title' => $file->getFilename(),
        IslandoraUtils::MEMBER_OF_FIELD => $parent,
        'uid' => $this->currentUser->id(),
        'status' => NodeInterface::PUBLISHED,
        IslandoraUtils::MODEL_FIELD => $values['model'] ?
          $taxonomy_term_storage->load($values['model']) :
          NULL,
      ]);
      if ($node->save() !== SAVED_NEW) {
        throw new \Exception("Failed to create node for file '{$file->id()}'.");
      }

      // Create a media with the file attached and also pointing at the node.
      $field = $this->doGetField($values);
      $widget = $this->doGetWidget($field);
      $items = FieldItemList::createInstance($field, $field->getName(), $this->getMediaType($form_state)->getTypedData());
      $items->setValue([0 => $info]);
      //$items->setValue([$delta => $info]);

      $media_values = array_merge(
        [
          'bundle' => $values['media_type'],
          'name' => $file->getFilename(),
          IslandoraUtils::MEDIA_OF_FIELD => $node,
          IslandoraUtils::MEDIA_USAGE_FIELD => $values['use'] ?
            $taxonomy_term_storage->loadMultiple($values['use']) :
            NULL,
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
      dsm($media_values);
      $media = $this->entityTypeManager->getStorage('media')->create($media_values);
      if ($media->save() !== SAVED_NEW) {
        throw new \Exception("Failed to create media for file '{$file->id()}.");
      }
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

  public function batchProcessFinished() {
    // TODO: Dump out status message of some sort?
  }

}
