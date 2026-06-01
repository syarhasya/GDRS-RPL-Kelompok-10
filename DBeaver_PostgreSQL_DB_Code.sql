-- ============================================================
--  GDRS — PostgreSQL Schema
-- ============================================================

-- 1. ENUM untuk status laporan
CREATE TYPE report_status AS ENUM ('Menunggu', 'Diproses', 'Selesai');

-- 2. Tabel Mahasiswa
CREATE TABLE students (
    student_id          SERIAL PRIMARY KEY,
    full_name           VARCHAR(255) NOT NULL,
    email               VARCHAR(255) UNIQUE NOT NULL,
    phone_number        VARCHAR(20),
    password_hash       VARCHAR(255) NOT NULL,
    profile_picture_url TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Tabel Admin
CREATE TABLE admins (
    admin_id            SERIAL PRIMARY KEY,
    full_name           VARCHAR(255) NOT NULL,
    email               VARCHAR(255) UNIQUE NOT NULL,
    phone_number        VARCHAR(20),
    password_hash       VARCHAR(255) NOT NULL,
    profile_picture_url TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Tabel Laporan
CREATE TABLE reports (
    report_id         SERIAL PRIMARY KEY,
    student_id        INT NOT NULL,
    description       TEXT NOT NULL,
    ai_estimation     TEXT,
    severity_level    VARCHAR(10) DEFAULT 'Sedang',
    status            report_status DEFAULT 'Menunggu',
    person_in_charge  VARCHAR(100),
    is_priority       BOOLEAN DEFAULT FALSE,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_student
        FOREIGN KEY (student_id)
        REFERENCES students(student_id)
        ON DELETE CASCADE
);

-- 5. Tabel Notifikasi Mahasiswa
--    Setiap baris mewakili satu perubahan status/prioritas laporan milik mahasiswa.
--    is_read = FALSE berarti belum dilihat oleh mahasiswa.
CREATE TABLE student_notifications (
    notif_id    SERIAL PRIMARY KEY,
    student_id  INT NOT NULL,
    report_id   INT NOT NULL,
    message     TEXT NOT NULL,
    is_read     BOOLEAN DEFAULT FALSE,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notif_student
        FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_report
        FOREIGN KEY (report_id)  REFERENCES reports(report_id)   ON DELETE CASCADE
);

-- 6. Tabel Sesi Admin
--    Menyimpan timestamp logout terakhir per admin untuk menghitung
--    berapa laporan baru masuk sejak admin terakhir aktif.
CREATE TABLE admin_session_log (
    admin_id       INT PRIMARY KEY,
    last_logout_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_session_admin
        FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE
);

-- 7. Trigger: otomatis perbarui updated_at saat baris diubah
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_reports_updated_at
BEFORE UPDATE ON reports
FOR EACH ROW
EXECUTE FUNCTION update_updated_at_column();

-- 8. Trigger: buat notifikasi mahasiswa saat status atau prioritas laporan berubah
CREATE OR REPLACE FUNCTION notify_student_on_report_change()
RETURNS TRIGGER AS $$
DECLARE
    v_msg TEXT := '';
BEGIN
    -- Deteksi perubahan status
    IF NEW.status IS DISTINCT FROM OLD.status THEN
        v_msg := v_msg || 'Status laporan #' || NEW.report_id ||
                 ' berubah menjadi "' || NEW.status::TEXT || '". ';
    END IF;

    -- Deteksi perubahan prioritas
    IF NEW.is_priority IS DISTINCT FROM OLD.is_priority THEN
        IF NEW.is_priority THEN
            v_msg := v_msg || 'Laporan #' || NEW.report_id || ' ditandai sebagai Prioritas. ';
        ELSE
            v_msg := v_msg || 'Laporan #' || NEW.report_id || ' tidak lagi berstatus Prioritas. ';
        END IF;
    END IF;

    -- Hanya sisipkan notifikasi jika ada yang benar-benar berubah
    IF v_msg <> '' THEN
        INSERT INTO student_notifications (student_id, report_id, message)
        VALUES (NEW.student_id, NEW.report_id, TRIM(v_msg));
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_notify_student
AFTER UPDATE OF status, is_priority ON reports
FOR EACH ROW
EXECUTE FUNCTION notify_student_on_report_change();

-- ── Data Awal ────────────────────────────────────────────────────────────
-- Catatan: password disimpan sebagai plain-text hanya untuk data seed awal.
-- API akan mendeteksi plain-text vs bcrypt secara otomatis (fallback).
-- Setelah pertama kali user menyimpan perubahan password lewat UI,
-- password yamg diubah akan tersimpan dalam format bcrypt.

INSERT INTO admins (full_name, email, phone_number, password_hash)
VALUES ('Admin123', 'admin123@gdrs.com', '089876543210', 'Password123');

INSERT INTO students (full_name, email, phone_number, password_hash)
VALUES ('Student123', 'student123@gdrs.com', '081234567890', 'Password123');

INSERT INTO reports (student_id, description, ai_estimation, severity_level, status, person_in_charge, is_priority, created_at, updated_at)
VALUES (1,
  'Engsel pintu lemari pakaian di kamar A-115 terlepas seluruhnya dari kusen kayunya karena sekrup yang sudah aus dan lapuk. Pintu lemari sekarang hanya bersandar pada dinding kamar dan tidak bisa ditutup sama sekali. Membutuhkan penggantian engsel kayu atau bor ulang.',
  'Perbaikan kayu dan engsel pintu furnitur', 'Rendah', 'Selesai', 'Pak Anto', FALSE,
  NOW() - INTERVAL '1 day', NOW() - INTERVAL '1 day');

INSERT INTO reports (student_id, description, ai_estimation, severity_level, status, person_in_charge, is_priority, created_at, updated_at)
VALUES (1,
  'Lampu penerangan di lorong utara lantai 2, tepat di depan akses pintu tangga darurat, sudah berkedip-kedip lalu mati total sejak tiga hari yang lalu. Kondisi area tersebut menjadi sangat gelap di malam hari dan cukup membahayakan penghuni saat akan turun tangga.',
  'Penggantian lampu lorong darurat', 'Sedang', 'Menunggu', NULL, FALSE,
  NOW() - INTERVAL '2 days', NOW() - INTERVAL '2 days');

INSERT INTO reports (student_id, description, ai_estimation, severity_level, status, person_in_charge, is_priority, created_at, updated_at)
VALUES (1,
  'Gagang keran air di wastafel kamar mandi lorong lantai 3 patah total. Air menyembur keluar tanpa henti dan mulai menggenangi area lantai luar kamar mandi. Katup air utama sudah coba kami tutup secara manual tetapi tuasnya terlalu keras untuk diputar.',
  'Keran patah menyebabkan genangan air skala besar, perbaikan mekanis pipa', 'Tinggi', 'Selesai', 'Pak Budi', TRUE,
  NOW() - INTERVAL '3 days', NOW() - INTERVAL '3 days');

INSERT INTO reports (student_id, description, ai_estimation, severity_level, status, person_in_charge, is_priority, created_at, updated_at)
VALUES (1,
  'AC di kamar B-302 mengalami kebocoran yang sangat parah. Air menetes deras dari unit indoor langsung ke atas meja belajar sejak tadi siang, membasahi buku-buku dan kabel colokan laptop. Mohon segera diperbaiki karena berpotensi menyebabkan korsleting listrik yang membahayakan penghuni.',
  'Kebocoran AC berisiko tinggi korsleting listrik, perlu perbaikan darurat', 'Tinggi', 'Diproses', 'Pak Anto', TRUE,
  NOW() - INTERVAL '4 days', NOW() - INTERVAL '4 days');