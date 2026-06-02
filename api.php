<?php
// ── CORS & headers ─────────────────────────────────────────────────────────
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Sembunyikan semua warning/notice PHP agar tidak mencemari output JSON.
error_reporting(0);
ini_set('display_errors', '0');

// Pre-flight untuk browser
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// ── Database connection ────────────────────────────────────────────────────
$host    = "localhost"; // default
$port    = "5432"; // default
$db_name = "<schema>"; // sesuaikan dengan nama schema dalam SQL, PASTIKAN NAMA FULL LOWERCASE
$db_user = "postgres"; // default
$db_pass = "<password>"; // Sesuaikan dengan password PostgreSQL Anda

try {
    $conn = new PDO("pgsql:host=$host;port=$port;dbname=$db_name", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Koneksi database gagal: " . $e->getMessage()]);
    exit();
}

// ── Helpers ────────────────────────────────────────────────────────────────
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$body   = json_decode(file_get_contents("php://input"));

/**
 * Verifikasi password: mendukung plain-text (untuk data demo yang sudah ada)
 * dan password_hash PHP (untuk akun yang diperbarui lewat sistem).
 */
function verifyPassword(string $input, string $stored): bool {
    // Coba verifikasi sebagai bcrypt/password_hash terlebih dahulu
    if (password_verify($input, $stored)) return true;
    // Fallback: perbandingan plain-text (untuk data seed awal)
    return $input === $stored;
}

// Hash password baru sebelum disimpan ke database.

function hashPassword(string $plain): string {
    return password_hash($plain, PASSWORD_BCRYPT);
}

/**
 * PDO dengan PostgreSQL mengembalikan kolom BOOLEAN sebagai string "t"/"f",
 * bukan boolean PHP. Fungsi ini mengonversi kolom is_priority di setiap baris
 * menjadi boolean PHP sejati sehingga json_encode menghasilkan true/false,
 * bukan string, di sisi JavaScript.
 * Tidak menggunakan referensi (&) pada parameter atau foreach untuk menghindari
 * perilaku tak terduga pada PHP 8.x yang dapat menyebabkan json_encode gagal.
 */
function normaliseBooleans(array $rows): array {
    $result = [];
    foreach ($rows as $row) {
        $row['is_priority'] = ($row['is_priority'] === 't' || $row['is_priority'] === true || $row['is_priority'] === 1);
        $result[] = $row;
    }
    return $result;
}

// Membersihkan teks dari AI agar aman disimpan ke database dan di-encode ke JSON.
function sanitizeAiText(string $raw): string {
    // Paksa ke UTF-8 yang valid; karakter tidak valid diganti tanda tanya
    $clean = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    // Buang karakter kontrol selain tab (\x09) dan newline (\x0A \x0D)
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);
    return trim($clean);
}

// ═══════════════════════════════════════════════════════════════════════════
// FUNGSI ANALISIS AI DENGAN GEMINI
// ═══════════════════════════════════════════════════════════════════════════
/**
 * Memanggil Google Gemini API untuk menganalisis laporan mahasiswa.
 *
 * Mengembalikan array dengan 3 key:
 *   - is_priority    (bool)   : true jika laporan darurat/berbahaya
 *   - ai_estimation  (string) : ringkasan 1 kalimat dari AI
 *   - severity_level (string) : "Rendah" | "Sedang" | "Tinggi"
 *
 * Catatan implementasi:
 *   - API key dengan prefix "AQ." WAJIB 
 *   - Semua error dicatat ke ai_debug.log; fungsi selalu mengembalikan nilai
 *     default yang aman agar create_report tidak gagal jika AI tidak tersedia.
 */
