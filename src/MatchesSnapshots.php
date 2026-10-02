<?php

namespace Spatie\Snapshots;

use PHPUnit\Framework\Attributes\PostCondition;
use PHPUnit\Framework\ExpectationFailedException;
use ReflectionClass;
use ReflectionObject;
use Spatie\Snapshots\Concerns\PhpUnitCompatibility;
use Spatie\Snapshots\Concerns\SnapshotDirectoryAware;
use Spatie\Snapshots\Concerns\SnapshotIdAware;
use Spatie\Snapshots\Drivers\HtmlDriver;
use Spatie\Snapshots\Drivers\ImageDriver;
use Spatie\Snapshots\Drivers\JsonDriver;
use Spatie\Snapshots\Drivers\ObjectDriver;
use Spatie\Snapshots\Drivers\TextDriver;
use Spatie\Snapshots\Drivers\XmlDriver;
use Spatie\Snapshots\Drivers\YamlDriver;

trait MatchesSnapshots
{
    use PhpUnitCompatibility;
    use SnapshotDirectoryAware;
    use SnapshotIdAware;

    protected array $snapshotChanges = [];

    /** @postCondition */
    #[PostCondition]
    public function markTestIncompleteIfSnapshotsHaveChanged()
    {
        if (empty($this->snapshotChanges)) {
            return;
        }

        if (count($this->snapshotChanges) === 1) {
            $this->markTestIncomplete($this->snapshotChanges[0]);

            return;
        }

        $formattedMessages = implode(PHP_EOL, array_map(function (string $message) {
            return "- {$message}";
        }, $this->snapshotChanges));

        $this->markTestIncomplete($formattedMessages);
    }

    public function assertMatchesSnapshot($actual, ?Driver $driver = null, ?string $id = null): void
    {
        if (! is_null($driver)) {
            $this->doSnapshotAssertion($actual, $driver, $id);

            return;
        }

        if (is_string($actual) || is_int($actual) || is_float($actual)) {
            $this->doSnapshotAssertion($actual, $this->getTextDriver(), $id);

            return;
        }

        $this->doSnapshotAssertion($actual, $this->getObjectDriver(), $id);
    }

    public function assertMatchesFileHashSnapshot(string $filePath, ?string $id = null): void
    {
        if (! file_exists($filePath)) {
            $this->fail('File does not exist');
        }

        $actual = sha1_file($filePath);

        $this->assertMatchesSnapshot($actual, null, $id);
    }

    public function assertMatchesFileSnapshot(string $file, ?string $id = null): void
    {
        $this->doFileSnapshotAssertion($file, $id);
    }

    public function assertMatchesHtmlSnapshot(string $actual, ?string $id = null): void
    {
        $this->assertMatchesSnapshot($actual, $this->getHtmlDriver(), $id);
    }

    public function assertMatchesJsonSnapshot($actual, ?string $id = null): void
    {
        $this->assertMatchesSnapshot($actual, $this->getJsonDriver(), $id);
    }

    public function assertMatchesObjectSnapshot($actual, ?string $id = null): void
    {
        $this->assertMatchesSnapshot($actual, $this->getObjectDriver(), $id);
    }

    public function assertMatchesTextSnapshot($actual, ?string $id = null): void
    {
        $this->assertMatchesSnapshot($actual, $this->getTextDriver(), $id);
    }

    public function assertMatchesXmlSnapshot($actual, ?string $id = null): void
    {
        $this->assertMatchesSnapshot($actual, $this->getXmlDriver(), $id);
    }

    public function assertMatchesYamlSnapshot($actual, ?string $id = null): void
    {
        $this->assertMatchesSnapshot($actual, $this->getYamlDriver(), $id);
    }

    public function assertMatchesImageSnapshot(
        $actual,
        float $threshold = 0.1,
        bool $includeAa = true,
        ?string $id = null
    ): void {
        $this->assertMatchesSnapshot($actual, $this->getImageDriver($threshold, $includeAa), $id);
    }

