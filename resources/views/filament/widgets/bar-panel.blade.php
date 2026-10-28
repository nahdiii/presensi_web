<x-filament-widgets::widget>
    <x-filament::section :heading="$heading" :description="$description">
        @if (empty($items))
            <p class="pk-empty">Belum ada pegawai terjadwal hari ini.</p>
        @else
            <ul class="pk-list">
                @foreach ($items as $item)
                    <li>
                        <div class="pk-list-row">
                            <span class="pk-list-label">{{ $item['label'] }}</span>
                            <span class="pk-list-value">{{ $item['value'] }}</span>
                        </div>
                        <div @class(['pk-track', 'pk-thin' => $thin])>
                            <div class="pk-fill {{ $item['color'] }}" style="width: {{ round($item['percent'], 1) }}%"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
