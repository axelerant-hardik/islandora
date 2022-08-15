<?php

namespace Drupal\islandora\Form\AddChildrenWizard;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\islandora\IslandoraUtils;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Children addition batch processor.
 */
class ChildBatchProcessor {

  use FieldTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|null
   */
  protected ?EntityTypeManagerInterface $entityTypeManager;

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
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    AccountProxyInterface $current_user
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->currentUser = $current_user;
  }

  /**
   * Implements callback_batch_operation() for our child addition batch.
   */
  public function batchOperation($delta, $info, array $values, &$context) {
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
      $field = $this->getField($values);

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
   * Helper to bulk process validatable entities.
   *
   * @param array $entities
   *   An array of entities to scan for validation violations.
   *
   * @return array
   *   An associative array mapping entity type IDs to entity IDs to a count
   *   of validation violations found on then given entity.
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
            '1 validation error present in <a target="_blank" href=":uri">bulk created entity of type %type, with ID %id</a>.',
            '@count validation errors present in <a target="_blank" href=":uri">bulk created entity of type %type, with ID %id</a>.',
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
