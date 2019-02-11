<?php

namespace Drupal\wh_import_vlb\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class ConfigForm.
 */
class ConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'wh_import_vlb.config',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('wh_import_vlb.config');
    $form['metadata_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Metadata token'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $config->get('metadata_token'),
    ];
    $form['cover_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Cover token'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $config->get('cover_token'),
    ];
    $form['book_categories'] = [
      '#type' => 'textarea',
      '#title' => t('Book categories'),
      '#default_value' => $config->get('book_categories'),
    ];
    $form['book_publisher'] = [
      '#type' => 'textarea',
      '#title' => t('Book publisher'),
      '#default_value' => $config->get('book_publisher'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('wh_import_vlb.config')
      ->set('n', $form_state->getValue('n'))
      ->set('metadata_token', $form_state->getValue('metadata_token'))
      ->set('cover_token', $form_state->getValue('cover_token'))
      ->set('book_categories', $form_state->getValue('book_categories'))
      ->set('book_publisher', $form_state->getValue('book_publisher'))
      ->save();
  }

}
