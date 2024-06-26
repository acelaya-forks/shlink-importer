<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Importer\Sources\Bitly;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Importer\Http\InvalidRequestException;
use Shlinkio\Shlink\Importer\Http\RestApiConsumerInterface;
use Shlinkio\Shlink\Importer\Model\ImportedShlinkUrl as ShlinkUrl;
use Shlinkio\Shlink\Importer\Sources\Bitly\BitlyApiException;
use Shlinkio\Shlink\Importer\Sources\Bitly\BitlyApiImporter;
use Shlinkio\Shlink\Importer\Sources\ImportSource;

use function explode;
use function sprintf;
use function str_contains;
use function str_starts_with;

class BitlyApiImporterTest extends TestCase
{
    private BitlyApiImporter $importer;
    private MockObject & RestApiConsumerInterface $apiConsumer;

    public function setUp(): void
    {
        $this->apiConsumer = $this->createMock(RestApiConsumerInterface::class);
        $this->importer = new BitlyApiImporter($this->apiConsumer);
    }

    #[Test, DataProvider('provideParams')]
    public function groupsAndUrlsAreRecursivelyFetched(array $paramsMap, array $expected): void
    {
        $paramsMap['access_token'] = static fn () => 'abc123';
        $params = ImportSource::BITLY->toParamsWithCallableMap($paramsMap);

        $callCounts = [];
        $this->apiConsumer->expects($this->exactly(5))->method('callApi')->willReturnCallback(
            function (string $uri) use (&$callCounts) {
                if ($uri === 'https://api-ssl.bitly.com/v4/groups') {
                    return [
                        'groups' => [
                            ['guid' => 'abc'],
                            ['guid' => 'def'],
                            ['guid' => 'ghi'],
                        ],
                    ];
                }

                if (! str_starts_with($uri, 'https://api-ssl.bitly.com/v4/groups/')) {
                    return [];
                }

                [$url] = explode('?', $uri);
                $callCounts[$url] = ($callCounts[$url] ?? 0) + 1;

                if ($callCounts[$url] === 1 && str_contains($url, 'def')) {
                    return [
                        'links' => [
                            [
                                'created_at' => '2020-03-01T00:00:00+0000',
                                'link' => 'http://bit.ly/ccc',
                                'long_url' => 'https://shlink.io',
                                'title' => 'Cool title',
                            ],
                            [
                                'created_at' => '2020-04-01T00:00:00+0000',
                                'link' => 'http://customdom.com/ddd',
                                'custom_bitlinks' => [],
                                'long_url' => 'https://github.com',
                                'tags' => ['bar'],
                            ],
                        ],
                        'pagination' => [
                            'next' => 'https://api-ssl.bitly.com/v4/groups/def/bitlinks',
                        ],
                    ];
                }

                return [
                    'links' => [
                        [
                            'created_at' => '2020-01-01T00:00:00+0000',
                            'link' => 'http://bit.ly/aaa',
                            'long_url' => 'https://shlink.io',
                        ],
                        [
                            'created_at' => '2020-02-01T00:00:00+0000',
                            'link' => 'http://bit.ly/this_should_be_ignored',
                            'custom_bitlinks' => ['http://bit.ly/bbb'],
                            'long_url' => 'https://github.com',
                            'tags' => ['foo', 'bar'],
                        ],
                    ],
                    'pagination' => [
                        'next' => '',
                    ],
                ];
            },
        );

        $urls = [...$this->importer->import($params)->shlinkUrls];

        self::assertEquals($expected, $urls);
    }

    public static function provideParams(): iterable
    {
        $source = ImportSource::BITLY;

        yield 'default options' => [[], [
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', ['foo', 'bar'], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-03-01T00:00:00+0000',
            ), null, 'ccc', 'Cool title'),
            new ShlinkUrl($source, 'https://github.com', ['bar'], self::createDate(
                '2020-04-01T00:00:00+0000',
            ), null, 'ddd', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', ['foo', 'bar'], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', ['foo', 'bar'], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
        ]];
        yield 'ignore archived' => [['ignore_archived' => fn () => true], [
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', ['foo', 'bar'], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-03-01T00:00:00+0000',
            ), null, 'ccc', 'Cool title'),
            new ShlinkUrl($source, 'https://github.com', ['bar'], self::createDate(
                '2020-04-01T00:00:00+0000',
            ), null, 'ddd', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', ['foo', 'bar'], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', ['foo', 'bar'], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
        ]];
        yield 'ignore tags' => [['import_tags' => fn () => false], [
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', [], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-03-01T00:00:00+0000',
            ), null, 'ccc', 'Cool title'),
            new ShlinkUrl($source, 'https://github.com', [], self::createDate(
                '2020-04-01T00:00:00+0000',
            ), null, 'ddd', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', [], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', [], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
        ]];
        yield 'import custom domains' => [['import_custom_domains' => fn () => true], [
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', ['foo', 'bar'], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-03-01T00:00:00+0000',
            ), null, 'ccc', 'Cool title'),
            new ShlinkUrl($source, 'https://github.com', ['bar'], self::createDate(
                '2020-04-01T00:00:00+0000',
            ), 'customdom.com', 'ddd', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', ['foo', 'bar'], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
            new ShlinkUrl($source, 'https://shlink.io', [], self::createDate(
                '2020-01-01T00:00:00+0000',
            ), null, 'aaa', null),
            new ShlinkUrl($source, 'https://github.com', ['foo', 'bar'], self::createDate(
                '2020-02-01T00:00:00+0000',
            ), null, 'bbb', null),
        ]];
    }

    #[Test, DataProvider('provideErrorStatusCodes')]
    public function throwsExceptionWhenStatusCodeReturnedByApiIsError(int $statusCode): void
    {
        $this->apiConsumer->expects($this->once())->method('callApi')->willThrowException(
            InvalidRequestException::fromResponseData('', $statusCode, 'Error'),
        );

        $this->expectException(BitlyApiException::class);
        $this->expectExceptionMessage('Request to Bitly API v4 to URL');
        $this->expectExceptionMessage(sprintf('failed with status code "%s" and body "Error"', $statusCode));

        // Iteration needed to trigger generator code
        [...$this->importer->import(
            ImportSource::BITLY->toParamsWithCallableMap(['access_token' => fn () => 'abc']),
        )->shlinkUrls];
    }

    public static function provideErrorStatusCodes(): iterable
    {
        yield '400' => [400];
        yield '401' => [401];
        yield '403' => [403];
        yield '404' => [404];
        yield '500' => [500];
    }

    private static function createDate(string $date): DateTimeInterface
    {
        return DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $date); // @phpstan-ignore-line
    }
}