    /*
     * Determines the driver used by the matching `assertMatches*Snapshot`
     * method. By default the built-in driver is returned.
     *
     * Override this method if you want to use a different driver for the
     * matching assertion. The returned driver determines the snapshot file's
     * extension, so overriding a driver whose `extension()` differs from the
     * built-in one renames the snapshot files of every assertion that uses it.
     *
     * `assertMatchesSnapshot()` without an explicit driver uses
     * `getTextDriver()` for string/int/float and `getObjectDriver()` otherwise,
     * so `assertMatchesFileHashSnapshot()` uses `getTextDriver()` as well.
     */
    protected function getHtmlDriver(): Driver
    {
        return new HtmlDriver;
    }

    protected function getImageDriver(float $threshold = 0.1, bool $includeAa = true): Driver
    {
        return new ImageDriver($threshold, $includeAa);
    }

    protected function getJsonDriver(): Driver
    {
        return new JsonDriver;
    }

    protected function getObjectDriver(): Driver
    {
        return new ObjectDriver;
    }

    protected function getTextDriver(): Driver
    {
        return new TextDriver;
    }

    protected function getXmlDriver(): Driver
    {
        return new XmlDriver;
    }

    protected function getYamlDriver(): Driver
    {
        return new YamlDriver;
    }

    /*
     * Determines the directory where file snapshots are stored. By default a
     * `__snapshots__/files` directory is created at the same level as the
     * test class.
     */
    protected function getFileSnapshotDirectory(): string
    {
        return $this->getSnapshotDirectory().
            DIRECTORY_SEPARATOR.
            'files';
    }

    /*
     * Determines whether or not the snapshot should be updated instead of
     * matched.
     *
     * Override this method if you want to use a different flag or mechanism
     * than `vendor/bin/update-snapshots` or the `UPDATE_SNAPSHOTS=true` env var.
     */
    protected function shouldUpdateSnapshots(): bool
    {
        if (getenv('UPDATE_SNAPSHOTS') === 'true') {
            return true;
        }

        return in_array('--update-snapshots', $_SERVER['argv'], true);
    }

    /*
     * Determines whether or not the snapshot should be created instead of
     * matched.
     *
     * Override this method if you want to use a different flag or mechanism
     * than the `CREATE_SNAPSHOTS=false` env var.
     */
    protected function shouldCreateSnapshots(): bool
    {
        if (getenv('CREATE_SNAPSHOTS') === 'false') {
            return false;
        }

        return ! in_array('--without-creating-snapshots', $_SERVER['argv'], true);
    }

    protected function resolveSnapshotId(?string $id = null): string
    {
        if ($id !== null) {
            return (new ReflectionClass($this))->getShortName().'__'.
                $this->nameWithDataSet().'__'.
                's-'.$id;
        }

        $this->snapshotIncrementor++;

        return $this->getSnapshotId();
    }

    protected function doSnapshotAssertion(mixed $actual, Driver $driver, ?string $id = null)
    {
        $snapshot = Snapshot::forTestCase(
            $this->resolveSnapshotId($id),
            $this->getSnapshotDirectory(),
            $driver
        );

        if (! $snapshot->exists()) {
            $this->assertSnapshotShouldBeCreated($snapshot->filename());

            $this->createSnapshotAndMarkTestIncomplete($snapshot, $actual);
        }

        if ($this->shouldUpdateSnapshots()) {
            try {
                // We only want to update snapshots which need updating. If the snapshot doesn't
                // match the expected output, we'll catch the failure, create a new snapshot and
                // mark the test as incomplete.
                $snapshot->assertMatches($actual);
            } catch (ExpectationFailedException $exception) {
                $this->updateSnapshotAndMarkTestIncomplete($snapshot, $actual);
            }

            return;
        }

        try {
            $snapshot->assertMatches($actual);
        } catch (ExpectationFailedException $exception) {
            $this->rethrowExpectationFailedExceptionWithUpdateSnapshotsPrompt($exception);
        }
    }

