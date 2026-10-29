{{--
    Mode SPA panel admin: isi halaman muncul dengan animasi singkat setiap
    pindah menu, dan angka statistik dihitung naik dari nol. Skrip dipasang
    sekali (data-navigate-once) lalu dijalankan ulang di setiap
    livewire:navigated, termasuk saat halaman pertama dimuat.
--}}
<style>
    @keyframes pk-enter {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: none; }
    }
    .fi-page-header-main-ctn,
    .fi-page-content > *,
    .fi-wi > * {
        /* backwards, bukan both: transform yang tetap aktif setelah animasi membuat
           modal (position: fixed di dalam halaman) terkunci di area konten dan tidak bisa digulir. */
        animation: pk-enter .35s cubic-bezier(.2, .7, .3, 1) backwards;
    }
    .fi-page-content > :nth-child(2) { animation-delay: .05s; }
    .fi-page-content > :nth-child(3) { animation-delay: .1s; }
    .fi-wi > :nth-child(2) { animation-delay: .06s; }
    .fi-wi > :nth-child(3) { animation-delay: .12s; }
    .fi-wi > :nth-child(n+4) { animation-delay: .18s; }
    @media (prefers-reduced-motion: reduce) {
        .fi-page-header-main-ctn, .fi-page-content > *, .fi-wi > * { animation: none; }
    }
</style>
<script data-navigate-once>
    (() => {
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
        const format = new Intl.NumberFormat('id-ID');

        // Hanya angka bulat polos ("12", "1.234"); teks lain dibiarkan apa adanya.
        const countUp = (el) => {
            const text = el.textContent.trim();
            if (!/^\d{1,3}(\.\d{3})*$|^\d+$/.test(text)) return;
            const target = parseInt(text.replace(/\./g, ''), 10);
            if (target === 0) return;
            const duration = 700;
            let start;
            let done = false;
            el.textContent = '0'; // langsung, supaya angka akhir tidak sempat terlihat sekejap
            const step = (now) => {
                if (done) return;
                // Waktu mulai dari frame pertama: timestamp rAF bisa lebih awal dari performance.now().
                start ??= now;
                const t = Math.min(1, (now - start) / duration);
                const eased = 1 - Math.pow(1 - t, 3);
                // Langkah terakhir memakai teks asli supaya format angka tidak berubah.
                el.textContent = t < 1 ? format.format(Math.round(target * eased)) : text;
                if (t < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
            // Browser menjeda rAF di tab/jendela tersembunyi; angka asli tetap dipasang.
            setTimeout(() => {
                done = true;
                el.textContent = text;
            }, duration + 300);
        };

        document.addEventListener('livewire:navigated', () => {
            if (reduce.matches) return;
            document.querySelectorAll('.fi-wi-stats-overview-stat-value').forEach(countUp);
        });
    })();
</script>
