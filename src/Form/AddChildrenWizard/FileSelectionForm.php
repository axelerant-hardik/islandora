<?php

namespace Drupal\islandora\Form\AddChildrenWizard;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Database\Connection;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\media\MediaTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Children addition wizard's second step.
 */
class FileSelectionForm extends FormBase {

  use WizardTrait {
    WizardTrait::getField as doGetField;
    WizardTrait::getMediaType as doGetMediaType;
    WizardTrait::getWidget as doGetWidget;
  }

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
   * The batch processor service.
   *
   * @var \Drupal\islandora\Form\AddChildrenWizard\ChildBatchProcessor|null
   */
  protected ?ChildBatchProcessor $batchProcessor;

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

    $instance->batchProcessor = $container->get('islandora.upload_children.batch_processor');

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
      ->setFinishCallback([$this->batchProcessor, 'batchProcessFinished']);
    $values = $form_state->getValue($this->doGetField($cached_values)->getName());
    $massaged_values = $widget->massageFormValues($values, $form, $form_state);
    foreach ($massaged_values as $delta => $file) {
      $builder->addOperation(
        [$this->batchProcessor, 'batchOperation'],
        [$delta, $file, $cached_values]
      );
    }
    batch_set($builder->toArray());
    $form_state->setRedirectUrl(Url::fromUri("internal:/node/{$cached_values['node']}/members"));
  }

}
