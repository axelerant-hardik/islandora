<?php

namespace Drupal\islandora\Form\AddChildrenWizard;

use Drupal\ctools\Wizard\FormWizardBase;

class Form extends FormWizardBase {

  /**
   * {@inheritdoc}
   */
  public function getOperations($cached_values) {
    // TODO: Implement getOperations() method.
    return [
      'child_type' => [
        'title' => $this->t('Type of children'),
        'form' => TypeSelectionForm::class,
      ],
      'child_files' => [
        'title' => $this->t('Files for children'),
        'form' => FileSelectionForm::class,
      ]
    ];
  }
}
