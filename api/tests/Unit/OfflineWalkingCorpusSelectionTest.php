<?php

namespace Tests\Unit;

use App\Services\Routing\OfflineWalkingCorpusSelection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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
