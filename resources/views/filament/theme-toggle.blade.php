<button
    type="button"
    x-data="{}"
    x-on:click="$dispatch('theme-changed', $store.theme === 'dark' ? 'light' : 'dark')"
    x-bind:aria-label="$store.theme === 'dark' ? 'Gunakan mode terang' : 'Gunakan mode gelap'"
    class="pk-icon-btn"
>
    <svg x-show="$store.theme !== 'dark'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
    <svg x-show="$store.theme === 'dark'" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
</button>
