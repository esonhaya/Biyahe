<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ([
            ['label' => 'Active advisories', 'key' => 'active_advisories'],
            ['label' => 'Expiring within 6 hours', 'key' => 'expiring_soon'],
            ['label' => 'Unreviewed reports', 'key' => 'unreviewed_reports'],
            ['label' => 'Accepted community signals', 'key' => 'accepted_signals'],
            ['label' => 'Open conflicts', 'key' => 'open_conflicts'],
            ['label' => 'High-impact entities', 'key' => 'high_impact_entities'],
        ] as $stat)
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</div>
                <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $stats[$stat['key']] ?? 0 }}</div>
            </div>
        @endforeach
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400">Review temporary operational advisories and community signals here. Reports remain non-authoritative until an explicit reviewed advisory is created.</p>
</x-filament-panels::page>
