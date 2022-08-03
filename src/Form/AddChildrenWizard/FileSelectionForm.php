<?php

namespace Drupal\islandora\Form\AddChildrenWizard;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class FileSelectionForm extends FormBase {

  public function getFormId() {
    return 'islandora_add_children_wizard_file_selection';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    // TODO: Using the media type selected in the previous step, grab the
    // media bundle's "source" field, and create a multi-file upload widget
    // for it, with the same kind of constraints.
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // TODO: Implement submitForm() method.
    $form_state->setError($form, 'Oh no!');
  }
}
