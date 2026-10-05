<?php
/**
 * SI-AMANAH - API penyimpanan server (PHP 7.2+ / 8.x, tanpa database)
 * Sistem Informasi Adiwiyata dan Manajemen Bank Sampah - MAN 1 Palembang
 * Dikembangkan oleh Genov - Copyright 2026
 *
 * Data disimpan di folder data/ (dibuat otomatis, diproteksi .htaccess).
 * Aksi: status, setup, login, load, save, request, like, profile, seen, reset, logout
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

const SESSION_DAYS = 30;          // masa berlaku sesi login
const MAX_BACKUPS  = 30;          // jumlah cadangan otomatis yang disimpan
const BACKUP_EVERY = 3600;        // cadangan otomatis paling sering tiap 1 jam
const MAX_FAILS    = 10;          // batas salah sandi per IP
const LOCK_MINUTES = 10;          // lama blokir setelah melewati batas

$DIR = __DIR__ . '/data';
// Berkas berekstensi .php berawalan kode exit agar tidak bisa dibaca lewat URL (aman juga di Nginx)
$DB  = $DIR . '/si-amanah.json.php';
$SES = $DIR . '/sessions.json.php';
$ATT = $DIR . '/attempts.json.php';
$LKF = $DIR . '/likes.json.php';   // data suka (love) foto galeri, terpisah agar ringan
$MTF = $DIR . '/meta.json.php';    // rev & epoch ringkas untuk pengecekan realtime (tiap 5 detik)
$NTF = $DIR . '/notif.json.php';   // waktu terakhir notifikasi dibaca per akun
const JF = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
const GUARD = "<?php http_response_code(404); exit; ?>\n";
$LCK = $DIR . '/.lock';

function out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JF);
    exit;
}
function fail(int $code, string $msg): void { out($code, ['ok' => false, 'error' => $msg]); }

set_exception_handler(function ($e) { fail(500, 'Kesalahan server: ' . $e->getMessage()); });
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false; // galat yang diredam dengan @
    throw new ErrorException($str, 0, $no, $file, $line);
});
function lower(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); }
function cut(string $s, int $n): string { $s = trim($s); return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'Gunakan metode POST.');

/* ---------- Folder data & proteksi ---------- */
if (!is_dir($DIR) && !@mkdir($DIR, 0755, true)) fail(500, 'Folder data/ tidak dapat dibuat. Periksa izin tulis folder aplikasi.');
if (!is_writable($DIR)) fail(500, 'Folder data/ tidak dapat ditulis. Ubah izin folder menjadi 755 atau 775.');
if (!file_exists($DIR . '/.htaccess')) @file_put_contents($DIR . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
if (!file_exists($DIR . '/index.html')) @file_put_contents($DIR . '/index.html', '');
if (!is_dir($DIR . '/backup')) @mkdir($DIR . '/backup', 0755, true);

/* ---------- Utilitas file JSON (atomik) ---------- */
function readJson(string $f, $def) {
    if (!is_file($f)) return $def;
    $t = @file_get_contents($f);
    if ($t === false || $t === '') return $def;
    if (strpos($t, GUARD) === 0) $t = substr($t, strlen(GUARD));
    $j = json_decode($t, true);
    return is_array($j) ? $j : $def;
}
function writeJson(string $f, $data): void {
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JF);
    if ($json === false) throw new RuntimeException('Data tidak dapat dikodekan.');
    if (@file_put_contents($tmp, GUARD . $json, LOCK_EX) === false) throw new RuntimeException('Gagal menulis file data (ruang disk penuh?).');
    if (!@rename($tmp, $f)) { @unlink($tmp); throw new RuntimeException('Gagal menyimpan file data.'); }
}
/* ---------- Jalur cepat realtime: tanpa membuka file data besar bila tidak ada perubahan ---------- */
if (($_GET['action'] ?? '') === 'load') {
    $rawF = file_get_contents('php://input');
    $inF = is_string($rawF) && $rawF !== '' ? json_decode($rawF, true) : null;
    $meta = readJson($MTF, null);
    if (is_array($inF) && is_array($meta) && isset($meta['rev'], $meta['epoch'])
        && (int)($inF['rev'] ?? -1) === (int)$meta['rev'] && ($inF['epoch'] ?? '') === $meta['epoch']) {
        $tok = $inF['token'] ?? null;
        if (!is_string($tok) || !preg_match('/^[a-f0-9]{48}$/', $tok)) fail(401, 'Sesi tidak valid.');
        $sF = readJson($SES, []);
        if (!isset($sF[$tok]) || ($sF[$tok]['exp'] ?? 0) < time()) fail(401, 'Sesi berakhir.');
        $nt = readJson($NTF, []); $uidF = (string)$sF[$tok]['uid'];
        out(200, ['ok' => true, 'same' => true, 'rev' => (int)$meta['rev'], 'epoch' => $meta['epoch'], 'seen' => (int)($nt[$uidF] ?? 0), 'clr' => (int)($nt['c_' . $uidF] ?? 0)] + likesOut($LKF, $inF['lrev'] ?? -1));
    }
}
$lockH = fopen($LCK, 'c');
if (!$lockH || !flock($lockH, LOCK_EX)) fail(503, 'Server sibuk, coba lagi.');

