<?php

declare(strict_types=1);

namespace Drupal\media_file_relocate\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media_file_relocate\FileRelocator;

/**
 * Configures the file name patterns used to derive media file folders.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [FileRelocator::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'media_file_relocate_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $patterns = $this->config(FileRelocator::SETTINGS)->get('patterns') ?? [];

    $form['patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('File name patterns'),
      '#description' => $this->t('One PCRE regular expression per line, including delimiters, tried in order against the file name. The first capture group (or the whole match when there is no group) becomes the folder name under the field\'s file scheme, replacing the configured upload directory. Example: <code>/^(61220_utsc\d+)/</code> stores <em>61220_utsc11048_sfcHvZg.tif</em> in <em>private://61220_utsc11048/</em>. Files that match no pattern keep the field\'s upload directory. Applies when a media item is saved; run <code>drush media-file-relocate:relocate</code> for existing files.'),
      '#default_value' => implode("\n", $patterns),
      '#rows' => 6,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach ($this->parsePatterns((string) $form_state->getValue('patterns')) as $pattern) {
      $error = FileRelocator::validatePattern($pattern);
      if ($error !== NULL) {
        $form_state->setErrorByName('patterns', $this->t('"@pattern" is not a valid regular expression: @error', [
          '@pattern' => $pattern,
          '@error' => $error,
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(FileRelocator::SETTINGS)
      ->set('patterns', $this->parsePatterns((string) $form_state->getValue('patterns')))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Splits the textarea value into a list of non-empty, trimmed patterns.
   *
   * @param string $value
   *   The raw textarea value.
   *
   * @return string[]
   *   The patterns.
   */
  protected function parsePatterns(string $value): array {
    $lines = array_map('trim', preg_split('/\R/', $value) ?: []);
    return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
  }

}
