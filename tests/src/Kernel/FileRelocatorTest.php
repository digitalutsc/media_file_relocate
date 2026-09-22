<?php

declare(strict_types=1);

namespace Drupal\Tests\media_file_relocate\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Kernel\MediaKernelTestBase;

/**
 * Tests that media files are moved into folders derived from their names.
 *
 * @group media_file_relocate
 */
class FileRelocatorTest extends MediaKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['media_file_relocate'];

  /**
   * The media type used by the tests.
   *
   * @var \Drupal\media\MediaTypeInterface
   */
  protected $fileType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['media_file_relocate']);
    $this->config('media_file_relocate.settings')->set('patterns', ['/^(61220_utsc\d+)/'])->save();
    $this->fileType = $this->createMediaType('file', ['id' => 'file']);
  }

  /**
   * Creates a permanent file in the public files directory.
   */
  protected function createPublicFile(string $filename): File {
    $directory = 'public://2026-09';
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $uri = $directory . '/' . $filename;
    file_put_contents($uri, str_repeat('a', 100));
    $file = File::create(['uri' => $uri, 'filename' => $filename]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Matching files move on save; non-matching files stay; saves are idempotent.
   */
  public function testRelocation(): void {
    $matching = $this->createPublicFile('61220_utsc11048_sfcHvZg.tif');
    $other = $this->createPublicFile('random_name.tif');

    $media = Media::create([
      'bundle' => $this->fileType->id(),
      'name' => 'Matching',
      'field_media_file' => ['target_id' => $matching->id()],
    ]);
    $media->save();

    $reloaded = File::load($matching->id());
    $this->assertSame('public://61220_utsc11048/61220_utsc11048_sfcHvZg.tif', $reloaded->getFileUri());
    $this->assertFileExists($reloaded->getFileUri());
    $this->assertFileDoesNotExist('public://2026-09/61220_utsc11048_sfcHvZg.tif');

    // A second save must not move (or rename) the file again.
    $media->save();
    $this->assertSame('public://61220_utsc11048/61220_utsc11048_sfcHvZg.tif', File::load($matching->id())->getFileUri());

    $media2 = Media::create([
      'bundle' => $this->fileType->id(),
      'name' => 'Other',
      'field_media_file' => ['target_id' => $other->id()],
    ]);
    $media2->save();
    $this->assertSame('public://2026-09/random_name.tif', File::load($other->id())->getFileUri());
  }

  /**
   * The folder comes from the first matching pattern's capture group.
   */
  public function testMatchFolder(): void {
    $relocator = \Drupal::service('media_file_relocate.relocator');
    $this->assertSame('61220_utsc11048', $relocator->matchFolder('61220_utsc11048_sfcHvZg.tif'));
    $this->assertNull($relocator->matchFolder('61220_utscX.tif'));
    $this->assertNull($relocator->matchFolder('prefix_61220_utsc1.tif'));

    $this->config('media_file_relocate.settings')->set('patterns', ['/bad[/', '/^(\d+)_/'])->save();
    $this->assertSame('61220', $relocator->matchFolder('61220_utsc11048_sfcHvZg.tif'));
  }

}