function analisisLaporanDenganAI(string $teks_laporan): array
{
    $apiKey = "<API key>"; // isi dengan API key yang dibuat

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-lite:generateContent"; // bisa diubah ke versi Gemini lain jika diinginkan

    $prompt = <<<'PROMPT'
Anda adalah sistem analisis laporan pengaduan fasilitas asrama mahasiswa.
Analisis laporan yang diberikan dan kembalikan HANYA objek JSON valid dengan tepat 3 key berikut:

1. "is_priority" (boolean)
   - true  : laporan DARURAT / membahayakan keselamatan (korsleting, banjir parah, kebakaran,
             kerusakan struktural, fasilitas kritis mati total, dll.)
   - false : masalah minor yang tidak mengancam keselamatan

2. "ai_estimation" (string, maks 1 kalimat)
   Ringkasan singkat tentang masalah dan urgensi tindakannya.

3. "severity_level" (string, HARUS salah satu dari: "Rendah", "Sedang", "Tinggi")
   - "Tinggi" : darurat, bahaya langsung
   - "Sedang" : perlu segera ditangani, tidak darurat
   - "Rendah" : masalah minor, bisa dijadwalkan

Output HARUS berupa JSON murni tanpa markdown, backtick, atau teks tambahan.
Contoh: {"is_priority":true,"ai_estimation":"Kebocoran AC berpotensi korsleting, perlu perbaikan darurat.","severity_level":"Tinggi"}

Laporan:
PROMPT;

    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt . "\n" . $teks_laporan]
                ]
            ]
        ],
        "generationConfig" => [
            "responseMimeType" => "application/json",
            "temperature"      => 0.1,
            "maxOutputTokens"  => 200,
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        // Nonaktifkan SSL verify untuk XAMPP lokal (tidak ada CA bundle)
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        // Batas waktu koneksi awal: 5 detik
        CURLOPT_CONNECTTIMEOUT => 5,
        // Batas waktu total respons: 15 detik
        // Dikurangi dari 20 untuk mencegah Apache thread starvation di XAMPP
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log file untuk debugging — periksa jika AI tidak merespons dengan benar
    $logFile = __DIR__ . '/ai_debug.log';

    // Nilai default yang aman — digunakan jika AI tidak tersedia
    $default = [
        "is_priority"    => false,
        "ai_estimation"  => "Laporan diterima. Analisis AI tidak tersedia saat ini.",
        "severity_level" => "Sedang",
    ];

    if ($curlError) {
        file_put_contents($logFile,
            date('[Y-m-d H:i:s]') . " cURL Error: $curlError\n", FILE_APPEND);
        return $default;
    }

    if ($httpCode !== 200) {
        file_put_contents($logFile,
            date('[Y-m-d H:i:s]') . " HTTP $httpCode dari Gemini: $response\n", FILE_APPEND);
        return $default;
    }

    $geminiResult = json_decode($response, true);
    $rawText = $geminiResult['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if (!$rawText) {
        file_put_contents($logFile,
            date('[Y-m-d H:i:s]') . " Tidak ada teks dalam respons Gemini: $response\n", FILE_APPEND);
        return $default;
    }

    // Bersihkan whitespace dan markdown fence (jaga-jaga model lama)
    $cleanText = trim($rawText);
    $cleanText = preg_replace('/^```(?:json)?\s*/i', '', $cleanText);
    $cleanText = preg_replace('/\s*```\s*$/i', '', $cleanText);
    $cleanText = trim($cleanText);

    $aiData = json_decode($cleanText, true);

    if (!is_array($aiData)) {
        file_put_contents($logFile,
            date('[Y-m-d H:i:s]') . " JSON parse gagal. Raw: $cleanText\n", FILE_APPEND);
        return $default;
    }

    // Validasi dan sanitasi setiap field
    $isPriority    = isset($aiData['is_priority']) && $aiData['is_priority'] === true;

    $aiEstimation  = isset($aiData['ai_estimation'])
                   ? sanitizeAiText((string)$aiData['ai_estimation'])
                   : $default['ai_estimation'];

    $severityLevel = isset($aiData['severity_level'])
                   ? trim((string)$aiData['severity_level'])
                   : '';

    $allowedSeverity = ['Rendah', 'Sedang', 'Tinggi'];
    if (!in_array($severityLevel, $allowedSeverity, true)) {
        $severityLevel = $isPriority ? 'Tinggi' : 'Sedang';
    }

    // Konsistensi: laporan prioritas selalu severity Tinggi
    if ($isPriority && $severityLevel !== 'Tinggi') {
        $severityLevel = 'Tinggi';
    }

    return [
        "is_priority"    => $isPriority,
        "ai_estimation"  => $aiEstimation,
        "severity_level" => $severityLevel,
    ];
}