function emptyData(): array {
    return [
        'pengaturan' => ['sekolah' => 'MAN 1 Palembang', 'alamat' => 'Jalan Gub. H. Ahmad Bastari, Jalan Pendidikan - Jakabaring, Palembang', 'kepsek' => '', 'nip' => '', 'koor' => '', 'nipKoor' => '', 'petugas' => ''],
        'users' => [], 'masterSampah' => [], 'kegiatan' => [], 'transaksiBS' => [], 'masterTanaman' => [], 'penjualanTN' => [], 'laporanTanaman' => [], 'inventaris' => [], 'penarikan' => [], 'sembunyi' => [], 'agenda' => [], 'hapus' => new stdClass(), '_epoch' => ''
    ];
}
function saveDb(string $DB, array $db): void { // simpan data + ringkasan rev untuk jalur cepat
    global $MTF;
    writeJson($DB, $db);
    writeJson($MTF, ['rev' => (int)$db['rev'], 'epoch' => (string)$db['epoch']]);
}
function seenOf(string $NTF, string $uid): int { $n = readJson($NTF, []); return (int)($n[$uid] ?? 0); }
function ntOut(string $NTF, string $uid): array { $n = readJson($NTF, []); return ['seen' => (int)($n[$uid] ?? 0), 'clr' => (int)($n['c_' . $uid] ?? 0)]; }
function loadDb(string $DB): array {
    $db = readJson($DB, null);
    if (!$db || !isset($db['data']) || !is_array($db['data'])) {
        $db = ['rev' => 0, 'epoch' => bin2hex(random_bytes(8)), 'data' => emptyData(), 'savedAt' => 0, 'backupAt' => 0];
    }
    $db['data']['users'] = normUsers($db['data']['users'] ?? []);
    return $db;
}
/* Peran: admin, guru, siswa (+ kader). Bila belum ada admin, guru pertama dijadikan admin (data versi lama). */
function normUsers($us): array {
    $out = []; $hasAdmin = false;
    foreach (is_array($us) ? $us : [] as $u) {
        if (!is_array($u) || !isset($u['id'], $u['username'])) continue;
        $r = $u['role'] ?? 'siswa';
        $u['role'] = in_array($r, ['admin', 'guru'], true) ? $r : 'siswa';
        $u['kader'] = $u['role'] === 'siswa' && !empty($u['kader']);
        $u['kelas'] = (string)($u['kelas'] ?? '');
        if ($u['role'] === 'admin') $hasAdmin = true;
        $out[] = $u;
    }
    if (!$hasAdmin) foreach ($out as $i => $u) if ($u['role'] === 'guru') { $out[$i]['role'] = 'admin'; break; }
    return $out;
}
function canInput(array $u): bool { return in_array($u['role'] ?? '', ['admin', 'guru'], true) || (($u['role'] ?? '') === 'siswa' && !empty($u['kader'])); }
function validData($d): bool {
    if (!is_array($d) || !isset($d['pengaturan']) || !is_array($d['pengaturan'])) return false;
    foreach (['users', 'kegiatan', 'transaksiBS', 'masterSampah'] as $k) if (!isset($d[$k]) || !is_array($d[$k])) return false;
    return true;
}
function findUser(array $data, string $id): ?array {
    foreach ($data['users'] ?? [] as $u) if (is_array($u) && ($u['id'] ?? null) === $id) return $u;
    return null;
}
function publicUser(array $u): array {
    $r = in_array($u['role'] ?? '', ['admin', 'guru'], true) ? $u['role'] : 'siswa';
    return ['id' => $u['id'], 'nama' => (string)($u['nama'] ?? $u['username']), 'username' => $u['username'], 'role' => $r, 'kelas' => (string)($u['kelas'] ?? ''), 'kader' => $r === 'siswa' && !empty($u['kader'])];
}
/* Data akun sendiri: ditambah foto profil (foto tidak ikut dikirim di daftar nama akun lain) */
function meUser(array $u): array { $p = publicUser($u); $p['foto'] = (string)($u['foto'] ?? ''); return $p; }
/* Data untuk non-admin: tanpa hash sandi; daftar nama & kelas dikirim sebagai _dir */
function viewFor(array $data, array $u): array {
    if (($u['role'] ?? '') === 'admin') return $data;
    $dir = [];
    foreach ($data['users'] ?? [] as $x) if (is_array($x)) $dir[] = publicUser($x);
    $data['users'] = [];
    $data['_dir'] = $dir;
    if (($u['role'] ?? '') === 'siswa') { // siswa hanya menerima riwayat tabungannya sendiri
        $data['penarikan'] = array_values(array_filter($data['penarikan'] ?? [], function ($w) use ($u) { return mineP($w, $u); }));
    }
    return $data;
}
/* Tabungan: rumus sama dengan aplikasi (saldo = setoran + penambahan - pengambilan dibayar - pengurangan) */
function mineP($r, array $u): bool {
    if (!is_array($r)) return false;
    if (!empty($r['uid']) && $r['uid'] === ($u['id'] ?? null)) return true;
    $a = lower(trim((string)($r['nama'] ?? '')));
    return $a !== '' && $a === lower(trim((string)($u['nama'] ?? '')));
}
function saldoP(array $data, array $u): array {
    $set = 0.0; $tar = 0.0; $pend = 0.0; $adj = 0.0;
    foreach ($data['transaksiBS'] ?? [] as $t) if (mineP($t, $u)) $set += (float)($t['totalNilai'] ?? 0);
    foreach ($data['penarikan'] ?? [] as $w) {
        if (!mineP($w, $u)) continue;
        $tp = $w['tipe'] ?? 'tarik'; $st = $w['status'] ?? ''; $j = (float)($w['jumlah'] ?? 0);
        if ($tp === 'tarik') { if ($st === 'Dibayar') $tar += $j; elseif ($st === 'Menunggu') $pend += $j; }
        elseif ($st === 'Selesai') { if ($tp === 'tambah') $adj += $j; elseif ($tp === 'kurang') $tar += $j; }
    }
    $s = $set + $adj - $tar;
    return ['saldo' => $s, 'tersedia' => $s - $pend];
}
function likesRead(string $f): array {
    $lk = readJson($f, ['rev' => 0, 'list' => []]);
    if (!isset($lk['list']) || !is_array($lk['list'])) $lk['list'] = [];
    $lk['rev'] = (int)($lk['rev'] ?? 0);
    return $lk;
}
function likesOut(string $f, $lrev, bool $force = false): array {
    $lk = likesRead($f);
    if (!$force && (int)$lrev === $lk['rev']) return [];
    return ['suka' => array_values($lk['list']), 'lrev' => $lk['rev']];
}
function legacyHash(string $s): string { // kompatibel dengan hash lama aplikasi (awalan x:)
    $u = @iconv('UTF-8', 'UTF-16LE', $s);
    $codes = $u === false ? array_map('ord', str_split($s)) : array_values(unpack('v*', $u) ?: []);
    $h = 5381;
    foreach ($codes as $c) $h = ($h * 33 + $c) & 0xFFFFFFFF;
    return 'x:' . dechex($h);
}
function checkPw(array $u, string $pw): bool {
    $hash = (string)($u['hash'] ?? ''); $salt = (string)($u['salt'] ?? '');
    if ($hash === '') return false;
    $calc = strpos($hash, 'x:') === 0 ? legacyHash($salt . ':' . $pw) : hash('sha256', $salt . ':' . $pw);
    return hash_equals($hash, $calc);
}
function newSession(string $SES, array $u): string {
    $s = readJson($SES, []);
    $now = time();
    foreach ($s as $k => $v) if (($v['exp'] ?? 0) < $now) unset($s[$k]);
    $tok = bin2hex(random_bytes(24));
    $s[$tok] = ['uid' => $u['id'], 'exp' => $now + SESSION_DAYS * 86400];
    writeJson($SES, $s);
    return $tok;
}
function auth(string $SES, array $db, $tok): array {
    if (!is_string($tok) || !preg_match('/^[a-f0-9]{48}$/', $tok)) fail(401, 'Sesi tidak valid.');
    $s = readJson($SES, []);
    if (!isset($s[$tok]) || ($s[$tok]['exp'] ?? 0) < time()) fail(401, 'Sesi berakhir.');
    $u = findUser($db['data'], (string)$s[$tok]['uid']);
    if (!$u) fail(401, 'Akun tidak ditemukan.');
    return $u;
}
function backup(string $DIR, array &$db): void {
    if (time() - (int)($db['backupAt'] ?? 0) < BACKUP_EVERY) return;
    $dst = $DIR . '/backup/si-amanah-' . date('Ymd-His') . '-r' . (int)$db['rev'] . '.json.php';
    @file_put_contents($dst, GUARD . json_encode($db, JF));
    $db['backupAt'] = time();
    $files = glob($DIR . '/backup/si-amanah-*.json.php') ?: [];
    sort($files);
    while (count($files) > MAX_BACKUPS) @unlink(array_shift($files));
}
function clientIp(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 64); }

