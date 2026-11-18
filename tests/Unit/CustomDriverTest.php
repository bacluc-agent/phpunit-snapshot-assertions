<?php

namespace Spatie\Snapshots\Test\Unit;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Spatie\Snapshots\Driver;
use Spatie\Snapshots\Drivers\HtmlDriver;
use Spatie\Snapshots\Drivers\ImageDriver;
use Spatie\Snapshots\Drivers\JsonDriver;
use Spatie\Snapshots\Drivers\ObjectDriver;
use Spatie\Snapshots\Drivers\TextDriver;
use Spatie\Snapshots\Drivers\XmlDriver;
use Spatie\Snapshots\Drivers\YamlDriver;
use Spatie\Snapshots\MatchesSnapshots;

class CustomDriverTest extends TestCase
{
    private string $snapshotDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotDirectory = sys_get_temp_dir().
            DIRECTORY_SEPARATOR.
            uniqid('custom-driver-test-', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->snapshotDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }

        if (file_exists($this->snapshotDirectory)) {
            rmdir($this->snapshotDirectory);
        }

        parent::tearDown();
    }

    /** @test */
    #[Test]
    public function it_returns_the_built_in_driver_when_the_factory_is_not_overridden()
    {
        $defaultCase = new DefaultDriverTestCase($this->snapshotDirectory);

        $this->assertInstanceOf(HtmlDriver::class, $defaultCase->factory('getSpatieHtmlDriver'));
        $this->assertInstanceOf(JsonDriver::class, $defaultCase->factory('getSpatieJsonDriver'));
        $this->assertInstanceOf(ObjectDriver::class, $defaultCase->factory('getSpatieObjectDriver'));
        $this->assertInstanceOf(TextDriver::class, $defaultCase->factory('getSpatieTextDriver'));
        $this->assertInstanceOf(XmlDriver::class, $defaultCase->factory('getSpatieXmlDriver'));
        $this->assertInstanceOf(YamlDriver::class, $defaultCase->factory('getSpatieYamlDriver'));
    }

    /** @test */
    #[Test]
    public function it_uses_the_overridden_driver()
    {
        $case = $this->case();

        $case->assertMatchesJsonSnapshot('{"a":1}', 'overridden');

        $this->assertStringContainsString(
            'custom-driver:',
            file_get_contents(glob($this->snapshotDirectory.DIRECTORY_SEPARATOR.'*')[0])
        );
    }

    /** @test */
    #[Test]
    public function it_uses_the_overridden_yaml_driver()
    {
        $case = new OverriddenYamlDriverTestCase($this->snapshotDirectory);

        $case->assertMatchesYamlSnapshot("foo: bar\n", 'overridden-yaml');

        $this->assertStringContainsString(
            'custom-driver:',
            file_get_contents(glob($this->snapshotDirectory.DIRECTORY_SEPARATOR.'*')[0])
        );
    }

    /** @test */
    #[Test]
    public function it_preserves_yaml_string_bytes_with_a_preserving_driver()
    {
        $case = new PreservingYamlDriverTestCase($this->snapshotDirectory);
        $yaml = "foo:   bar\r\nqux:  'quoted'\r\n";

        $case->assertMatchesYamlSnapshot($yaml, 'preserved-yaml');

        $this->assertSame(
            $yaml,
            file_get_contents(glob($this->snapshotDirectory.DIRECTORY_SEPARATOR.'*')[0])
        );
    }

    /** @test */
    #[Test]
    public function it_uses_the_overridden_driver_for_every_typed_assertion()
    {
        $case = $this->case();

        $case->assertMatchesHtmlSnapshot('<p>hi</p>', 'html');
        $case->assertMatchesTextSnapshot('some text', 'text');
        $case->assertMatchesObjectSnapshot((object) ['a' => 1], 'object');
        $case->assertMatchesXmlSnapshot('<root/>', 'xml');

        $written = array_map(
            fn (string $file) => file_get_contents($file),
            glob($this->snapshotDirectory.DIRECTORY_SEPARATOR.'*')
        );

        $this->assertCount(4, $written);

        foreach ($written as $contents) {
            $this->assertStringContainsString('custom-driver:', $contents);
        }
    }

    /** @test */
    #[Test]
    public function it_uses_the_overridden_driver_for_assert_matches_snapshot_without_an_explicit_driver()
    {
        $case = $this->case();

        $case->assertMatchesSnapshot('a string', null, 'implicit-text');
        $case->assertMatchesSnapshot(42, null, 'implicit-int');
        $case->assertMatchesSnapshot(['a' => 1], null, 'implicit-array');
        $case->assertMatchesSnapshot((object) ['b' => 2], null, 'implicit-object');

        $written = array_map(
            fn (string $file) => file_get_contents($file),
            glob($this->snapshotDirectory.DIRECTORY_SEPARATOR.'*')
        );

        $this->assertCount(4, $written);

        foreach ($written as $contents) {
            $this->assertStringContainsString('custom-driver:', $contents);
        }
    }

    /** @test */
    #[Test]
    public function it_uses_the_overridden_text_driver_for_file_hash_snapshots()
    {
        $case = $this->case();

        $case->assertMatchesFileHashSnapshot(__DIR__.'/test_files/testA.png', 'file-hash');

        $this->assertStringContainsString(
            'custom-driver:',
            file_get_contents(glob($this->snapshotDirectory.DIRECTORY_SEPARATOR.'*')[0])
        );
    }

    /** @test */
    #[Test]
    public function it_prefers_the_explicit_driver_over_the_overridden_factory()
    {
        $case = $this->case();

        $case->assertMatchesSnapshot('{"a":1}', new TextDriver, 'explicit');

        $this->assertStringNotContainsString(
            'custom-driver:',
            file_get_contents(glob($this->snapshotDirectory.DIRECTORY_SEPARATOR.'*')[0])
        );
    }

    /** @test */
    #[Test]
    public function it_passes_the_threshold_and_antialiasing_arguments_to_the_overridden_image_driver()
    {
        $case = $this->case();

        $case->assertMatchesImageSnapshot(__DIR__.'/test_files/testA.png', 0.5, false, 'image');

        $this->assertSame([0.5, false], $case->imageDriverArgs);
    }

    /** @test */
    #[Test]
    public function it_forwards_its_arguments_to_the_default_image_driver()
    {
        $driver = (new DefaultDriverTestCase($this->snapshotDirectory))->factory('getSpatieImageDriver', 0.5, false);

        $read = Closure::bind(function () {
            return [$this->threshold, $this->includeAa];
        }, $driver, ImageDriver::class);

        [$threshold, $includeAa] = $read();

        $this->assertSame(0.5, $threshold);
        $this->assertFalse($includeAa);
    }

    private function case(): CustomDriverTestCase
    {
        return new CustomDriverTestCase($this->snapshotDirectory);
    }
}

