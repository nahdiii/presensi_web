@php
    $user = auth()->user();
    $context = $user?->employee?->unit?->name ?? \App\Support\Branding::institution();
@endphp
<p class="pk-topbar-context">{{ $context }}</p>
