<?php

namespace Drupal\islandora\EventGenerator;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\islandora\Event\StompHeaderEvent;
use Drupal\islandora\Event\StompHeaderEventException;
use Stomp\Exception\StompException;
use Stomp\StatefulStomp;
use Stomp\Transport\Message;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Configurable action base for actions that publish messages to queues.
 */
abstract class EmitEvent extends ConfigurableActionBase implements ContainerFactoryPluginInterface {

  /**
   * Current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Event generator service.
   *
   * @var \Drupal\islandora\EventGenerator\EventGeneratorInterface
   */
  protected $eventGenerator;

  /**
   * Stomp client.
   *
   * @var \Stomp\StatefulStomp
   */
  protected $stomp;

  /**
   * Event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Constructs a EmitEvent action.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\islandora\EventGenerator\EventGeneratorInterface $event_generator
   *   EventGenerator service to serialize AS2 events.
   * @param \Stomp\StatefulStomp $stomp
   *   Stomp client.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AccountInterface $account,
    EntityTypeManagerInterface $entity_type_manager,
    EventGeneratorInterface $event_generator,
    StatefulStomp $stomp,
    EventDispatcherInterface $event_dispatcher
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->account = $account;
    $this->entityTypeManager = $entity_type_manager;
    $this->eventGenerator = $event_generator;
    $this->stomp = $stomp;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('islandora.eventgenerator'),
      $container->get('islandora.stomp'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    // Generate event as stomp message.
    try {
      $user = $this->entityTypeManager->getStorage('user')->load($this->account->id());
      $data = $this->generateData($entity);

      $event = $this->eventDispatcher->dispatch(
        StompHeaderEvent::EVENT_NAME,
        new StompHeaderEvent($entity, $user, $data, $this->getConfiguration())
      );

      $message = new Message(
        $this->eventGenerator->generateEvent($entity, $user, $data),
        $event->getHeaders()->all()
      );
    }
    catch (StompHeaderEventException $e) {
      \Drupal::logger('islandora')->error($e->getMessage());
      drupal_set_message($e->getMessage(), 'error');
      return;
    }
    catch (\RuntimeException $e) {
      // Notify the user the event couldn't be generated and abort.
      \Drupal::logger('islandora')->error(
        t('Error generating event: @msg', ['@msg' => $e->getMessage()])
      );
      drupal_set_message(
        t('Error generating event: @msg', ['@msg' => $e->getMessage()]),
        'error'
      );
      return;
    }

    // Send the message.
    try {
      $this->stomp->begin();
      $this->stomp->send($this->configuration['queue'], $message);
      $this->stomp->commit();
    }
    catch (StompException $e) {
      // Log it.
      \Drupal::logger('islandora')->error(
        'Error publishing message: @msg',
        ['@msg' => $e->getMessage()]
      );

      // Notify user.
      drupal_set_message(
        t('Error publishing message: @msg',
          ['@msg' => $e->getMessage()]
        ),
        'error'
      );
    }
  }

  /**
   * Override this function to control what gets encoded as a json note.
   */
  protected function generateData(EntityInterface $entity) {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'queue' => '',
      'event' => 'Create',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['queue'] = [
      '#type' => 'textfield',
      '#title' => t('Queue'),
      '#default_value' => $this->configuration['queue'],
      '#required' => TRUE,
      '#rows' => '8',
      '#description' => t('Name of queue to which event is published'),
    ];
    $form['event'] = [
      '#type' => 'select',
      '#title' => t('Event type'),
      '#default_value' => $this->configuration['event'],
      '#description' => t('Type of event to emit'),
      '#options' => [
        'Create' => t('Create'),
        'Update' => t('Update'),
        'Delete' => t('Delete'),
        'Generate Derivative' => t('Generate Derivative'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['queue'] = $form_state->getValue('queue');
    $this->configuration['event'] = $form_state->getValue('event');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = AccessResult::allowed();
    return $return_as_object ? $result : $result->isAllowed();
  }

}
