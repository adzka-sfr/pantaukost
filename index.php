<?php

declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Jakarta');

const UPLOAD_DIR = __DIR__ . '/bukti_pembayaran/';
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

$databaseHost = getenv('DB_HOST') ?: '127.0.0.1';
$databaseName = getenv('DB_NAME') ?: 'pantau_kost';
$databaseUser = getenv('DB_USER') ?: 'root';
$databasePassword = getenv('DB_PASS') ?: '';
$pdo = null;
$databaseError = null;
$pageError = null;

try {
    $pdo = new PDO(
        "mysql:host={$databaseHost};dbname={$databaseName};charset=utf8mb4",
        $databaseUser,
        $databasePassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    $databaseError = 'Database belum terhubung. Pastikan MySQL aktif dan schema.sql sudah diimpor.';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function redirectHome(): never
{
    header('Location: index.php');
    exit;
}

function setFlash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function escape(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatRupiah(int|float|string $amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function namaBulanIndo(DateTimeInterface $date): string
{
    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $months[(int) $date->format('n') - 1];
}

function formatTanggalIndo(DateTimeInterface $date, bool $includeYear = true): string
{
    $formatted = (int) $date->format('j') . ' ' . namaBulanIndo($date);
    return $includeYear ? $formatted . ' ' . $date->format('Y') : $formatted;
}

function storeProof(array $file): ?string
{
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Bukti pembayaran gagal diunggah. Coba pilih file lain.');
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Ukuran bukti maksimal 5 MB.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Format bukti harus JPG, PNG, WEBP, atau PDF.');
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        throw new RuntimeException('Folder bukti pembayaran tidak dapat dibuat.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $fileName)) {
        throw new RuntimeException('Bukti pembayaran tidak dapat disimpan.');
    }

    return $fileName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        setFlash('Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.', 'error');
        redirectHome();
    }
    if (!$pdo) {
        setFlash($databaseError ?? 'Database tidak tersedia.', 'error');
        redirectHome();
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_transaction') {
        $type = (string) ($_POST['transaction_type'] ?? '');
        $description = trim((string) ($_POST['description'] ?? ''));
        $amountInput = trim((string) ($_POST['amount'] ?? ''));
        $transactionDate = (string) ($_POST['transaction_date'] ?? '');
        $proofName = null;

        try {
            if (!in_array($type, ['income', 'expense'], true)) {
                throw new RuntimeException('Jenis transaksi tidak valid.');
            }
            if ($description === '' || mb_strlen($description) > 160) {
                throw new RuntimeException('Isi keterangan transaksi, maksimal 160 karakter.');
            }
            if (!is_numeric($amountInput) || (float) $amountInput <= 0 || (float) $amountInput > 999999999999) {
                throw new RuntimeException('Masukkan nominal lebih dari nol dengan angka yang valid.');
            }
            $date = DateTime::createFromFormat('!Y-m-d', $transactionDate);
            if (!$date || $date->format('Y-m-d') !== $transactionDate) {
                throw new RuntimeException('Tanggal transaksi tidak valid.');
            }
            if ($type === 'income' && !empty($_FILES['proof']['name'])) {
                throw new RuntimeException('Bukti pembayaran hanya dilampirkan pada pengeluaran.');
            }

            if ($type === 'expense' && isset($_FILES['proof'])) {
                $proofName = storeProof($_FILES['proof']);
            }

            $statement = $pdo->prepare(
                'INSERT INTO transactions (transaction_type, description, amount, transaction_date, proof_file)
				 VALUES (:type, :description, :amount, :transaction_date, :proof_file)'
            );
            $statement->execute([
                'type' => $type,
                'description' => $description,
                'amount' => $amountInput,
                'transaction_date' => $transactionDate,
                'proof_file' => $proofName,
            ]);

            setFlash($type === 'income' ? 'Dana masuk berhasil dicatat.' : 'Pengeluaran berhasil dicatat.');
        } catch (Throwable $exception) {
            if ($proofName !== null && is_file(UPLOAD_DIR . $proofName)) {
                unlink(UPLOAD_DIR . $proofName);
            }
            setFlash($exception instanceof RuntimeException ? $exception->getMessage() : 'Transaksi gagal disimpan. Periksa kembali koneksi database.', 'error');
        }
        redirectHome();
    }

    if ($action === 'delete_transaction') {
        $transactionId = filter_var($_POST['transaction_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$transactionId || $transactionId < 1) {
            setFlash('Transaksi tidak ditemukan.', 'error');
            redirectHome();
        }

        try {
            $statement = $pdo->prepare('SELECT proof_file FROM transactions WHERE id = :id');
            $statement->execute(['id' => $transactionId]);
            $transaction = $statement->fetch();
            if (!$transaction) {
                throw new RuntimeException('Transaksi tidak ditemukan.');
            }

            $statement = $pdo->prepare('DELETE FROM transactions WHERE id = :id');
            $statement->execute(['id' => $transactionId]);
            if ($transaction['proof_file'] && is_file(UPLOAD_DIR . basename($transaction['proof_file']))) {
                unlink(UPLOAD_DIR . basename($transaction['proof_file']));
            }
            setFlash('Transaksi berhasil dihapus.');
        } catch (Throwable $exception) {
            setFlash($exception instanceof RuntimeException ? $exception->getMessage() : 'Transaksi gagal dihapus.', 'error');
        }
        redirectHome();
    }

    setFlash('Aksi tidak dikenali.', 'error');
    redirectHome();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$balance = 0;
$monthIncome = 0;
$monthExpense = 0;
$transactions = [];
$allTimeIncome = 0;
$allTimeExpense = 0;

if ($pdo) {
    try {
        $totals = $pdo->query(
            "SELECT
				COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) AS total_income,
				COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense,
				COALESCE(SUM(CASE WHEN transaction_type = 'income' AND DATE_FORMAT(transaction_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN amount ELSE 0 END), 0) AS month_income,
				COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND DATE_FORMAT(transaction_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN amount ELSE 0 END), 0) AS month_expense
			 FROM transactions"
        )->fetch();
        $allTimeIncome = (float) $totals['total_income'];
        $allTimeExpense = (float) $totals['total_expense'];
        $balance = $allTimeIncome - $allTimeExpense;
        $monthIncome = (float) $totals['month_income'];
        $monthExpense = (float) $totals['month_expense'];
        $transactions = $pdo->query(
            'SELECT id, transaction_type, description, amount, transaction_date, proof_file
			 FROM transactions ORDER BY transaction_date DESC, id DESC LIMIT 100'
        )->fetchAll();
    } catch (PDOException $exception) {
        $pageError = 'Tabel transaksi belum tersedia. Impor schema.sql melalui phpMyAdmin.';
    }
}

$monthLabel = namaBulanIndo(new DateTimeImmutable()) . ' ' . date('Y');
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f6f7f2">
    <title>PantauKost - Keuangan Pembangunan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            color-scheme: light;
            --ink: #1d3029;
            --muted: #748078;
            --paper: #f6f7f2;
            --surface: #fff;
            --line: #e6e9e2;
            --green: #245c45;
            --green-dark: #193e31;
            --mint: #e4f0e8;
            --coral: #d9775d;
            --coral-pale: #fbede8;
            --shadow: 0 16px 42px rgba(29, 48, 41, .09);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--paper);
            color: var(--ink);
            font-family: 'DM Sans', sans-serif;
            font-size: 15px;
            -webkit-font-smoothing: antialiased;
        }

        button,
        input {
            font: inherit;
        }

        button {
            cursor: pointer;
        }

        .shell {
            max-width: 1080px;
            margin: 0 auto;
            padding: 30px 28px 54px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 30px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            border-radius: 13px;
            color: #fff;
            background: var(--green);
        }

        .brand-mark svg {
            width: 21px;
            height: 21px;
        }

        .brand-name {
            font: 800 17px 'Manrope', sans-serif;
            letter-spacing: 0;
        }

        .brand-caption {
            margin-top: 2px;
            color: var(--muted);
            font-size: 12px;
        }

        .top-date {
            color: var(--muted);
            font-size: 13px;
        }

        .intro {
            margin-bottom: 22px;
        }

        .eyebrow {
            color: var(--green);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.4px;
        }

        h1 {
            margin: 7px 0 0;
            font: 700 25px 'Manrope', sans-serif;
            letter-spacing: 0;
        }

        .dashboard {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(280px, .9fr);
            gap: 17px;
            align-items: stretch;
        }

        .balance-panel {
            position: relative;
            overflow: hidden;
            min-height: 235px;
            padding: 26px 29px;
            color: white;
            background: var(--green-dark);
            border-radius: 16px;
        }

        .balance-panel::after {
            position: absolute;
            content: '';
            width: 255px;
            height: 255px;
            right: -80px;
            bottom: -155px;
            border: 1px solid rgba(255, 255, 255, .13);
            border-radius: 50%;
            box-shadow: 0 0 0 28px rgba(255, 255, 255, .035), 0 0 0 58px rgba(255, 255, 255, .025);
        }

        .balance-label {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #c5d6cd;
            font-size: 13px;
        }

        .balance-label svg {
            width: 16px;
        }

        .balance-value {
            position: relative;
            z-index: 1;
            margin: 16px 0 21px;
            font: 800 clamp(29px, 5vw, 42px) 'Manrope', sans-serif;
            letter-spacing: 0;
            overflow-wrap: anywhere;
        }

        .balance-foot {
            position: relative;
            z-index: 1;
            display: flex;
            gap: 20px;
            color: #c5d6cd;
            font-size: 12px;
        }

        .balance-foot strong {
            display: block;
            margin-top: 5px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
        }

        .actions {
            display: flex;
            gap: 9px;
            margin-top: 22px;
            position: relative;
            z-index: 1;
        }

        .button {
            display: inline-flex;
            min-height: 42px;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0 15px;
            border: 0;
            border-radius: 9px;
            font-weight: 700;
            font-size: 13px;
            transition: transform .18s ease, background .18s ease;
        }

        .button:active {
            transform: translateY(1px);
        }

        .button svg {
            width: 16px;
            height: 16px;
        }

        .button-light {
            color: var(--green-dark);
            background: #fff;
        }

        .button-light:hover {
            background: #eaf3ed;
        }

        .button-soft {
            color: #fff;
            background: rgba(255, 255, 255, .13);
        }

        .button-soft:hover {
            background: rgba(255, 255, 255, .2);
        }

        .month-panel {
            padding: 23px 23px 18px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--surface);
        }

        .month-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .month-title {
            font: 700 15px 'Manrope', sans-serif;
        }

        .month-pill {
            padding: 5px 9px;
            border-radius: 99px;
            color: var(--green);
            background: var(--mint);
            font-size: 11px;
            font-weight: 700;
        }

        .month-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 0;
            border-bottom: 1px solid var(--line);
        }

        .month-item:last-child {
            padding-bottom: 2px;
            border-bottom: 0;
        }

        .month-label {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 13px;
        }

        .metric-icon {
            width: 31px;
            height: 31px;
            display: grid;
            place-items: center;
            border-radius: 10px;
        }

        .metric-icon svg {
            width: 15px;
        }

        .metric-in {
            color: var(--green);
            background: var(--mint);
        }

        .metric-out {
            color: var(--coral);
            background: var(--coral-pale);
        }

        .month-value {
            font-weight: 700;
            font-size: 13px;
            text-align: right;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 14px;
            margin: 34px 0 14px;
        }

        h2 {
            margin: 0;
            font: 700 18px 'Manrope', sans-serif;
            letter-spacing: 0;
        }

        .section-note {
            margin-top: 4px;
            color: var(--muted);
            font-size: 12px;
        }

        .filter-wrap {
            position: relative;
            width: min(250px, 44vw);
        }

        .filter-wrap svg {
            position: absolute;
            top: 50%;
            left: 12px;
            width: 16px;
            color: #849087;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .filter-input {
            width: 100%;
            height: 40px;
            padding: 0 12px 0 37px;
            border: 1px solid var(--line);
            border-radius: 9px;
            outline: none;
            background: #fff;
            color: var(--ink);
            font-size: 13px;
        }

        .filter-input:focus,
        .form-control:focus {
            border-color: #6a9c7d;
            box-shadow: 0 0 0 3px rgba(70, 127, 89, .12);
        }

        .transaction-list {
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 13px;
            background: #fff;
        }

        .list-head,
        .transaction-row {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(110px, .7fr) minmax(140px, .85fr) 42px;
            align-items: center;
            gap: 14px;
            padding: 13px 18px;
        }

        .list-head {
            color: #89928b;
            background: #fbfcf9;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .transaction-row {
            min-height: 67px;
            border-top: 1px solid #eff1ed;
        }

        .transaction-main {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .transaction-icon {
            width: 36px;
            height: 36px;
            display: grid;
            flex: 0 0 auto;
            place-items: center;
            border-radius: 11px;
        }

        .transaction-icon svg {
            width: 17px;
        }

        .transaction-name {
            overflow: hidden;
            font-size: 13px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .transaction-sub {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-top: 4px;
            color: var(--muted);
            font-size: 11px;
        }

        .transaction-kind {
            color: var(--muted);
            font-size: 12px;
        }

        .transaction-amount {
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .amount-in {
            color: var(--green);
        }

        .amount-out {
            color: #bf634a;
        }

        .row-menu {
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            border: 0;
            border-radius: 8px;
            color: #78847b;
            background: transparent;
        }

        .row-menu:hover {
            color: #a94334;
            background: #fbefec;
        }

        .row-menu svg {
            width: 16px;
        }

        .proof-link {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: var(--green);
            text-decoration: none;
            font-weight: 600;
        }

        .proof-link svg {
            width: 12px;
            height: 12px;
        }

        .empty-state {
            padding: 46px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 46px;
            height: 46px;
            display: grid;
            place-items: center;
            margin: 0 auto 13px;
            border-radius: 14px;
            color: var(--green);
            background: var(--mint);
        }

        .empty-icon svg {
            width: 22px;
        }

        .empty-title {
            font-weight: 700;
        }

        .empty-copy {
            margin-top: 5px;
            color: var(--muted);
            font-size: 12px;
        }

        .notice {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 13px 15px;
            margin-bottom: 16px;
            border: 1px solid #f0c7bb;
            border-radius: 10px;
            color: #843f30;
            background: #fff3ef;
            font-size: 13px;
        }

        .notice svg {
            width: 17px;
            flex: 0 0 auto;
        }

        .flash {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 12px 14px;
            margin-bottom: 17px;
            border-radius: 9px;
            font-size: 13px;
        }

        .flash svg {
            width: 17px;
        }

        .flash.success {
            color: #245c45;
            background: #e4f0e8;
        }

        .flash.error {
            color: #963f30;
            background: #fbede8;
        }

        dialog {
            width: min(460px, calc(100vw - 28px));
            max-height: min(90vh, 760px);
            padding: 0;
            overflow: auto;
            border: 0;
            border-radius: 16px;
            color: var(--ink);
            background: #fff;
            box-shadow: var(--shadow);
        }

        dialog::backdrop {
            background: rgba(20, 34, 28, .48);
            backdrop-filter: blur(3px);
        }

        .modal-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 22px 22px 15px;
        }

        .modal-title {
            font: 700 19px 'Manrope', sans-serif;
        }

        .modal-desc {
            margin-top: 4px;
            color: var(--muted);
            font-size: 12px;
        }

        .icon-close {
            width: 34px;
            height: 34px;
            display: grid;
            place-items: center;
            border: 0;
            border-radius: 9px;
            color: #6f7c72;
            background: #f2f4ef;
        }

        .icon-close svg {
            width: 17px;
        }

        .form-body {
            padding: 0 22px 22px;
        }

        .type-switch {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5px;
            padding: 4px;
            margin: 2px 0 18px;
            border-radius: 10px;
            background: #f1f3ef;
        }

        .type-option {
            min-height: 39px;
            border: 0;
            border-radius: 7px;
            color: var(--muted);
            background: transparent;
            font-size: 13px;
            font-weight: 700;
        }

        .type-option[aria-pressed="true"] {
            color: var(--green-dark);
            background: #fff;
            box-shadow: 0 1px 5px rgba(30, 45, 37, .1);
        }

        .field {
            margin-bottom: 15px;
        }

        .field label {
            display: block;
            margin-bottom: 7px;
            font-size: 12px;
            font-weight: 700;
        }

        .form-control {
            width: 100%;
            min-height: 44px;
            padding: 10px 12px;
            border: 1px solid #dfe4dc;
            border-radius: 8px;
            outline: none;
            color: var(--ink);
            background: #fff;
            font-size: 14px;
        }

        .form-control[type="file"] {
            padding: 9px;
            font-size: 12px;
        }

        .field-hint {
            margin-top: 5px;
            color: var(--muted);
            font-size: 11px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 9px;
            padding-top: 5px;
        }

        .button-muted {
            color: #536057;
            background: #f0f2ee;
        }

        .button-primary {
            color: #fff;
            background: var(--green);
        }

        .button-primary:hover {
            background: #1b4a36;
        }

        .button-danger {
            color: #fff;
            background: #b94d3b;
        }

        .confirm-copy {
            padding: 0 22px 18px;
            color: var(--muted);
            line-height: 1.6;
            font-size: 13px;
        }

        .confirm-form {
            display: flex;
            justify-content: flex-end;
            gap: 9px;
            padding: 0 22px 22px;
        }

        .mobile-actions {
            display: none;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        @media (max-width: 720px) {
            .shell {
                padding: 21px 17px calc(100px + env(safe-area-inset-bottom));
            }

            .topbar {
                margin-bottom: 25px;
            }

            .top-date {
                font-size: 11px;
            }

            h1 {
                font-size: 22px;
            }

            .dashboard {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .balance-panel {
                min-height: 219px;
                padding: 23px 21px;
            }

            .balance-value {
                margin: 15px 0 17px;
                font-size: clamp(29px, 8vw, 39px);
            }

            .actions {
                display: none;
            }

            .month-panel {
                padding: 19px 19px 14px;
            }

            .month-item {
                padding: 13px 0;
            }

            .section-head {
                align-items: center;
                margin: 28px 0 12px;
            }

            h2 {
                font-size: 17px;
            }

            .section-note {
                font-size: 11px;
            }

            .filter-wrap {
                width: 43px;
                flex: 0 0 auto;
                transition: width .2s ease;
            }

            .filter-wrap:focus-within {
                width: min(200px, 48vw);
            }

            .filter-input {
                padding-right: 8px;
                color: transparent;
            }

            .filter-wrap:focus-within .filter-input {
                padding-left: 35px;
                color: var(--ink);
            }

            .list-head {
                display: none;
            }

            .transaction-row {
                grid-template-columns: minmax(0, 1fr) auto 34px;
                gap: 8px;
                padding: 13px 12px;
            }

            .transaction-kind {
                display: none;
            }

            .transaction-main {
                gap: 9px;
            }

            .transaction-icon {
                width: 34px;
                height: 34px;
            }

            .transaction-name {
                max-width: 52vw;
                font-size: 12px;
            }

            .transaction-sub {
                font-size: 10px;
            }

            .transaction-amount {
                font-size: 11px;
            }

            .mobile-actions {
                position: fixed;
                z-index: 5;
                right: 0;
                bottom: 0;
                left: 0;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                padding: 12px 17px calc(12px + env(safe-area-inset-bottom));
                border-top: 1px solid var(--line);
                background: rgba(255, 255, 255, .96);
                backdrop-filter: blur(12px);
            }

            .mobile-actions .button {
                min-height: 46px;
            }
        }

        @media (max-width: 380px) {
            .shell {
                padding-right: 13px;
                padding-left: 13px;
            }

            .balance-panel {
                padding-right: 17px;
                padding-left: 17px;
            }

            .balance-foot {
                gap: 12px;
            }

            .balance-foot strong {
                font-size: 12px;
            }

            .brand-caption {
                font-size: 11px;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body>
    <main class="shell">
        <header class="topbar">
            <div class="brand">
                <div class="brand-mark"><i data-lucide="house"></i></div>
                <div>
                    <div class="brand-name">PantauKost</div>
                    <div class="brand-caption">Keuangan pembangunan</div>
                </div>
            </div>
            <div class="top-date"><?= escape($monthLabel) ?></div>
        </header>

        <?php if ($flash): ?>
            <div class="flash <?= escape($flash['kind']) ?>" role="status">
                <i data-lucide="<?= $flash['kind'] === 'error' ? 'circle-alert' : 'circle-check' ?>"></i>
                <span><?= escape($flash['message']) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($databaseError || $pageError): ?>
            <div class="notice" role="alert"><i data-lucide="circle-alert"></i><span><?= escape($pageError ?? $databaseError) ?></span></div>
        <?php endif; ?>

        <section class="intro">
            <div class="eyebrow">Ringkasan proyek</div>
            <h1>Uang pembangunan, selalu terpantau.</h1>
        </section>

        <section class="dashboard" aria-label="Ringkasan keuangan">
            <div class="balance-panel">
                <div class="balance-label"><i data-lucide="wallet"></i> Saldo tersedia</div>
                <div class="balance-value"><?= formatRupiah($balance) ?></div>
                <div class="balance-foot">
                    <div>Total dana masuk<strong><?= formatRupiah($allTimeIncome) ?></strong></div>
                    <div>Total pengeluaran<strong><?= formatRupiah($allTimeExpense) ?></strong></div>
                </div>
                <div class="actions">
                    <button class="button button-light" type="button" data-open-form="income"><i data-lucide="plus"></i>Catat dana masuk</button>
                    <button class="button button-soft" type="button" data-open-form="expense"><i data-lucide="arrow-up-right"></i>Catat pengeluaran</button>
                </div>
            </div>
            <div class="month-panel">
                <div class="month-head">
                    <div class="month-title">Bulan ini</div><span class="month-pill"><?= escape($monthLabel) ?></span>
                </div>
                <div class="month-item">
                    <div class="month-label"><span class="metric-icon metric-in"><i data-lucide="arrow-down-left"></i></span>Dana masuk</div>
                    <div class="month-value"><?= formatRupiah($monthIncome) ?></div>
                </div>
                <div class="month-item">
                    <div class="month-label"><span class="metric-icon metric-out"><i data-lucide="arrow-up-right"></i></span>Pengeluaran</div>
                    <div class="month-value"><?= formatRupiah($monthExpense) ?></div>
                </div>
            </div>
        </section>

        <section aria-labelledby="transactions-heading">
            <div class="section-head">
                <div>
                    <h2 id="transactions-heading">Riwayat transaksi</h2>
                    <div class="section-note">Transaksi terbaru · maks. 100 catatan</div>
                </div>
                <label class="filter-wrap"><i data-lucide="search"></i><span class="sr-only">Cari transaksi</span><input id="transaction-search" class="filter-input" type="search" placeholder="Cari transaksi" autocomplete="off"></label>
            </div>
            <div class="transaction-list" id="transaction-list">
                <div class="list-head"><span>Transaksi</span><span>Jenis</span><span>Nominal</span><span></span></div>
                <?php if (!$transactions): ?>
                    <div class="empty-state" id="empty-state">
                        <div class="empty-icon"><i data-lucide="receipt-text"></i></div>
                        <div class="empty-title">Belum ada transaksi</div>
                        <div class="empty-copy">Catat dana pertama untuk mulai memantau proyek.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($transactions as $transaction): ?>
                        <?php $isIncome = $transaction['transaction_type'] === 'income'; ?>
                        <article class="transaction-row" data-search="<?= escape(mb_strtolower($transaction['description'] . ' ' . ($isIncome ? 'dana masuk pemasukan' : 'pengeluaran biaya') . ' ' . $transaction['transaction_date'])) ?>">
                            <div class="transaction-main">
                                <span class="transaction-icon <?= $isIncome ? 'metric-in' : 'metric-out' ?>"><i data-lucide="<?= $isIncome ? 'arrow-down-left' : 'hammer' ?>"></i></span>
                                <div style="min-width:0">
                                    <div class="transaction-name"><?= escape($transaction['description']) ?></div>
                                    <div class="transaction-sub">
                                        <time datetime="<?= escape($transaction['transaction_date']) ?>"><?= escape(formatTanggalIndo(new DateTimeImmutable($transaction['transaction_date']))) ?></time>
                                        <?php if ($transaction['proof_file']): ?>
                                            <span>·</span><a class="proof-link" href="bukti_pembayaran/<?= rawurlencode(basename($transaction['proof_file'])) ?>" target="_blank" rel="noopener"><i data-lucide="paperclip"></i>Bukti</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="transaction-kind"><?= $isIncome ? 'Dana masuk' : 'Pengeluaran' ?></div>
                            <div class="transaction-amount <?= $isIncome ? 'amount-in' : 'amount-out' ?>"><?= $isIncome ? '+' : '−' ?> <?= formatRupiah($transaction['amount']) ?></div>
                            <button class="row-menu" type="button" aria-label="Hapus <?= escape($transaction['description']) ?>" data-delete-id="<?= (int) $transaction['id'] ?>" data-delete-name="<?= escape($transaction['description']) ?>"><i data-lucide="trash-2"></i></button>
                        </article>
                    <?php endforeach; ?>
                    <div class="empty-state" id="no-results" hidden>
                        <div class="empty-title">Transaksi tidak ditemukan</div>
                        <div class="empty-copy">Coba kata kunci yang berbeda.</div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <div class="mobile-actions" aria-label="Tambah transaksi">
        <button class="button button-muted" type="button" data-open-form="income"><i data-lucide="plus"></i>Dana masuk</button>
        <button class="button button-primary" type="button" data-open-form="expense"><i data-lucide="arrow-up-right"></i>Pengeluaran</button>
    </div>

    <dialog id="transaction-dialog" aria-labelledby="form-title">
        <div class="modal-head">
            <div>
                <div class="modal-title" id="form-title">Catat transaksi</div>
                <div class="modal-desc">Simpan setiap pergerakan dana proyek.</div>
            </div><button class="icon-close" type="button" data-close-dialog aria-label="Tutup"><i data-lucide="x"></i></button>
        </div>
        <form class="form-body" method="post" enctype="multipart/form-data" id="transaction-form">
            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_transaction">
            <input type="hidden" name="transaction_type" id="transaction-type" value="expense">
            <div class="type-switch" role="group" aria-label="Jenis transaksi">
                <button type="button" class="type-option" data-type="income" aria-pressed="false">Dana masuk</button>
                <button type="button" class="type-option" data-type="expense" aria-pressed="true">Pengeluaran</button>
            </div>
            <div class="field"><label for="description">Keterangan</label><input class="form-control" id="description" name="description" type="text" maxlength="160" placeholder="Contoh: Pembelian semen" required></div>
            <div class="field"><label for="amount">Nominal</label><input class="form-control" id="amount" name="amount" type="number" min="1" max="999999999999" step="1" inputmode="numeric" placeholder="Contoh: 1500000" required></div>
            <div class="field"><label for="transaction-date">Tanggal</label><input class="form-control" id="transaction-date" name="transaction_date" type="date" value="<?= escape($today) ?>" required></div>
            <div class="field" id="proof-field"><label for="proof">Bukti pembayaran <span style="color:var(--muted);font-weight:400">(opsional)</span></label><input class="form-control" id="proof" name="proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf">
                <div class="field-hint">JPG, PNG, WEBP atau PDF. Maksimal 5 MB.</div>
            </div>
            <div class="modal-actions"><button class="button button-muted" type="button" data-close-dialog>Batal</button><button class="button button-primary" type="submit"><i data-lucide="check"></i>Simpan transaksi</button></div>
        </form>
    </dialog>

    <dialog id="delete-dialog" aria-labelledby="delete-title">
        <div class="modal-head">
            <div>
                <div class="modal-title" id="delete-title">Hapus transaksi?</div>
            </div><button class="icon-close" type="button" data-close-dialog aria-label="Tutup"><i data-lucide="x"></i></button>
        </div>
        <div class="confirm-copy">Catatan <strong id="delete-name"></strong> dan bukti pembayarannya akan dihapus permanen.</div>
        <form class="confirm-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete_transaction"><input type="hidden" name="transaction_id" id="delete-id">
            <button class="button button-muted" type="button" data-close-dialog>Batal</button><button class="button button-danger" type="submit"><i data-lucide="trash-2"></i>Hapus</button>
        </form>
    </dialog>

    <script>
        const formDialog = document.querySelector('#transaction-dialog');
        const deleteDialog = document.querySelector('#delete-dialog');
        const typeInput = document.querySelector('#transaction-type');
        const proofField = document.querySelector('#proof-field');
        const proofInput = document.querySelector('#proof');

        function setTransactionType(type) {
            typeInput.value = type;
            document.querySelectorAll('.type-option').forEach((button) => {
                button.setAttribute('aria-pressed', String(button.dataset.type === type));
            });
            proofField.hidden = type !== 'expense';
            proofInput.disabled = type !== 'expense';
            document.querySelector('#form-title').textContent = type === 'income' ? 'Catat dana masuk' : 'Catat pengeluaran';
        }

        document.querySelectorAll('[data-open-form]').forEach((button) => {
            button.addEventListener('click', () => {
                setTransactionType(button.dataset.openForm);
                formDialog.showModal();
                document.querySelector('#description').focus();
            });
        });
        document.querySelectorAll('.type-option').forEach((button) => button.addEventListener('click', () => setTransactionType(button.dataset.type)));
        document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
        document.querySelectorAll('[data-delete-id]').forEach((button) => {
            button.addEventListener('click', () => {
                document.querySelector('#delete-id').value = button.dataset.deleteId;
                document.querySelector('#delete-name').textContent = `“${button.dataset.deleteName}”`;
                deleteDialog.showModal();
            });
        });
        document.querySelectorAll('dialog').forEach((dialog) => {
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close();
            });
        });
        const searchInput = document.querySelector('#transaction-search');
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim().toLocaleLowerCase('id');
            const rows = [...document.querySelectorAll('.transaction-row')];
            let visibleCount = 0;
            rows.forEach((row) => {
                const matches = row.dataset.search.includes(query);
                row.hidden = !matches;
                if (matches) visibleCount += 1;
            });
            const noResults = document.querySelector('#no-results');
            if (noResults) noResults.hidden = visibleCount !== 0 || rows.length === 0;
        });
        if (window.lucide) lucide.createIcons();
    </script>
</body>

</html>