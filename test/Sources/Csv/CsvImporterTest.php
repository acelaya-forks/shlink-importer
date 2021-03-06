<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Importer\Sources\Csv;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Importer\Model\ImportedShlinkUrl;
use Shlinkio\Shlink\Importer\Sources\Csv\CsvImporter;
use Shlinkio\Shlink\Importer\Sources\ImportSources;

use function fopen;
use function fwrite;
use function rewind;

class CsvImporterTest extends TestCase
{
    private CsvImporter $importer;

    protected function setUp(): void
    {
        $this->importer = new CsvImporter($this->getDate());
    }

    /**
     * @test
     * @dataProvider provideCSVs
     */
    public function csvIsProperlyImported(string $csv, string $delimiter, array $expectedList): void
    {
        $rawOptions = ['delimiter' => $delimiter, 'stream' => $this->createCsvStream($csv)];

        $result = $this->importer->import($rawOptions);

        $urls = [];
        foreach ($result as $item) {
            $urls[] = $item;
        }

        self::assertEquals($expectedList, $urls);
    }

    public function provideCSVs(): iterable
    {
        yield 'comma separator' => [
            <<<CSV
            Long URL,Tags,Domain  ,Short code, Title
            https://shlink.io,foo|bar|baz,,123,
            https://facebook.com,,example.com,456,my title
            CSV,
            ',',
            [
                new ImportedShlinkUrl(
                    ImportSources::CSV,
                    'https://shlink.io',
                    ['foo', 'bar', 'baz'],
                    $this->getDate(),
                    null,
                    '123',
                    null,
                ),
                new ImportedShlinkUrl(
                    ImportSources::CSV,
                    'https://facebook.com',
                    [],
                    $this->getDate(),
                    'example.com',
                    '456',
                    'my title',
                ),
            ],
        ];
        yield 'semicolon separator' => [
            <<<CSV
            longURL;tags;domain;short code;Title
            https://alejandrocelaya.blog;;;abc;
            https://facebook.com;foo|baz;example.com;def;
            https://shlink.io/documentation;;example.com;ghi;the title
            CSV,
            ';',
            [
                new ImportedShlinkUrl(
                    ImportSources::CSV,
                    'https://alejandrocelaya.blog',
                    [],
                    $this->getDate(),
                    null,
                    'abc',
                    null,
                ),
                new ImportedShlinkUrl(
                    ImportSources::CSV,
                    'https://facebook.com',
                    ['foo', 'baz'],
                    $this->getDate(),
                    'example.com',
                    'def',
                    null,
                ),
                new ImportedShlinkUrl(
                    ImportSources::CSV,
                    'https://shlink.io/documentation',
                    [],
                    $this->getDate(),
                    'example.com',
                    'ghi',
                    'the title',
                ),
            ],
        ];
    }

    /**
     * @return resource
     */
    private function createCsvStream(string $csv)
    {
        $stream = fopen('php://memory', 'rb+');
        fwrite($stream, $csv);
        rewind($stream);

        return $stream;
    }

    private function getDate(): DateTimeInterface
    {
        static $date;
        return $date ?? ($date = new DateTimeImmutable());
    }
}