// ── 1. Login Mahasiswa ────────────────────────────────────────────────────
if ($action === 'login_student') {
    if (!isset($body->email, $body->password)) {
        echo json_encode(["success" => false, "message" => "Email dan password wajib diisi."]);
        exit();
    }
    
    try {
        $stmt = $conn->prepare(
            "SELECT student_id, full_name, email, phone_number, password_hash FROM students WHERE email = :email"
        );
        $stmt->execute([':email' => $body->email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && verifyPassword($body->password, $user['password_hash'])) {
            unset($user['password_hash']); 
            echo json_encode(["success" => true, "user" => $user]);
        } else {
            echo json_encode(["success" => false, "message" => "Email atau password salah."]);
        }
    } catch (PDOException $e) {
        // Mengembalikan error database dalam format JSON yang valid agar tidak merusak fetch()
        echo json_encode(["success" => false, "message" => "Error Query: " . $e->getMessage()]);
    }
}

// ── 2. Login Admin ────────────────────────────────────────────────────────
elseif ($action === 'login_admin') {
    if (!isset($body->email, $body->password)) {
        echo json_encode(["success" => false, "message" => "Email dan password wajib diisi."]);
        exit();
    }
    $stmt = $conn->prepare(
        "SELECT admin_id, full_name, email, phone_number, password_hash FROM admins WHERE email = :email"
    );
    $stmt->execute([':email' => $body->email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && verifyPassword($body->password, $user['password_hash'])) {
        unset($user['password_hash']);
        echo json_encode(["success" => true, "user" => $user]);
    } else {
        echo json_encode(["success" => false, "message" => "Email atau password salah."]);
    }
}

// ── 3. Ambil Profil Mahasiswa ─────────────────────────────────────────────
elseif ($action === 'get_student_profile') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) { echo json_encode(null); exit(); }

    $stmt = $conn->prepare(
        "SELECT student_id, full_name, email, phone_number FROM students WHERE student_id = :id"
    );
    $stmt->execute([':id' => $id]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
}

// ── 4. Ambil Profil Admin ─────────────────────────────────────────────────
elseif ($action === 'get_admin_profile') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) { echo json_encode(null); exit(); }

    $stmt = $conn->prepare(
        "SELECT admin_id, full_name, email, phone_number FROM admins WHERE admin_id = :id"
    );
    $stmt->execute([':id' => $id]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
}

