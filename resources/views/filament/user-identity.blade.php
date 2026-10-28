@php
    $user = auth()->user();
    $role = match (true) {
        $user?->isAdmin() => 'Admin SDM',
        $user?->hasRole(\App\Models\User::ROLE_HEAD) => 'Pimpinan unit',
        default => 'Pegawai',
    };
@endphp
@if ($user)
    <span class="pk-user-identity">
        <span class="pk-user-name">{{ $user->name }}</span>
        <span class="pk-user-role">{{ $role }}</span>
    </span>
@endif
