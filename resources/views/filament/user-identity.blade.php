@php
    $user = auth()->user();
@endphp
@if ($user)
    <span class="pk-user-identity">
        <span class="pk-user-name">{{ $user->name }}</span>
        <span class="pk-user-role">{{ $user->roleLabel() }}</span>
    </span>
@endif
