<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Unit\Eval;

use Katalysis\NeuronAi\Tests\Eval\Hosts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * BF-12: the hosts a reply names, for the no_invented_hosts check (B-TRU-9). Found in BF-11's check 4: a "list the
 * people" reply gave https://your-domain/application/files/… for an image the tool returned only as a path.
 */
final class HostsTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function texts(): array
    {
        return [
            'BF-11 check 4' => ['- Alpha: https://your-domain/application/files/7817/1234/person-1.jpg', ['your-domain']],
            'plain https' => ['See https://katalysis-pro.test/neuron-test/people.', ['katalysis-pro.test']],
            'plain http with a port' => ['At http://Katalysis-Pro.test:8080/x', ['katalysis-pro.test']],
            'markdown link target' => ['[photo](https://cdn.example.org/a.jpg)', ['cdn.example.org']],
            'markdown link text and target' => ['[https://a.test/x](https://b.test/x)', ['a.test', 'b.test']],
            'autolink' => ['<https://katalysis-pro.test/>', ['katalysis-pro.test']],
            'protocol-relative' => ['src //static.test/img.png', ['static.test']],
            'bare www host' => ['Go to www.mysite.co.uk for more.', ['www.mysite.co.uk']],
            'placeholder written as a link' => ['The image is at yourdomain.com/application/files/1/a.jpg', ['yourdomain.com']],
            'placeholder with a port' => ['your-site:8080/a', ['your-site']],
            'placeholder after a scheme' => ['https://example.com/image.jpg', ['example.com']],
            'two of the same, once' => ['https://a.test/1 and https://A.test/2', ['a.test']],
            'trailing dot of a sentence' => ['Visit https://a.test.', ['a.test']],
            'an email address is not a host' => ['Write to editor@example.com about it.', []],
            'a mailto link is not a host' => ['[mail](mailto:alpha@neuron-test.invalid)', []],
            'a relative path is not a host' => ['The photo is /application/files/7817/1234/person-1.jpg', []],
            'a file name is not a host' => ['person-1.jpg and photo.png', []],
            'a comment slash is not a host' => ['a // b', []],
            '"for example" with a slash is not a host' => ['for example/instance', []],
            'nothing' => ['Here are the three test people.', []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('texts')]
    public function testHostsInText(string $text, array $expected): void
    {
        $this->assertSame($expected, Hosts::inText($text));
    }

    public function testAllowedHostsComeFromToolResultsAndThePersonsMessagesOnly(): void
    {
        $calls = [
            // A JSON result as the store keeps it: slashes may be escaped.
            ['name' => 'list_people', 'inputs' => ['url' => 'https://from-an-input.test/'], 'result' => '{"ok":true,"data":{"imageUrl":"https:\/\/katalysis-pro.test\/application\/files\/1\/a.png"}}', 'ok' => true],
            ['name' => 'get_page', 'inputs' => [], 'result' => '{"url":"https://other.test/about"}', 'ok' => true],
        ];

        $this->assertSame(
            ['katalysis-pro.test', 'other.test', 'person.test'],
            Hosts::allowed($calls, ['Compare it with https://person.test/page'])
        );
    }

    public function testInventedHostsAreTheReplysHostsThatNoSourceHas(): void
    {
        $calls = [['name' => 'list_people', 'inputs' => [], 'result' => '{"imageUrl":"https://katalysis-pro.test/application/files/1/a.png"}', 'ok' => true]];

        $this->assertSame([], Hosts::invented('[Alpha](https://katalysis-pro.test/application/files/1/a.png)', $calls, []));
        $this->assertSame(['your-domain'], Hosts::invented('https://katalysis-pro.test/x and https://your-domain/y', $calls, []));
        // The real dev host, when no tool returned it in the run (Glyn, approving BF-12: still invented).
        $this->assertSame(['katalysis-pro.test'], Hosts::invented('https://katalysis-pro.test/x', [], ['List the people.']));
    }
}
