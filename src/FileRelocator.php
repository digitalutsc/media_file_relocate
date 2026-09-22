<?php

declare(strict_types=1);

namespace Drupal\media_file_relocate;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\Exception\FileNotExistsException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Moves media files into a folder derived from their file name.
 *
 * The folder is taken from the first configured regular expression that
 * matches the file name: its first capture group, or the whole match when the
 * pattern has no group. The folder replaces the field's configured upload
 * directory, so a file uploaded to private://2026-09/61220_utsc11048_x.tif
 * ends up at private://61220_utsc11048/61220_utsc11048_x.tif.
 */
class FileRelocator {

  /**
   * The configuration object name.
   */
  public const SETTINGS = 'media_file_relocate.settings';

  /**
   * Field types whose items reference a managed file.
   */
  protected const FILE_FIELD_TYPES = ['file', 'image'];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Relocates every file referenced by a media entity's file and image fields.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param bool $dry_run
   *   When TRUE, nothing is moved; the return value lists what would move.
   *
   * @return array<int, array{fid: int, from: string, to: string}>
   *   One entry per file that was (or would be) moved.
   */
  public function relocateMedia(MediaInterface $media, bool $dry_run = FALSE): array {
    $moves = [];
    foreach ($this->getFileFields($media) as $definition) {
      foreach ($media->get($definition->getName()) as $item) {
        $file = $item->entity;
        if (!$file instanceof FileInterface) {
          continue;
        }
        $from = $file->getFileUri();
        $target = $this->getTargetUri($file, $definition);
        if ($target === NULL) {
          continue;
        }
        if ($dry_run || $this->moveTo($file, $target)) {
          $moves[] = [
            'fid' => (int) $file->id(),
            'from' => $from,
            'to' => $dry_run ? $target : $file->getFileUri(),
          ];
        }
      }
    }
    return $moves;
  }

  /**
   * Returns the file and image field definitions of a media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   Field definitions keyed by field name.
   */
  public function getFileFields(MediaInterface $media): array {
    return array_filter(
      $media->getFieldDefinitions(),
      static fn(FieldDefinitionInterface $definition): bool => in_array($definition->getType(), self::FILE_FIELD_TYPES, TRUE)
    );
  }

  /**
   * Computes the URI a file should be stored at, or NULL if no move is needed.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field the file is attached through; supplies the URI scheme.
   *
   * @return string|null
   *   The destination URI, or NULL when the name matches no pattern, the scheme
   *   cannot be determined, or the file is already in the right folder.
   */
  public function getTargetUri(FileInterface $file, FieldDefinitionInterface $definition): ?string {
    $current = (string) $file->getFileUri();
    if ($current === '') {
      return NULL;
    }
    $basename = $this->fileSystem->basename($current);
    $filename = $file->getFilename() ?: $basename;

    $folder = $this->matchFolder($filename);
    if ($folder === NULL) {
      return NULL;
    }

    $scheme = $definition->getFieldStorageDefinition()->getSetting('uri_scheme')
      ?: StreamWrapperManager::getScheme($current);
    if (!$scheme) {
      return NULL;
    }

    $target_dir = $scheme . '://' . $folder;
    if (str_starts_with($current, $target_dir . '/')) {
      return NULL;
    }
    return $target_dir . '/' . $basename;
  }

  /**
   * Moves a file into the folder derived from its name.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity. On success its URI and file name are updated in place.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field the file is attached through.
   *
   * @return bool
   *   TRUE if the file was moved.
   */
  public function relocateFile(FileInterface $file, FieldDefinitionInterface $definition): bool {
    $target = $this->getTargetUri($file, $definition);
    return $target !== NULL && $this->moveTo($file, $target);
  }

  /**
   * Moves a file to an already computed destination URI.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity. On success its URI and file name are updated in place.
   * @param string $target
   *   The destination URI, as returned by getTargetUri().
   *
   * @return bool
   *   TRUE if the file was moved.
   */
  protected function moveTo(FileInterface $file, string $target): bool {
    $source = $file->getFileUri();

    $target_dir = $this->fileSystem->dirname($target);
    if (!$this->fileSystem->prepareDirectory($target_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->logger->error('File @fid was not moved: directory @dir could not be created.', [
        '@fid' => $file->id(),
        '@dir' => $target_dir,
      ]);
      return FALSE;
    }

    try {
      $moved = $this->fileRepository->move($file, $target, FileExists::Rename);
    }
    catch (FileNotExistsException $e) {
      // A dangling file entity is expected now and then; it is not an error.
      $this->logger->warning('File @fid was not moved: @uri does not exist on disk.', [
        '@fid' => $file->id(),
        '@uri' => $source,
      ]);
      return FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('File @fid was not moved to @target: @message', [
        '@fid' => $file->id(),
        '@target' => $target,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    // FileRepository::move() saves a clone; keep the caller's object in sync.
    $file->setFileUri($moved->getFileUri());
    $file->setFilename($moved->getFilename());

    $this->logger->info('Moved file @fid from @from to @to.', [
      '@fid' => $file->id(),
      '@from' => $source,
      '@to' => $moved->getFileUri(),
    ]);
    return TRUE;
  }

  /**
   * Derives a folder name from a file name using the configured patterns.
   *
   * @param string $filename
   *   The file name to test.
   *
   * @return string|null
   *   The folder name, or NULL when no pattern matches.
   */
  public function matchFolder(string $filename): ?string {
    $patterns = $this->configFactory->get(self::SETTINGS)->get('patterns') ?? [];
    foreach ($patterns as $pattern) {
      if (!is_string($pattern) || trim($pattern) === '') {
        continue;
      }
      $error = self::validatePattern($pattern);
      if ($error !== NULL) {
        $this->logger->error('Skipping invalid file name pattern @pattern: @error', [
          '@pattern' => $pattern,
          '@error' => $error,
        ]);
        continue;
      }
      if (preg_match($pattern, $filename, $matches) !== 1) {
        continue;
      }
      $folder = trim((string) ($matches[1] ?? $matches[0]));
      // The folder must be a single, safe path segment.
      if ($folder === '' || $folder === '.' || $folder === '..' || preg_match('#[/\\\\]#', $folder)) {
        $this->logger->warning('Pattern @pattern produced unusable folder name "@folder" for @filename.', [
          '@pattern' => $pattern,
          '@folder' => $folder,
          '@filename' => $filename,
        ]);
        continue;
      }
      return $folder;
    }
    return NULL;
  }

  /**
   * Checks that a string is a valid PCRE pattern.
   *
   * @param string $pattern
   *   The pattern, including delimiters.
   *
   * @return string|null
   *   The PCRE error message, or NULL when the pattern is valid.
   */
  public static function validatePattern(string $pattern): ?string {
    return @preg_match($pattern, '') === FALSE ? preg_last_error_msg() : NULL;
  }

}
