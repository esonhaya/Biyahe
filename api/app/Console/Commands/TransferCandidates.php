<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Services\Routing\TransferCandidateFinder;
use Illuminate\Console\Command;

class TransferCandidates extends Command
{
    protected $signature = 'biyahe:transfers:candidates {--city= : City slug or ID} {--radius= : Maximum straight-line screening radius in meters} {--limit=50 : Maximum stop records to inspect}';

    protected $description = 'List bounded, unverified transfer candidates for admin review.';

    public function handle(TransferCandidateFinder $finder): int
    {
        $cityId = $this->cityId();
        $candidates = $finder->find(
            cityId: $cityId,
            radiusMeters: $this->option('radius') === null ? null : (float) $this->option('radius'),
            limit: (int) $this->option('limit'),
        );

        $this->line('BIYAHE TRANSFER CANDIDATES');
        $this->line('candidates: '.$candidates->count());
        foreach ($candidates as $candidate) {
            $this->line(sprintf(
                '%s -> %s | %.2f m | %s | %s | REVIEW_REQUIRED',
                $candidate['from_stop']['code'],
                $candidate['to_stop']['code'],
                $candidate['distance_meters'],
                implode(',', array_column($candidate['routes'], 'code')),
                $candidate['trust']->value,
            ));
            $this->line('  reason: '.$candidate['reason']);
        }

        return self::SUCCESS;
    }

    private function cityId(): ?int
    {
        if ($this->option('city') === null) {
            return null;
        }

        $city = (string) $this->option('city');

        return City::query()
            ->where('slug', $city)
            ->when(ctype_digit($city), fn ($query) => $query->orWhereKey((int) $city))
            ->value('id');
    }
}
