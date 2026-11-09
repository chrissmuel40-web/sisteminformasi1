<?php
header('Content-Type: application/json; charset=utf-8');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'path' => '/'
]);
session_start();

function respond($statusCode, array $body)
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function readAccounts($file, array $default = [])
{
    if (!file_exists($file)) {
        return $default;
    }

    $decoded = json_decode(file_get_contents($file), true);
    if (!is_array($decoded)) {
        respond(500, ['status' => 'error', 'message' => 'Penyimpanan akun tidak dapat dibaca.']);
    }

    return $decoded;
}

function writeAccounts($file, array $accounts)
{
    $json = json_encode($accounts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
        respond(500, ['status' => 'error', 'message' => 'Perubahan akun gagal disimpan.']);
    }
}

function readStudentAccounts($file)
{
    $accounts = readAccounts($file);
    if (!$accounts) {
        $accounts = [[
            'nim' => 'i431251288',
            'nama' => 'Zebua',
            'aliases' => ['i43251288'],
            'legacyCaseInsensitivePassword' => true,
            'passwordHash' => password_hash('zebua', PASSWORD_DEFAULT)
        ]];
        writeAccounts($file, $accounts);
    }

    return $accounts;
}

function requireAdmin()
{
    if (($_SESSION['role'] ?? '') !== 'admin') {
        respond(403, ['status' => 'error', 'message' => 'Akses admin diperlukan.']);
    }
}

$studentFile = __DIR__ . '/akun_mahasiswa.json';
$adminFile = __DIR__ . '/akun_admin.json';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'session') {
        if (!isset($_SESSION['role'])) {
            respond(200, ['status' => 'success', 'authenticated' => false]);
        }

        respond(200, [
            'status' => 'success',
            'authenticated' => true,
            'role' => $_SESSION['role'],
            'name' => $_SESSION['name'] ?? '',
            'nim' => $_SESSION['nim'] ?? ''
        ]);
    }

    if ($action === 'accounts') {
        requireAdmin();
        $accounts = readStudentAccounts($studentFile);
        $publicAccounts = array_map(function ($account) {
            return ['nim' => $account['nim'], 'nama' => $account['nama']];
        }, $accounts);

        respond(200, ['status' => 'success', 'accounts' => $publicAccounts]);
    }

    respond(400, ['status' => 'error', 'message' => 'Aksi tidak dikenali.']);
}

if ($method !== 'POST') {
    respond(405, ['status' => 'error', 'message' => 'Metode tidak diizinkan.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['status' => 'error', 'message' => 'Format data tidak valid.']);
}

$action = $input['action'] ?? '';

if ($action === 'login') {
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $role = ($input['role'] ?? '') === 'admin' ? 'admin' : 'student';

    if ($username === '' || $password === '') {
        respond(400, ['status' => 'error', 'message' => 'NIM/username dan kata sandi wajib diisi.']);
    }

    if ($role === 'admin') {
        $admins = readAccounts($adminFile, ['users' => []]);
        $admin = null;
        foreach ($admins['users'] ?? [] as $candidate) {
            if (strtolower($candidate['username'] ?? '') === strtolower($username)) {
                $admin = $candidate;
                break;
            }
        }

        if (!$admin || !password_verify($password, $admin['passwordHash'] ?? '')) {
            respond(401, ['status' => 'error', 'message' => 'Username atau kata sandi admin tidak sesuai.']);
        }

        session_regenerate_id(true);
        $_SESSION['role'] = 'admin';
        $_SESSION['name'] = $admin['username'];
        unset($_SESSION['nim']);
        respond(200, ['status' => 'success', 'role' => 'admin', 'name' => $admin['username']]);
    }

    $accounts = readStudentAccounts($studentFile);
    $student = null;
    foreach ($accounts as $candidate) {
        $identifiers = array_merge(
            [strtolower((string) ($candidate['nim'] ?? ''))],
            array_map('strtolower', $candidate['aliases'] ?? [])
        );
        if (in_array(strtolower($username), $identifiers, true)) {
            $student = $candidate;
            break;
        }
    }

    $passwordToVerify = !empty($student['legacyCaseInsensitivePassword']) ? strtolower($password) : $password;
    if (!$student || !password_verify($passwordToVerify, $student['passwordHash'] ?? '')) {
        respond(401, ['status' => 'error', 'message' => 'NIM atau kata sandi tidak sesuai.']);
    }

    session_regenerate_id(true);
    $_SESSION['role'] = 'student';
    $_SESSION['name'] = $student['nama'];
    $_SESSION['nim'] = $student['nim'];
    respond(200, ['status' => 'success', 'role' => 'student', 'name' => $student['nama'], 'nim' => $student['nim']]);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
    }
    session_destroy();
    respond(200, ['status' => 'success']);
}

requireAdmin();
$accounts = readStudentAccounts($studentFile);

if ($action === 'create' || $action === 'update') {
    $nim = trim((string) ($input['nim'] ?? ''));
    $name = trim((string) ($input['nama'] ?? ''));
    $password = (string) ($input['password'] ?? '');

    if ($nim === '' || strlen($nim) > 50 || $name === '' || strlen($name) > 120) {
        respond(400, ['status' => 'error', 'message' => 'NIM dan nama wajib diisi dengan panjang yang valid.']);
    }

    foreach ($accounts as $account) {
        if (strcasecmp((string) ($account['nim'] ?? ''), $nim) === 0 && ($action === 'create' || $account['nim'] !== ($input['originalNim'] ?? ''))) {
            respond(409, ['status' => 'error', 'message' => 'NIM tersebut sudah digunakan akun lain.']);
        }
    }

    if ($action === 'create') {
        if (strlen($password) < 8) {
            respond(400, ['status' => 'error', 'message' => 'Kata sandi akun baru minimal 8 karakter.']);
        }

        $accounts[] = [
            'nim' => $nim,
            'nama' => $name,
            'aliases' => [],
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT)
        ];
    } else {
        $originalNim = trim((string) ($input['originalNim'] ?? ''));
        $accountIndex = null;
        foreach ($accounts as $index => $account) {
            if (($account['nim'] ?? '') === $originalNim) {
                $accountIndex = $index;
                break;
            }
        }
        if ($accountIndex === null) {
            respond(404, ['status' => 'error', 'message' => 'Akun mahasiswa tidak ditemukan.']);
        }

        $accounts[$accountIndex]['nim'] = $nim;
        $accounts[$accountIndex]['nama'] = $name;
        if ($password !== '') {
            if (strlen($password) < 8) {
                respond(400, ['status' => 'error', 'message' => 'Kata sandi baru minimal 8 karakter.']);
            }
            $accounts[$accountIndex]['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
            unset($accounts[$accountIndex]['legacyCaseInsensitivePassword']);
        }
    }

    writeAccounts($studentFile, $accounts);
    respond(200, ['status' => 'success', 'message' => 'Akun mahasiswa berhasil disimpan.']);
}

respond(400, ['status' => 'error', 'message' => 'Aksi tidak dikenali.']);