// ── 5. Perbarui Profil Mahasiswa ──────────────────────────────────────────
elseif ($action === 'update_student') {
    if (!isset($body->student_id, $body->full_name, $body->email)) {
        echo json_encode(["success" => false, "message" => "Data tidak lengkap."]);
        exit();
    }

    $studentId = (int)$body->student_id;
    // trim() penting: membuang spasi dan mencegah string kosong lolos sebagai password baru
    $newPass   = isset($body->new_password) ? trim((string)$body->new_password) : '';
    $currPass  = isset($body->current_password) ? (string)$body->current_password : '';

    // Jika ingin ganti password: verifikasi password lama dulu
    if ($newPass !== '') {
        $stmt = $conn->prepare("SELECT password_hash FROM students WHERE student_id = :id");
        $stmt->execute([':id' => $studentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !verifyPassword($currPass, $row['password_hash'])) {
            echo json_encode(["success" => false, "message" => "Password saat ini salah."]);
            exit();
        }
    }

    $params = [
        ':nama'  => $body->full_name,
        ':email' => $body->email,
        ':telp'  => isset($body->phone_number) ? $body->phone_number : '',
        ':id'    => $studentId,
    ];

    $sql = "UPDATE students SET full_name = :nama, email = :email, phone_number = :telp";
    if ($newPass !== '') {
        $sql .= ", password_hash = :pass";
        $params[':pass'] = hashPassword($newPass);
    }
    $sql .= " WHERE student_id = :id";

    $stmt = $conn->prepare($sql);
    $ok   = $stmt->execute($params);
    echo json_encode(["success" => $ok, "message" => $ok ? "Profil berhasil diperbarui." : "Gagal memperbarui data."]);
}

// ── 6. Perbarui Profil Admin ──────────────────────────────────────────────
elseif ($action === 'update_admin') {
    if (!isset($body->admin_id, $body->full_name, $body->email)) {
        echo json_encode(["success" => false, "message" => "Data tidak lengkap."]);
        exit();
    }

    $adminId  = (int)$body->admin_id;
    // trim() penting: membuang spasi dan mencegah string kosong lolos sebagai password baru
    $newPass  = isset($body->new_password) ? trim((string)$body->new_password) : '';
    $currPass = isset($body->current_password) ? (string)$body->current_password : '';

    if ($newPass !== '') {
        $stmt = $conn->prepare("SELECT password_hash FROM admins WHERE admin_id = :id");
        $stmt->execute([':id' => $adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !verifyPassword($currPass, $row['password_hash'])) {
            echo json_encode(["success" => false, "message" => "Password saat ini salah."]);
            exit();
        }
    }

    $params = [
        ':nama'  => $body->full_name,
        ':email' => $body->email,
        ':telp'  => isset($body->phone_number) ? $body->phone_number : '',
        ':id'    => $adminId,
    ];

    $sql = "UPDATE admins SET full_name = :nama, email = :email, phone_number = :telp";
    if ($newPass !== '') {
        $sql .= ", password_hash = :pass";
        $params[':pass'] = hashPassword($newPass);
    }
    $sql .= " WHERE admin_id = :id";

    $stmt = $conn->prepare($sql);
    $ok   = $stmt->execute($params);
    echo json_encode(["success" => $ok, "message" => $ok ? "Profil berhasil diperbarui." : "Gagal memperbarui data."]);
}

// ── 7. Ambil Laporan Milik Mahasiswa ──────────────────────────────────────
elseif ($action === 'get_reports_student') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) { echo json_encode([]); exit(); }

    $stmt = $conn->prepare(
        "SELECT report_id, student_id, description, ai_estimation, severity_level, status::TEXT,
                person_in_charge, is_priority::int, created_at, updated_at
         FROM reports
         WHERE student_id = :id
         ORDER BY created_at DESC, report_id DESC"
    );
    $stmt->execute([':id' => $id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(normaliseBooleans($rows), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

// ── 8. Ambil Semua Laporan (Admin) ────────────────────────────────────────
elseif ($action === 'get_all_reports') {
    $stmt = $conn->prepare(
        "SELECT r.report_id, r.student_id, r.description, r.ai_estimation, r.severity_level,
                r.status::TEXT, r.person_in_charge, r.is_priority::int,
                r.created_at, r.updated_at,
                s.full_name AS pelapor
         FROM reports r
         JOIN students s ON r.student_id = s.student_id
         ORDER BY r.created_at DESC, r.report_id DESC"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(normaliseBooleans($rows), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

// ── 9. Ambil Laporan Prioritas (Admin) ───────────────────────────────────
elseif ($action === 'get_priority_reports') {
    $stmt = $conn->prepare(
        "SELECT r.report_id, r.student_id, r.description, r.ai_estimation, r.severity_level,
                r.status::TEXT, r.person_in_charge, r.is_priority::int,
                r.created_at, r.updated_at,
                s.full_name AS pelapor
         FROM reports r
         JOIN students s ON r.student_id = s.student_id
         WHERE r.is_priority = TRUE
         ORDER BY r.created_at DESC, r.report_id DESC"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(normaliseBooleans($rows), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

// ── 10. Buat Laporan Baru (dengan analisis Gemini AI) ────────────────────
elseif ($action === 'create_report') {
    if (!isset($body->student_id, $body->description) || trim($body->description) === '') {
        echo json_encode(["success" => false, "message" => "student_id dan description wajib diisi."]);
        exit();
    }

    $studentId   = (int)$body->student_id;
    $description = trim($body->description);

    // Panggil Gemini AI untuk menganalisis laporan — selalu berhasil (ada fallback)
    $aiResult      = analisisLaporanDenganAI($description);
    $aiEstimation  = $aiResult['ai_estimation'];
    $severityLevel = $aiResult['severity_level'];
    $isPriorityStr = $aiResult['is_priority'] ? 'true' : 'false'; // format boolean PostgreSQL

    $stmt = $conn->prepare(
        "INSERT INTO reports (student_id, description, ai_estimation, severity_level, is_priority)
         VALUES (:student_id, :description, :ai_estimation, :severity_level, :is_priority)"
    );
    $ok = $stmt->execute([
        ':student_id'     => $studentId,
        ':description'    => $description,
        ':ai_estimation'  => $aiEstimation,
        ':severity_level' => $severityLevel,
        ':is_priority'    => $isPriorityStr,
    ]);

    // Kembalikan hasil AI agar frontend bisa menampilkannya segera
    echo json_encode([
        "success"     => $ok,
        "ai_analysis" => [
            "ai_estimation"  => $aiEstimation,
            "severity_level" => $severityLevel,
            "is_priority"    => $aiResult['is_priority'],
        ]
    ]);
}

// ── 11. Toggle Prioritas ──────────────────────────────────────────────────
elseif ($action === 'toggle_priority') {
    if (!isset($body->report_id)) {
        echo json_encode(["success" => false, "message" => "report_id wajib diisi."]);
        exit();
    }
    $stmt = $conn->prepare(
        "UPDATE reports SET is_priority = NOT is_priority WHERE report_id = :id"
    );
    $ok = $stmt->execute([':id' => (int)$body->report_id]);
    echo json_encode(["success" => $ok]);
}

// ── 12. Update Status & PIC Laporan (Admin) ───────────────────────────────
elseif ($action === 'update_report_status') {
    if (!isset($body->report_id, $body->status)) {
        echo json_encode(["success" => false, "message" => "report_id dan status wajib diisi."]);
        exit();
    }

    $allowed = ['Menunggu', 'Diproses', 'Selesai'];
    if (!in_array($body->status, $allowed)) {
        echo json_encode(["success" => false, "message" => "Nilai status tidak valid."]);
        exit();
    }

    $stmt = $conn->prepare(
        "UPDATE reports
         SET status = :status::report_status,
             person_in_charge = :pic
         WHERE report_id = :id"
    );
    $ok = $stmt->execute([
        ':status' => $body->status,
        ':pic'    => isset($body->person_in_charge) ? trim($body->person_in_charge) : null,
        ':id'     => (int)$body->report_id,
    ]);
    echo json_encode(["success" => $ok]);
}

// ── 13. Ambil Notifikasi Mahasiswa ────────────────────────────────────────
elseif ($action === 'get_student_notifications') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) { echo json_encode([]); exit(); }

    $stmt = $conn->prepare(
        "SELECT notif_id, report_id, message, is_read, created_at
         FROM student_notifications
         WHERE student_id = :id
         ORDER BY created_at DESC, notif_id DESC"
    );
    $stmt->execute([':id' => $id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

// ── 14. Tandai Semua Notifikasi Mahasiswa Sebagai Telah Dibaca ────────────
elseif ($action === 'mark_notifications_read') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) { echo json_encode(["success" => false]); exit(); }

    $stmt = $conn->prepare(
        "UPDATE student_notifications
         SET is_read = TRUE
         WHERE student_id = :id AND is_read = FALSE"
    );
    $ok = $stmt->execute([':id' => $id]);
    echo json_encode(["success" => $ok]);
}

// ── 15. Ambil Jumlah Laporan Baru untuk Admin ─────────────────────────────
// Mengembalikan berapa laporan yang masuk sejak admin terakhir logout.
// Jika admin belum pernah logout (tidak ada baris di admin_session_log),
// kembalikan 0 agar tidak menampilkan angka yang tidak bermakna.
elseif ($action === 'get_admin_new_reports') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) { echo json_encode(["count" => 0]); exit(); }

    $stmt = $conn->prepare(
        "SELECT last_logout_at FROM admin_session_log WHERE admin_id = :id"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // Admin belum pernah logout — tidak ada referensi waktu, tampilkan 0
        echo json_encode(["count" => 0]);
        exit();
    }

    $stmt2 = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM reports WHERE created_at > :since"
    );
    $stmt2->execute([':since' => $row['last_logout_at']]);
    $count = (int)$stmt2->fetch(PDO::FETCH_ASSOC)['cnt'];
    echo json_encode(["count" => $count]);
}

// ── 16. Catat Waktu Logout Admin ─────────────────────────────────────────
// Dipanggil saat admin menekan tombol Keluar, sebelum sesi dihapus.
elseif ($action === 'record_admin_logout') {
    if (!isset($body->admin_id)) {
        echo json_encode(["success" => false, "message" => "admin_id wajib diisi."]);
        exit();
    }
    $adminId = (int)$body->admin_id;

    // UPSERT: perbarui jika sudah ada, sisipkan jika belum
    $stmt = $conn->prepare(
        "INSERT INTO admin_session_log (admin_id, last_logout_at)
         VALUES (:id, NOW())
         ON CONFLICT (admin_id) DO UPDATE SET last_logout_at = NOW()"
    );
    $ok = $stmt->execute([':id' => $adminId]);
    echo json_encode(["success" => $ok]);
}

// ── 404 fallback ──────────────────────────────────────────────────────────
else {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Action '$action' tidak dikenali."]);
}
?>
