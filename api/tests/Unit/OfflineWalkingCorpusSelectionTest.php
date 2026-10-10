<?php

namespace Tests\Unit;

use App\Console\Commands\VerifyOfflineWalkingCorpus;
use App\Services\Routing\OfflineWalkingCorpusSelection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

final class OfflineWalkingCorpusSelectionTest extends TestCase
{
    public function test_case_selection_is_sorted_and_does_not_mark_a_subset_complete(): void
    {
        $indices = OfflineWalkingCorpusSelection::parse('4,1');

        $this->assertSame([1, 4], $indices);
        $this->assertFalse(OfflineWalkingCorpusSelection::isComplete($indices));
    }

    public function test_absent_selection_preserves_full_corpus_mode(): void
    {
        $indices = OfflineWalkingCorpusSelection::parse(null);

        $this->assertNull($indices);
        $this->assertTrue(OfflineWalkingCorpusSelection::isComplete($indices));
    }

    public function test_explicitly_empty_selection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OfflineWalkingCorpusSelection::parse('');
    }

    public function test_repeated_case_indices_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OfflineWalkingCorpusSelection::parse('2,2');
    }

    public function test_corpus_report_writer_publishes_without_overwriting(): void
    {
        $reflection = new ReflectionClass(VerifyOfflineWalkingCorpus::class);
        $command = $reflection->newInstanceWithoutConstructor();
        $writer = $reflection->getMethod('writeNewJson');
        $writer->setAccessible(true);
        $path = sys_get_temp_dir().'/biyahe-corpus-report-'.bin2hex(random_bytes(8)).'.json';

        try {
            $writer->invoke($command, $path, ['format' => 'fixture']);
            $original = file_get_contents($path);
            $this->assertIsString($original);
            $this->assertSame(['format' => 'fixture'], json_decode($original, true, flags: JSON_THROW_ON_ERROR));

            try {
                $writer->invoke($command, $path, ['format' => 'replacement']);
                $this->fail('The report writer must reject an existing result path.');
            } catch (RuntimeException) {
                $this->assertSame($original, file_get_contents($path));
            }
        } finally {
            @unlink($path);
            @unlink($path.'.building');
        }
    }

    #[DataProvider('malformedCaseSelections')]
    public function test_malformed_or_out_of_range_case_indices_are_rejected(string $selection): void
    {
        $this->expectException(InvalidArgumentException::class);

        OfflineWalkingCorpusSelection::parse($selection);
    }

    public static function malformedCaseSelections(): array
    {
        return [
            'whitespace only' => ['   '],
            'negative index' => ['-1'],
            'index outside corpus' => ['10'],
            'leading zero' => ['01'],
            'leading whitespace' => [' 0'],
            'trailing whitespace' => ['0 '],
            'empty item' => ['1,,2'],
            'nonnumeric item' => ['one'],
        ];
    }
}
