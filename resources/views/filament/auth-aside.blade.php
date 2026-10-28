<aside class="pk-auth-aside" aria-hidden="true">
    <span class="pk-auth-glow pk-auth-glow-a"></span>
    <span class="pk-auth-glow pk-auth-glow-b"></span>

    <div class="pk-auth-brand">
        <span class="pk-auth-mark">{{ \App\Support\Branding::initials() }}</span>
        <div>
            <p class="pk-auth-brand-name">Presensi</p>
            <p class="pk-auth-brand-sub">{{ \App\Support\Branding::institution() }}</p>
        </div>
    </div>

    <div class="pk-auth-copy">
        <h2>Sistem Presensi Pegawai Kampus</h2>
        <p>Pantau kehadiran dosen dan tendik per unit, setujui izin, dan siapkan rekap bulanan — dengan cakupan yang menyesuaikan peran setiap pengguna.</p>
    </div>

    <p class="pk-auth-foot">© {{ now()->year }} {{ \App\Support\Branding::institution() }}</p>
</aside>
