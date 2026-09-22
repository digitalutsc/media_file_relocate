<?php

declare(strict_types=1);

namespace Drupal\media_file_relocate\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\media_file_relocate\FileRelocator;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Media File Relocate module.
 */
final class MediaFileRelocateCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileRelocator $relocator,
  ) {
    parent::__construct();
  }

  /**
   * Moves existing media files into folders derived from their file names.
   *
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'media-file-relocate:relocate', aliases: ['mfr:relocate'])]
  #[CLI\Option(name: 'bundle', description: 'Limit to one media type (machine name).')]
  #[CLI\Option(name: 'dry-run', description: 'Only report which files would be moved.')]
  #[CLI\Usage(name: 'drush media-file-relocate:relocate --dry-run', description: 'List the files that would be moved.')]
  #[CLI\Usage(name: 'drush media-file-relocate:relocate --bundle=image', description: 'Move matching files attached to image media.')]
  public function relocate(array $options = ['bundle' => NULL, 'dry-run' => FALSE]): void {
    $storage = $this->entityTypeManager->getStorage('media');
    $query = $storage->getQuery()->accessCheck(FALSE)->sort('mid');
    if (!empty($options['bundle'])) {
      $query->condition('bundle', $options['bundle']);
    }
    $ids = $query->execute();
    $dry_run = (bool) $options['dry-run'];

    $moved = 0;
    foreach (array_chunk($ids, 50) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $media) {
        assert($media instanceof MediaInterface);
        foreach ($this->relocator->relocateMedia($media, $dry_run) as $move) {
          $moved++;
          $this->io()->writeln(sprintf('%s media %d (%s) file %d: %s -> %s',
            $dry_run ? 'Would move' : 'Moved',
            $media->id(),
            $media->bundle(),
            $move['fid'],
            $move['from'],
            $move['to'],
          ));
        }
      }
      $storage->resetCache($chunk);
    }

    $this->io()->success(sprintf('%d media checked, %d file(s) %s.',
      count($ids),
      $moved,
      $dry_run ? 'would be moved' : 'moved',
    ));
  }

}