class LabelledTextDriver extends TextDriver
{
    private const LABEL = 'custom-driver:';

    public function serialize($data): string
    {
        return self::LABEL.parent::serialize($data);
    }
}

class LabelledObjectDriver extends ObjectDriver
{
    private const LABEL = 'custom-driver:';

    public function serialize($data): string
    {
        return self::LABEL.parent::serialize($data);
    }
}

abstract class CustomDriverTestCaseBase
{
    use MatchesSnapshots;

    public function __construct(private string $snapshotDirectory) {}

    public function nameWithDataSet(): string
    {
        return 'custom-driver';
    }

    public function factory(string $name, mixed ...$args): Driver
    {
        return $this->{$name}(...$args);
    }

    protected function getSnapshotDirectory(): string
    {
        return $this->snapshotDirectory;
    }

    protected function shouldUpdateSnapshots(): bool
    {
        return false;
    }

    protected function shouldCreateSnapshots(): bool
    {
        return true;
    }

    public function markTestIncomplete(string $message = ''): void {}

    public function fail(string $message = ''): void
    {
        throw new RuntimeException($message);
    }

    public function assertTrue(bool $condition): void {}
}

class CustomDriverTestCase extends CustomDriverTestCaseBase
{
    /** @var array */
    public array $imageDriverArgs = [];

    protected function getSpatieJsonDriver(): Driver
    {
        return new LabelledTextDriver;
    }

    protected function getSpatieImageDriver(float $threshold = 0.1, bool $includeAa = true): Driver
    {
        $this->imageDriverArgs = [$threshold, $includeAa];

        return new TextDriver;
    }

    protected function getSpatieHtmlDriver(): Driver
    {
        return new LabelledTextDriver;
    }

    protected function getSpatieXmlDriver(): Driver
    {
        return new LabelledTextDriver;
    }

    protected function getSpatieTextDriver(): Driver
    {
        return new LabelledTextDriver;
    }

    protected function getSpatieObjectDriver(): Driver
    {
        return new LabelledObjectDriver;
    }
}

class OverriddenYamlDriverTestCase extends CustomDriverTestCaseBase
{
    protected function getSpatieYamlDriver(): Driver
    {
        return new LabelledTextDriver;
    }
}

class PreservingYamlDriverTestCase extends CustomDriverTestCaseBase
{
    protected function getSpatieYamlDriver(): Driver
    {
        return new YamlDriver(preserveStrings: true);
    }
}

class DefaultDriverTestCase extends CustomDriverTestCaseBase
{
}