/* ---------- Baca permintaan ---------- */
$action = (string)($_GET['action'] ?? '');
$raw = file_get_contents('php://input');
if ($raw === false) $raw = '';
$in = $raw === '' ? [] : json_decode($raw, true);
if (!is_array($in)) {
    $max = ini_get('post_max_size');
    fail(400, 'Permintaan tidak valid' . ($raw === '' ? ' (ukuran data mungkin melebihi batas server ' . $max . ')' : '') . '.');
}
$db = loadDb($DB);

switch ($action) {
case 'status':
    out(200, ['ok' => true, 'app' => 'si-amanah', 'hasUsers' => count($db['data']['users'] ?? []) > 0, 'wa' => (string)($db['data']['pengaturan']['waOp'] ?? ''), 'waNama' => (string)($db['data']['pengaturan']['namaOp'] ?? ''), 'rev' => (int)$db['rev'], 'time' => time(), 'maxPost' => ini_get('post_max_size')]);

case 'setup':
    if (count($db['data']['users'] ?? []) > 0) fail(409, 'Akun admin sudah dibuat. Silakan masuk.');
    $d = $in['data'] ?? null;
    if (!validData($d) || count($d['users']) !== 1) fail(400, 'Data awal tidak valid.');
    $u = $d['users'][0];
    $un = (string)($in['username'] ?? '');
    if (($u['role'] ?? '') !== 'admin' || ($u['username'] ?? '') !== $un || !checkPw($u, (string)($in['password'] ?? ''))) fail(400, 'Data akun tidak cocok.');
    $d['_epoch'] = $db['epoch']; unset($d['_dir']);
    $d['users'] = normUsers($d['users']);
    $db['data'] = $d; $db['rev'] = (int)$db['rev'] + 1; $db['savedAt'] = time();
    saveDb($DB, $db);
    $tok = newSession($SES, $u);
    out(200, ['ok' => true, 'token' => $tok, 'user' => meUser($u), 'rev' => $db['rev'], 'epoch' => $db['epoch'], 'seen' => 0]);

case 'login':
    $att = readJson($ATT, []); $ip = clientIp(); $now = time();
    foreach ($att as $k => $v) if (($v['t'] ?? 0) < $now - LOCK_MINUTES * 60) unset($att[$k]);
    if (($att[$ip]['n'] ?? 0) >= MAX_FAILS) fail(429, 'Terlalu banyak percobaan masuk. Coba lagi dalam ' . LOCK_MINUTES . ' menit.');
    $un = lower(trim((string)($in['username'] ?? '')));
    $pw = (string)($in['password'] ?? '');
    $u = null;
    foreach ($db['data']['users'] ?? [] as $x) if (is_array($x) && ($x['username'] ?? '') === $un) { $u = $x; break; }
    if (!$u || !checkPw($u, $pw)) {
        $att[$ip] = ['n' => ($att[$ip]['n'] ?? 0) + 1, 't' => $now];
        writeJson($ATT, $att);
        fail(403, 'Username atau kata sandi salah.');
    }
    unset($att[$ip]); writeJson($ATT, $att);
    $role = $u['role'];
    $lbl = ['admin' => 'Admin', 'guru' => 'Guru', 'siswa' => 'Siswa'];
    if (isset($in['role']) && $in['role'] !== $role) fail(403, 'Akun ini terdaftar sebagai ' . $lbl[$role] . '. Pilih peran yang sesuai.');
    $tok = newSession($SES, $u);
    out(200, ['ok' => true, 'token' => $tok, 'user' => meUser($u), 'data' => viewFor($db['data'], $u), 'rev' => (int)$db['rev'], 'epoch' => $db['epoch'], 'seen' => seenOf($NTF, (string)$u['id']), 'clr' => ntOut($NTF, (string)$u['id'])['clr']] + likesOut($LKF, -1, true));

case 'load':
    $u = auth($SES, $db, $in['token'] ?? null);
    if (!is_file($MTF)) writeJson($MTF, ['rev' => (int)$db['rev'], 'epoch' => (string)$db['epoch']]);
    if ((int)($in['rev'] ?? -1) === (int)$db['rev'] && ($in['epoch'] ?? '') === $db['epoch'])
        out(200, ['ok' => true, 'same' => true, 'user' => publicUser($u), 'rev' => (int)$db['rev'], 'epoch' => $db['epoch'], 'seen' => seenOf($NTF, (string)$u['id']), 'clr' => ntOut($NTF, (string)$u['id'])['clr']] + likesOut($LKF, $in['lrev'] ?? -1));
    out(200, ['ok' => true, 'user' => meUser($u), 'data' => viewFor($db['data'], $u), 'rev' => (int)$db['rev'], 'epoch' => $db['epoch'], 'seen' => seenOf($NTF, (string)$u['id']), 'clr' => ntOut($NTF, (string)$u['id'])['clr']] + likesOut($LKF, $in['lrev'] ?? -1));

case 'save':
    $u = auth($SES, $db, $in['token'] ?? null);
    if (!canInput($u)) fail(403, 'Akun ini hanya dapat melihat data.');
    if ((int)($in['rev'] ?? -1) !== (int)$db['rev'] || ($in['epoch'] ?? '') !== $db['epoch'])
        out(409, ['ok' => false, 'error' => 'Data di server lebih baru, sedang digabungkan.', 'data' => viewFor($db['data'], $u), 'rev' => (int)$db['rev'], 'epoch' => $db['epoch']]);
    $d = $in['data'] ?? null;
    if (!validData($d)) fail(400, 'Struktur data tidak valid.');
    unset($d['_dir'], $d['suka']); // suka disimpan terpisah (aksi like)
    if ($u['role'] === 'admin') {
        if (!isset($d['penarikan']) || !is_array($d['penarikan'])) $d['penarikan'] = $db['data']['penarikan'] ?? [];
        $d['users'] = normUsers($d['users']);
        $hasAdmin = false;
        foreach ($d['users'] as $x) if ($x['role'] === 'admin') $hasAdmin = true;
        if (!$hasAdmin) fail(400, 'Minimal harus ada satu akun Admin.');
    } else {
        // Non-admin tidak boleh mengubah akun & profil madrasah
        $d['users'] = $db['data']['users'];
        $d['pengaturan'] = $db['data']['pengaturan'];
        $d['penarikan'] = $db['data']['penarikan'] ?? []; // tabungan hanya diproses admin / lewat aksi request
        $d['sembunyi'] = $db['data']['sembunyi'] ?? [];   // foto tersembunyi hanya diatur admin
        $d['agenda'] = $db['data']['agenda'] ?? [];       // agenda hanya diinput admin
        if ($u['role'] === 'siswa') { // kader: tidak mengubah master harga
            $d['masterSampah'] = $db['data']['masterSampah'] ?? [];
            $d['masterTanaman'] = $db['data']['masterTanaman'] ?? [];
        }
    }
    $d['_epoch'] = $db['epoch'];
    backup($DIR, $db);
    $db['data'] = $d; $db['rev'] = (int)$db['rev'] + 1; $db['savedAt'] = time();
    saveDb($DB, $db);
    out(200, ['ok' => true, 'rev' => $db['rev'], 'epoch' => $db['epoch'], 'savedAt' => $db['savedAt']]);

case 'request': // pengajuan pengambilan tabungan, tetap ditabung, atau pembatalan (semua akun)
    $u = auth($SES, $db, $in['token'] ?? null);
    $tp = (string)($in['tipe'] ?? '');
    if (!isset($db['data']['penarikan']) || !is_array($db['data']['penarikan'])) $db['data']['penarikan'] = [];
    $ms = (int)round(microtime(true) * 1000);
    if ($tp === 'batal') {
        $id = (string)($in['id'] ?? ''); $found = false;
        foreach ($db['data']['penarikan'] as $i => $w) {
            if (is_array($w) && ($w['id'] ?? '') === $id && mineP($w, $u) && ($w['status'] ?? '') === 'Menunggu') {
                $db['data']['penarikan'][$i]['status'] = 'Dibatalkan'; $db['data']['penarikan'][$i]['_u'] = $ms; $found = true;
            }
        }
        if (!$found) fail(404, 'Pengajuan tidak ditemukan atau sudah diproses admin.');
    } elseif ($tp === 'tarik' || $tp === 'simpan') {
        $s = saldoP($db['data'], $u);
        $rec = ['_u' => $ms, 'id' => 'WD-' . $ms . '-' . bin2hex(random_bytes(2)), 'tipe' => $tp, 'uid' => $u['id'],
                'nama' => (string)($u['nama'] ?? $u['username']), 'kelas' => (string)($u['kelas'] ?? ''),
                'tanggal' => date('Y-m-d'), 'waktu' => date('H:i')];
        if ($tp === 'tarik') {
            $j = round((float)($in['jumlah'] ?? 0));
            if ($j <= 0) fail(400, 'Jumlah pengambilan tidak valid.');
            if ($j > $s['tersedia'] + 0.5) fail(400, 'Jumlah melebihi saldo yang dapat diambil (Rp ' . number_format(max(0, $s['tersedia']), 0, ',', '.') . ').');
            $m = (string)($in['metode'] ?? 'Tunai');
            if (!in_array($m, ['Tunai', 'Transfer Bank', 'E-Wallet'], true)) $m = 'Tunai';
            $rk = cut((string)($in['rekening'] ?? ''), 120);
            if ($m !== 'Tunai' && $rk === '') fail(400, 'Isi nomor rekening / e-wallet tujuan.');
            $rec += ['jumlah' => $j, 'metode' => $m, 'rekening' => $rk, 'catatan' => cut((string)($in['catatan'] ?? ''), 200), 'status' => 'Menunggu'];
        } else {
            $rec += ['jumlah' => max(0, round($s['saldo'])), 'metode' => '', 'rekening' => '', 'catatan' => '', 'status' => 'Dicatat'];
        }
        $db['data']['penarikan'][] = $rec;
    } else fail(400, 'Jenis pengajuan tidak dikenal.');
    $db['rev'] = (int)$db['rev'] + 1; $db['savedAt'] = time();
    saveDb($DB, $db);
    out(200, ['ok' => true, 'rev' => $db['rev'], 'epoch' => $db['epoch']]);

case 'like': // suka / batal suka foto galeri (semua akun)
    $u = auth($SES, $db, $in['token'] ?? null);
    $p = (string)($in['p'] ?? '');
    if ($p === '' || strlen($p) > 200) fail(400, 'Foto tidak valid.');
    $lk = likesRead($LKF); $had = false; $keep = [];
    foreach ($lk['list'] as $x) {
        if (!is_array($x)) continue;
        if (($x['p'] ?? '') === $p && ($x['u'] ?? '') === $u['id']) { $had = true; continue; }
        $keep[] = $x;
    }
    if (!$had) $keep[] = ['id' => 'L' . bin2hex(random_bytes(6)), 'p' => $p, 'u' => $u['id'], '_u' => (int)round(microtime(true) * 1000)];
    $lk['list'] = $keep; $lk['rev']++;
    writeJson($LKF, $lk);
    out(200, ['ok' => true, 'liked' => !$had, 'suka' => $keep, 'lrev' => $lk['rev']]);

case 'profile': // ubah profil sendiri: nama, username, kata sandi, foto (semua akun)
    $u = auth($SES, $db, $in['token'] ?? null);
    $ms = (int)round(microtime(true) * 1000);
    $nama = cut((string)($in['nama'] ?? ''), 80);
    $un = lower(trim((string)($in['username'] ?? '')));
    $np = (string)($in['newpw'] ?? '');
    if (function_exists('mb_strlen') ? mb_strlen($nama, 'UTF-8') < 2 : strlen($nama) < 2) fail(400, 'Nama lengkap minimal 2 karakter.');
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $un)) fail(400, 'Username 3-32 karakter: huruf kecil, angka, titik, garis bawah, atau strip.');
    $chgUser = $un !== (string)$u['username'];
    if ($np !== '' && strlen($np) < 6) fail(400, 'Kata sandi baru minimal 6 karakter.');
    if (($chgUser || $np !== '') && !checkPw($u, (string)($in['oldpw'] ?? ''))) fail(403, 'Kata sandi lama salah.');
    foreach ($db['data']['users'] as $x) if (is_array($x) && ($x['id'] ?? '') !== $u['id'] && ($x['username'] ?? '') === $un) fail(409, 'Username sudah dipakai akun lain.');
    $foto = $in['foto'] ?? null; // null = tidak diubah, '' = hapus
    if ($foto !== null) {
        $foto = (string)$foto;
        if ($foto !== '' && (!preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $foto) || strlen($foto) > 400000)) fail(400, 'Foto profil tidak valid atau terlalu besar.');
    }
    $oldKey = lower(trim((string)($u['nama'] ?? '')));
    foreach ($db['data']['users'] as $i => $x) {
        if (!is_array($x) || ($x['id'] ?? '') !== $u['id']) continue;
        $x['nama'] = $nama; $x['username'] = $un; $x['_u'] = $ms;
        if ($np !== '') { $x['salt'] = bin2hex(random_bytes(6)); $x['hash'] = hash('sha256', $x['salt'] . ':' . $np); }
        if ($foto !== null) $x['foto'] = $foto;
        $db['data']['users'][$i] = $x; $u = $x;
    }
    // Ganti nama: riwayat setoran & tabungan atas nama lama ikut diperbarui agar saldo tetap tersambung
    if ($oldKey !== '' && $oldKey !== lower($nama)) {
        foreach (['transaksiBS', 'penarikan'] as $col) {
            if (!isset($db['data'][$col]) || !is_array($db['data'][$col])) continue;
            foreach ($db['data'][$col] as $i => $r) {
                if (!is_array($r)) continue;
                $hit = lower(trim((string)($r['nama'] ?? ''))) === $oldKey || ($col === 'penarikan' && ($r['uid'] ?? '') === $u['id']);
                if ($hit) { $db['data'][$col][$i]['nama'] = $nama; $db['data'][$col][$i]['_u'] = $ms; }
            }
        }
    }
    backup($DIR, $db);
    $db['rev'] = (int)$db['rev'] + 1; $db['savedAt'] = time();
    saveDb($DB, $db);
    out(200, ['ok' => true, 'user' => meUser($u), 'rev' => $db['rev'], 'epoch' => $db['epoch']]);