    protected function doFileSnapshotAssertion(string $filePath, ?string $id = null): void
    {
        if (! file_exists($filePath)) {
            $this->fail('File does not exist');
        }

        $fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);

        if (empty($fileExtension)) {
            $this->fail("Unable to make a file snapshot, file does not have a file extension ({$filePath})");
        }

        $fileSystem = Filesystem::inDirectory($this->getFileSnapshotDirectory());

        $snapshotId = $this->resolveSnapshotId($id).'.'.$fileExtension;
        $snapshotId = Filename::cleanFilename($snapshotId);

        // If $filePath has a different file extension than the snapshot, the test should fail
        if ($namesWithDifferentExtension = $fileSystem->getNamesWithDifferentExtension($snapshotId)) {
            // There is always only one existing snapshot with a different extension
            $existingSnapshotId = $namesWithDifferentExtension[0];

            if ($this->shouldUpdateSnapshots()) {
                $fileSystem->delete($existingSnapshotId);

                $fileSystem->copy($filePath, $snapshotId);

                $this->registerSnapshotChange("File snapshot updated for {$snapshotId}");

                return;
            }

            $expectedExtension = pathinfo($existingSnapshotId, PATHINFO_EXTENSION);

            $this->fail("File did not match the snapshot file extension (expected: {$expectedExtension}, was: {$fileExtension})");
        }

        $failedSnapshotId = $snapshotId.'_failed.'.$fileExtension;

        if ($fileSystem->has($failedSnapshotId)) {
            $fileSystem->delete($failedSnapshotId);
        }

        if (! $fileSystem->has($snapshotId)) {
            $this->assertSnapshotShouldBeCreated($failedSnapshotId);

            $fileSystem->copy($filePath, $snapshotId);

            $this->registerSnapshotChange("File snapshot created for {$snapshotId}");

            return;
        }

        if (! $fileSystem->fileEquals($filePath, $snapshotId)) {
            if ($this->shouldUpdateSnapshots()) {
                $fileSystem->copy($filePath, $snapshotId);

                $this->registerSnapshotChange("File snapshot updated for {$snapshotId}");

                return;
            }

            $fileSystem->copy($filePath, $failedSnapshotId);

            $this->fail("File did not match snapshot ({$snapshotId})");
        }

        $this->assertTrue(true);
    }

    protected function createSnapshotAndMarkTestIncomplete(Snapshot $snapshot, $actual): void
    {
        $snapshot->create($actual);

        $this->registerSnapshotChange("Snapshot created for {$snapshot->id()}");
    }

    protected function updateSnapshotAndMarkTestIncomplete(Snapshot $snapshot, $actual): void
    {
        $snapshot->create($actual);

        $this->registerSnapshotChange("Snapshot updated for {$snapshot->id()}");
    }

    protected function rethrowExpectationFailedExceptionWithUpdateSnapshotsPrompt($exception): void
    {
        $newMessage = $exception->getMessage()."\n\n".
            'Snapshots can be updated by running `vendor/bin/update-snapshots`, '.
            'or by setting the `UPDATE_SNAPSHOTS=true` environment variable.';

        $exceptionReflection = new ReflectionObject($exception);

        $messageReflection = $exceptionReflection->getProperty('message');
        $messageReflection->setValue($exception, $newMessage);

        throw $exception;
    }

    protected function registerSnapshotChange(string $message): void
    {
        $this->snapshotChanges[] = $message;
    }

    protected function assertSnapshotShouldBeCreated(string $snapshotFileName): void
    {
        if ($this->shouldCreateSnapshots()) {
            return;
        }

        $this->fail(
            "Snapshot \"$snapshotFileName\" does not exist.\n".
            'You can enable snapshot creation by running `vendor/bin/phpunit` directly, '.
            'or by unsetting the `CREATE_SNAPSHOTS=false` environment variable.'
        );
    }
}
