<x-filament-widgets::widget>
    <x-filament::section heading="Rekap bulan ini" :description="'Akumulasi status presensi '.$month">
        <ul class="pk-divided">
            @foreach ($rows as $row)
                <li>
                    <span class="pk-list-label">{{ $row['label'] }}</span>
                    <span class="pk-list-value">{{ number_format($row['value'], 0, ',', '.') }}<small>{{ $row['percent'] }}%</small></span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