case 'seen': // tandai notifikasi sudah dibaca (tersimpan di server, berlaku di semua perangkat)
    $u = auth($SES, $db, $in['token'] ?? null);
    $t = (int)($in['t'] ?? 0);
    $now = (int)round(microtime(true) * 1000);
    if ($t <= 0 || $t > $now + 86400000) $t = $now;
    $n = readJson($NTF, []);
    $uid = (string)$u['id']; $chg = false;
    if ($t > (int)($n[$uid] ?? 0)) { $n[$uid] = $t; $chg = true; }
    $c = (int)($in['clr'] ?? 0); // bersihkan pemberitahuan: sembunyikan notifikasi sampai waktu ini
    if ($c > 0) { if ($c > $now + 86400000) $c = $now; if ($c > (int)($n['c_' . $uid] ?? 0)) { $n['c_' . $uid] = $c; $chg = true; } }
    if ($chg) writeJson($NTF, $n);
    out(200, ['ok' => true, 'seen' => (int)($n[$uid] ?? 0), 'clr' => (int)($n['c_' . $uid] ?? 0)]);

case 'reset':
    $u = auth($SES, $db, $in['token'] ?? null);
    if (($u['role'] ?? '') !== 'admin') fail(403, 'Hanya admin yang dapat menghapus semua data.');
    $db['backupAt'] = 0; backup($DIR, $db); // simpan cadangan terakhir sebelum dihapus
    $db = ['rev' => (int)$db['rev'] + 1, 'epoch' => bin2hex(random_bytes(8)), 'data' => emptyData(), 'savedAt' => time(), 'backupAt' => time()];
    saveDb($DB, $db);
    writeJson($SES, []);
    writeJson($LKF, ['rev' => 0, 'list' => []]);
    writeJson($NTF, []);
    out(200, ['ok' => true]);

case 'logout':
    $s = readJson($SES, []);
    $tok = $in['token'] ?? '';
    if (is_string($tok) && isset($s[$tok])) { unset($s[$tok]); writeJson($SES, $s); }
    out(200, ['ok' => true]);

default:
    fail(400, 'Aksi tidak dikenal.');
}
