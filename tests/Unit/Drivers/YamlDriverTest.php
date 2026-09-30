<?php

namespace Spatie\Snapshots\Test\Unit\Drivers;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Spatie\Snapshots\Drivers\YamlDriver;
use Symfony\Component\Yaml\Yaml;

class YamlDriverTest extends TestCase
{
    /** @test */
    #[Test]
    public function it_can_serialize_a_yaml_string()
    {
        $driver = new YamlDriver;

        $yamlString = implode("\n", [
            'foo: bar',
            'baz: qux',
            '',
        ]);

        $this->assertEquals($yamlString, $driver->serialize($yamlString));
    }

    /** @test */
    #[Test]
    public function it_can_serialize_a_yaml_array()
    {
        $driver = new YamlDriver;

        $expected = implode("\n", [
            'foo: bar',
            'baz: qux',
            '',
        ]);

        $this->assertEquals($expected, $driver->serialize([
            'foo' => 'bar',
            'baz' => 'qux',
        ]));
    }

    /** @test */
    #[Test]
    public function it_applies_the_dump_options_to_serialize_and_match()
    {
        $data = ['foo' => "line 1\nline 2"];

        $defaultOutput = (new YamlDriver)->serialize($data);
        $blockDriver = new YamlDriver(flags: Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $blockOutput = $blockDriver->serialize($data);

        // symfony/yaml renders the literal block as `|` (5.2) or `|-` (8.0) — assert the common marker only
        $this->assertStringContainsString('|', $blockOutput);
        $this->assertStringNotContainsString('|', $defaultOutput);
        $this->assertNotSame($defaultOutput, $blockOutput);

        // match() must dump arrays with the same options, or the comparison fails
        $blockDriver->match($blockOutput, $data);

        $nested = ['a' => ['b' => 'c']];
        $this->assertStringContainsString("\n  b: c", (new YamlDriver(indent: 2))->serialize($nested));
        $this->assertStringNotContainsString("\n  b: c", (new YamlDriver)->serialize($nested));
    }
}
