<?php
// ==================== KONEKSI DATABASE NEON ====================
// Menggunakan environment variables untuk koneksi database
$database_url = getenv('NEON_DATABASE_URL');

if (!$database_url) {
    die("Koneksi ke database gagal: NEON_DATABASE_URL tidak ditemukan di environment variables");
}

// Parse database URL
$url = parse_url($database_url);
$host = $url['host'] ?? 'localhost';
$port = $url['port'] ?? 5432;
$dbname = isset($url['path']) ? substr($url['path'], 1) : 'db_tugas_perpus';
$username = $url['user'] ?? 'postgres';
$password = $url['pass'] ?? '';

// Koneksi ke PostgreSQL
try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $conn = new PDO($dsn, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Set encoding
    $conn->exec("SET NAMES 'utf8'");
} catch (PDOException $e) {
    die("Koneksi ke database gagal: " . $e->getMessage());
}

// ==================== FUNGSI GENERATE ID OTOMATIS ====================
function generateKodeBuku($conn) {
    try {
        // Cari kode buku terakhir
        $query = "SELECT kode_buku FROM buku ORDER BY kode_buku DESC LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch();
        
        if ($result && isset($result['kode_buku'])) {
            $last_kode = $result['kode_buku'];
            $new_kode = $last_kode + 1;
        } else {
            $new_kode = 1001;
        }
        
        return $new_kode;
    } catch (PDOException $e) {
        // Default value jika terjadi error
        return 1001;
    }
}

// ==================== BUAT TABEL JIKA BELUM ADA ====================
$tables = [
    "CREATE TABLE IF NOT EXISTS mahasiswa (
      nim VARCHAR(20) PRIMARY KEY,
      nama_mhs VARCHAR(50),
      jurusan VARCHAR(50),
      tgl_lahir DATE
    )",

    "CREATE TABLE IF NOT EXISTS petugas (
      id_petugas SERIAL PRIMARY KEY,
      nama_petugas VARCHAR(100),
      jabatan VARCHAR(50)
    )",

    "CREATE TABLE IF NOT EXISTS rak (
      kode_rak INTEGER PRIMARY KEY,
      lokasi_rak VARCHAR(100)
    )",

    "CREATE TABLE IF NOT EXISTS buku (
      kode_buku INTEGER PRIMARY KEY,
      judul_buku VARCHAR(100),
      pengarang VARCHAR(100),
      penerbit VARCHAR(100),
      tahun_terbit INTEGER,
      kode_rak INTEGER
    )",

    "CREATE TABLE IF NOT EXISTS peminjaman (
      id_peminjaman SERIAL PRIMARY KEY,
      nim VARCHAR(20),
      kode_buku INTEGER,
      tgl_pinjam TIMESTAMP,
      batas_max_peminjaman TIMESTAMP,
      id_petugas INTEGER
    )",

    "CREATE TABLE IF NOT EXISTS pengembalian (
      id_pengembalian SERIAL PRIMARY KEY,
      id_peminjaman INTEGER,
      kode_buku INTEGER,
      tgl_kembali TIMESTAMP,
      kode_perpanjang INTEGER,
      denda INTEGER,
      hilang_rusak VARCHAR(100),
      id_petugas INTEGER
    )"
];

// Eksekusi semua query pembuatan tabel
foreach ($tables as $table_query) {
    try {
        $conn->exec($table_query);
    } catch (PDOException $e) {
        // Ignore jika tabel sudah ada
        if (strpos($e->getMessage(), 'already exists') === false) {
            // Tampilkan error hanya jika bukan karena tabel sudah ada
            error_log("Error creating table: " . $e->getMessage());
        }
    }
}

// ==================== INSERT DATA AWAL ====================
// Data rak
try {
    $check_rak = $conn->query("SELECT COUNT(*) as count FROM rak");
    $row = $check_rak->fetch();
    if ($row['count'] == 0) {
        $stmt = $conn->prepare("INSERT INTO rak (kode_rak, lokasi_rak) VALUES (1, 'Rak A - Teknologi'), (2, 'Rak B - Sastra'), (3, 'Rak C - Sains')");
        $stmt->execute();
    }
} catch (PDOException $e) {
    error_log("Error inserting rak data: " . $e->getMessage());
}

// Data petugas
try {
    $check_petugas = $conn->query("SELECT COUNT(*) as count FROM petugas");
    $row = $check_petugas->fetch();
    if ($row['count'] == 0) {
        $stmt = $conn->prepare("INSERT INTO petugas (nama_petugas, jabatan) VALUES ('Admin Perpustakaan', 'Administrator')");
        $stmt->execute();
    }
} catch (PDOException $e) {
    error_log("Error inserting petugas data: " . $e->getMessage());
}

// ==================== SESSION START ====================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Simpan koneksi ke session
$_SESSION['db_conn'] = $conn;

// ==================== PROSES LOGIN ====================
if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username == 'admin' && $password == 'admin123') {
        $_SESSION['logged_in'] = true;
        $_SESSION['username'] = $username;
        header('Location: index.php?page=dashboard');
        exit();
    } else {
        $login_error = "Username atau password salah!";
    }
}

// ==================== PROSES LOGOUT ====================
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// ==================== PROSES CRUD ====================
$success = '';
$error_msg = '';
$action_mode = ''; // 'tambah' atau 'edit'
$edit_data = null;

// Reset edit mode jika tidak ada parameter edit
if (isset($_GET['page']) && !isset($_GET['edit_mahasiswa']) && !isset($_GET['edit_buku']) && !isset($_GET['edit_peminjaman']) && !isset($_GET['edit_pengembalian'])) {
    $action_mode = '';
    $edit_data = null;
}

if (isset($_SESSION['logged_in'])) {
    // ========== MAHASISWA ==========
    // 1. TAMBAH MAHASISWA - NIM INPUT MANUAL
    if (isset($_POST['tambah_mahasiswa'])) {
        $nim = $_POST['nim'];
        $nama = $_POST['nama'];
        $jurusan = $_POST['jurusan'];
        $tgl_lahir = $_POST['tgl_lahir'];

        try {
            // Cek apakah NIM sudah ada
            $stmt = $conn->prepare("SELECT * FROM mahasiswa WHERE nim = :nim");
            $stmt->bindParam(':nim', $nim);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $error_msg = "NIM $nim sudah terdaftar!";
            } else {
                $stmt = $conn->prepare("INSERT INTO mahasiswa (nim, nama_mhs, jurusan, tgl_lahir) VALUES (:nim, :nama, :jurusan, :tgl_lahir)");
                $stmt->bindParam(':nim', $nim);
                $stmt->bindParam(':nama', $nama);
                $stmt->bindParam(':jurusan', $jurusan);
                $stmt->bindParam(':tgl_lahir', $tgl_lahir);
                
                if ($stmt->execute()) {
                    $success = "Mahasiswa $nama berhasil ditambahkan! NIM: $nim";
                    $action_mode = '';
                    $edit_data = null;
                }
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }

    // 2. EDIT MAHASISWA
    if (isset($_POST['edit_mahasiswa'])) {
        $nim_old = $_POST['nim_edit'];
        $nim = $_POST['nim'];
        $nama = $_POST['nama'];
        $jurusan = $_POST['jurusan'];
        $tgl_lahir = $_POST['tgl_lahir'];

        try {
            $stmt = $conn->prepare("UPDATE mahasiswa SET 
                  nim = :nim,
                  nama_mhs = :nama, 
                  jurusan = :jurusan, 
                  tgl_lahir = :tgl_lahir 
                  WHERE nim = :nim_old");

            $stmt->bindParam(':nim_old', $nim_old);
            $stmt->bindParam(':nim', $nim);
            $stmt->bindParam(':nama', $nama);
            $stmt->bindParam(':jurusan', $jurusan);
            $stmt->bindParam(':tgl_lahir', $tgl_lahir);
            
            if ($stmt->execute()) {
                $success = "Data mahasiswa berhasil diperbarui!";
                $action_mode = '';
                $edit_data = null;
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }

    // 3. HAPUS MAHASISWA
    if (isset($_GET['hapus_mahasiswa'])) {
        $nim = $_GET['hapus_mahasiswa'];
        
        try {
            // Cek apakah mahasiswa masih memiliki peminjaman aktif
            $stmt = $conn->prepare("SELECT * FROM peminjaman WHERE nim = :nim AND id_peminjaman NOT IN (SELECT id_peminjaman FROM pengembalian)");
            $stmt->bindParam(':nim', $nim);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $error_msg = "Mahasiswa masih memiliki peminjaman aktif! Tidak dapat dihapus.";
            } else {
                $stmt = $conn->prepare("DELETE FROM mahasiswa WHERE nim = :nim");
                $stmt->bindParam(':nim', $nim);
                
                if ($stmt->execute()) {
                    $success = "Mahasiswa berhasil dihapus!";
                    $action_mode = '';
                    $edit_data = null;
                }
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }

    // 4. GET DATA EDIT MAHASISWA
    if (isset($_GET['edit_mahasiswa'])) {
        $nim_edit = $_GET['edit_mahasiswa'];
        
        try {
            $stmt = $conn->prepare("SELECT * FROM mahasiswa WHERE nim = :nim");
            $stmt->bindParam(':nim', $nim_edit);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $edit_data = $stmt->fetch();
                $action_mode = 'edit_mahasiswa';
            }
        } catch (PDOException $e) {
            error_log("Error fetching mahasiswa data: " . $e->getMessage());
        }
    }

    // ========== BUKU ==========
    // 1. TAMBAH BUKU - MODIFIKASI: AUTO GENERATE KODE BUKU
    if (isset($_POST['tambah_buku'])) {
        // Generate kode buku otomatis
        $kode_buku = generateKodeBuku($conn);
        $judul = $_POST['judul'];
        $pengarang = $_POST['pengarang'];
        $penerbit = $_POST['penerbit'];
        $tahun = $_POST['tahun'];
        $kode_rak = $_POST['kode_rak'];

        try {
            // Cek apakah kode buku sudah ada
            $stmt = $conn->prepare("SELECT * FROM buku WHERE kode_buku = :kode_buku");
            $stmt->bindParam(':kode_buku', $kode_buku);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $error_msg = "Kode buku $kode_buku sudah ada!";
            } else {
                $stmt = $conn->prepare("INSERT INTO buku (kode_buku, judul_buku, pengarang, penerbit, tahun_terbit, kode_rak) 
                      VALUES (:kode_buku, :judul, :pengarang, :penerbit, :tahun, :kode_rak)");
                
                $stmt->bindParam(':kode_buku', $kode_buku);
                $stmt->bindParam(':judul', $judul);
                $stmt->bindParam(':pengarang', $pengarang);
                $stmt->bindParam(':penerbit', $penerbit);
                $stmt->bindParam(':tahun', $tahun);
                $stmt->bindParam(':kode_rak', $kode_rak);
                
                if ($stmt->execute()) {
                    $success = "Buku '$judul' berhasil ditambahkan! Kode: $kode_buku";
                    $action_mode = '';
                    $edit_data = null;
                }
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }

    // 2. EDIT BUKU
    if (isset($_POST['edit_buku'])) {
        $kode_buku_old = $_POST['kode_buku_edit'];
        $kode_buku = $_POST['kode_buku'];
        $judul = $_POST['judul'];
        $pengarang = $_POST['pengarang'];
        $penerbit = $_POST['penerbit'];
        $tahun = $_POST['tahun'];
        $kode_rak = $_POST['kode_rak'];

        try {
            $stmt = $conn->prepare("UPDATE buku SET 
                  judul_buku = :judul, 
                  pengarang = :pengarang, 
                  penerbit = :penerbit, 
                  tahun_terbit = :tahun, 
                  kode_rak = :kode_rak 
                  WHERE kode_buku = :kode_buku_old");

            $stmt->bindParam(':kode_buku_old', $kode_buku_old);
            $stmt->bindParam(':judul', $judul);
            $stmt->bindParam(':pengarang', $pengarang);
            $stmt->bindParam(':penerbit', $penerbit);
            $stmt->bindParam(':tahun', $tahun);
            $stmt->bindParam(':kode_rak', $kode_rak);
            
            if ($stmt->execute()) {
                $success = "Data buku berhasil diperbarui!";
                $action_mode = '';
                $edit_data = null;
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }

    // 3. HAPUS BUKU dengan SweetAlert2 konfirmasi
    if (isset($_GET['hapus_buku'])) {
        $kode = $_GET['hapus_buku'];
        
        try {
            // Cek apakah buku sedang dipinjam
            $stmt = $conn->prepare("SELECT * FROM peminjaman p 
                                  LEFT JOIN pengembalian pg ON p.id_peminjaman = pg.id_peminjaman
                                  WHERE p.kode_buku = :kode AND pg.id_pengembalian IS NULL");
            $stmt->bindParam(':kode', $kode);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                // Simpan pesan error dalam session untuk ditampilkan dengan SweetAlert2
                $_SESSION['sweetalert_error'] = "Buku ini sedang dipinjam dan tidak dapat dihapus!";
                $_SESSION['error_title'] = "Gagal Hapus";
                $_SESSION['error_icon'] = "error";
            } else {
                $stmt = $conn->prepare("DELETE FROM buku WHERE kode_buku = :kode");
                $stmt->bindParam(':kode', $kode);
                
                if ($stmt->execute()) {
                    $success = "Buku berhasil dihapus!";
                    $action_mode = '';
                    $edit_data = null;
                }
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }

    // 4. GET DATA EDIT BUKU
    if (isset($_GET['edit_buku'])) {
        $kode_edit = $_GET['edit_buku'];
        
        try {
            $stmt = $conn->prepare("SELECT * FROM buku WHERE kode_buku = :kode");
            $stmt->bindParam(':kode', $kode_edit);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $edit_data = $stmt->fetch();
                $action_mode = 'edit_buku';
            }
        } catch (PDOException $e) {
            error_log("Error fetching buku data: " . $e->getMessage());
        }
    }

    // ========== PEMINJAMAN ==========
    // 1. TAMBAH PEMINJAMAN
    if (isset($_POST['tambah_peminjaman'])) {
        $nim = $_POST['nim'];
        $kode_buku = $_POST['kode_buku'];
        $lama_pinjam = $_POST['lama_pinjam'];

        try {
            // Cek apakah buku sedang dipinjam
            $stmt = $conn->prepare("SELECT * FROM peminjaman p 
                                    LEFT JOIN pengembalian pg ON p.id_peminjaman = pg.id_peminjaman
                                    WHERE p.kode_buku = :kode_buku AND pg.id_pengembalian IS NULL");
            $stmt->bindParam(':kode_buku', $kode_buku);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['sweetalert_error'] = "Buku ini sedang dipinjam!";
                $_SESSION['error_title'] = "Gagal Pinjam";
                $_SESSION['error_icon'] = "warning";
            } else {
                $batas = date('Y-m-d H:i:s', strtotime("+$lama_pinjam days"));
                $id_petugas = 1;

                $stmt = $conn->prepare("INSERT INTO peminjaman (nim, kode_buku, tgl_pinjam, batas_max_peminjaman, id_petugas) 
                      VALUES (:nim, :kode_buku, NOW(), :batas, :id_petugas)");
                
                $stmt->bindParam(':nim', $nim);
                $stmt->bindParam(':kode_buku', $kode_buku);
                $stmt->bindParam(':batas', $batas);
                $stmt->bindParam(':id_petugas', $id_petugas);
                
                if ($stmt->execute()) {
                    $success = "Peminjaman berhasil ditambahkan!";
                    $action_mode = '';
                    $edit_data = null;
                }
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }

    // 2. HAPUS PEMINJAMAN
    if (isset($_GET['hapus_peminjaman'])) {
        $id = $_GET['hapus_peminjaman'];
        
        try {
            // Cek apakah peminjaman sudah dikembalikan
            $stmt = $conn->prepare("SELECT * FROM pengembalian WHERE id_peminjaman = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['sweetalert_error'] = "Peminjaman ini sudah dikembalikan!";
                $_SESSION['error_title'] = "Gagal Hapus";
                $_SESSION['error_icon'] = "warning";
            } else {
                $stmt = $conn->prepare("DELETE FROM peminjaman WHERE id_peminjaman = :id");
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $success = "Peminjaman berhasil dihapus!";
                    $action_mode = '';
                    $edit_data = null;
                }
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }

    // 3. GET DATA EDIT PEMINJAMAN
    if (isset($_GET['edit_peminjaman'])) {
        $id_edit = $_GET['edit_peminjaman'];
        
        try {
            $stmt = $conn->prepare("SELECT * FROM peminjaman WHERE id_peminjaman = :id");
            $stmt->bindParam(':id', $id_edit);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $edit_data = $stmt->fetch();
                $action_mode = 'edit_peminjaman';
            }
        } catch (PDOException $e) {
            error_log("Error fetching peminjaman data: " . $e->getMessage());
        }
    }

    // 4. EDIT PEMINJAMAN
    if (isset($_POST['edit_peminjaman'])) {
        $id_peminjaman_old = $_POST['id_peminjaman_edit'];
        $id_peminjaman = $_POST['id_peminjaman'];
        $nim = $_POST['nim'];
        $kode_buku = $_POST['kode_buku'];
        $lama_pinjam = $_POST['lama_pinjam'];

        $batas = date('Y-m-d H:i:s', strtotime("+$lama_pinjam days"));

        try {
            $stmt = $conn->prepare("UPDATE peminjaman SET 
                  nim = :nim, 
                  kode_buku = :kode_buku, 
                  batas_max_peminjaman = :batas 
                  WHERE id_peminjaman = :id_peminjaman_old");

            $stmt->bindParam(':id_peminjaman_old', $id_peminjaman_old);
            $stmt->bindParam(':nim', $nim);
            $stmt->bindParam(':kode_buku', $kode_buku);
            $stmt->bindParam(':batas', $batas);
            
            if ($stmt->execute()) {
                $success = "Peminjaman berhasil diperbarui!";
                $action_mode = '';
                $edit_data = null;
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }

    // ========== PENGEMBALIAN ==========
    // 1. TAMBAH PENGEMBALIAN
    if (isset($_POST['tambah_pengembalian'])) {
        $id_peminjaman = $_POST['id_peminjaman'];
        $denda = $_POST['denda'];
        $kondisi = $_POST['kondisi'];

        try {
            // Cek apakah sudah pernah dikembalikan
            $stmt = $conn->prepare("SELECT * FROM pengembalian WHERE id_peminjaman = :id_peminjaman");
            $stmt->bindParam(':id_peminjaman', $id_peminjaman);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['sweetalert_error'] = "Buku ini sudah dikembalikan sebelumnya!";
                $_SESSION['error_title'] = "Gagal Pengembalian";
                $_SESSION['error_icon'] = "warning";
            } else {
                $stmt = $conn->prepare("SELECT kode_buku, id_petugas FROM peminjaman WHERE id_peminjaman = :id_peminjaman");
                $stmt->bindParam(':id_peminjaman', $id_peminjaman);
                $stmt->execute();
                $row_pinjam = $stmt->fetch();

                if ($row_pinjam) {
                    $kode_buku = $row_pinjam['kode_buku'];
                    $id_petugas = $row_pinjam['id_petugas'];

                    $stmt = $conn->prepare("INSERT INTO pengembalian (id_peminjaman, kode_buku, tgl_kembali, denda, hilang_rusak, id_petugas) 
                          VALUES (:id_peminjaman, :kode_buku, NOW(), :denda, :kondisi, :id_petugas)");
                    
                    $stmt->bindParam(':id_peminjaman', $id_peminjaman);
                    $stmt->bindParam(':kode_buku', $kode_buku);
                    $stmt->bindParam(':denda', $denda);
                    $stmt->bindParam(':kondisi', $kondisi);
                    $stmt->bindParam(':id_petugas', $id_petugas);
                    
                    if ($stmt->execute()) {
                        $success = "Buku berhasil dikembalikan!";
                        $action_mode = '';
                        $edit_data = null;
                    }
                }
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }

    // 2. HAPUS PENGEMBALIAN
    if (isset($_GET['hapus_pengembalian'])) {
        $id = $_GET['hapus_pengembalian'];
        
        try {
            $stmt = $conn->prepare("DELETE FROM pengembalian WHERE id_pengembalian = :id");
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $success = "Pengembalian berhasil dihapus!";
                $action_mode = '';
                $edit_data = null;
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }

    // 3. GET DATA EDIT PENGEMBALIAN
    if (isset($_GET['edit_pengembalian'])) {
        $id_edit = $_GET['edit_pengembalian'];
        
        try {
            $stmt = $conn->prepare("SELECT * FROM pengembalian WHERE id_pengembalian = :id");
            $stmt->bindParam(':id', $id_edit);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $edit_data = $stmt->fetch();
                $action_mode = 'edit_pengembalian';
            }
        } catch (PDOException $e) {
            error_log("Error fetching pengembalian data: " . $e->getMessage());
        }
    }

    // 4. EDIT PENGEMBALIAN
    if (isset($_POST['edit_pengembalian'])) {
        $id_pengembalian_old = $_POST['id_pengembalian_edit'];
        $id_pengembalian = $_POST['id_pengembalian'];
        $denda = $_POST['denda'];
        $kondisi = $_POST['kondisi'];

        try {
            $stmt = $conn->prepare("UPDATE pengembalian SET 
                  denda = :denda, 
                  hilang_rusak = :kondisi, 
                  tgl_kembali = NOW() 
                  WHERE id_pengembalian = :id_pengembalian_old");

            $stmt->bindParam(':id_pengembalian_old', $id_pengembalian_old);
            $stmt->bindParam(':denda', $denda);
            $stmt->bindParam(':kondisi', $kondisi);
            
            if ($stmt->execute()) {
                $success = "Data pengembalian berhasil diperbarui!";
                $action_mode = '';
                $edit_data = null;
            }
        } catch (PDOException $e) {
            $error_msg = "Gagal: " . $e->getMessage();
        }
    }
}

// ==================== ROUTING ====================
$page = isset($_GET['page']) ? $_GET['page'] : 'login';

// Jika belum login, redirect ke login
if (!isset($_SESSION['logged_in']) && $page != 'login') {
    header('Location: index.php?page=login');
    exit();
}

// ==================== QUERY FUNCTIONS UNTUK TAMPILAN ====================
// Fungsi untuk mengambil data statistik
function getTotalMahasiswa($conn) {
    try {
        $stmt = $conn->query("SELECT COUNT(*) as total FROM mahasiswa");
        $result = $stmt->fetch();
        return $result['total'] ?? 0;
    } catch (PDOException $e) {
        error_log("Error getting total mahasiswa: " . $e->getMessage());
        return 0;
    }
}

function getTotalBuku($conn) {
    try {
        $stmt = $conn->query("SELECT COUNT(*) as total FROM buku");
        $result = $stmt->fetch();
        return $result['total'] ?? 0;
    } catch (PDOException $e) {
        error_log("Error getting total buku: " . $e->getMessage());
        return 0;
    }
}

function getTotalPeminjaman($conn) {
    try {
        $stmt = $conn->query("SELECT COUNT(*) as total FROM peminjaman");
        $result = $stmt->fetch();
        return $result['total'] ?? 0;
    } catch (PDOException $e) {
        error_log("Error getting total peminjaman: " . $e->getMessage());
        return 0;
    }
}

function getTotalPengembalian($conn) {
    try {
        $stmt = $conn->query("SELECT COUNT(*) as total FROM pengembalian");
        $result = $stmt->fetch();
        return $result['total'] ?? 0;
    } catch (PDOException $e) {
        error_log("Error getting total pengembalian: " . $e->getMessage());
        return 0;
    }
}

// Fungsi untuk mengambil data mahasiswa
function getMahasiswaData($conn) {
    try {
        $stmt = $conn->prepare("SELECT *, EXTRACT(YEAR FROM AGE(tgl_lahir)) as umur FROM mahasiswa ORDER BY nim");
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting mahasiswa data: " . $e->getMessage());
        return [];
    }
}

// Fungsi untuk mengambil data buku dengan status
function getBukuData($conn) {
    try {
        $query = "SELECT b.*, r.lokasi_rak,
                  CASE 
                      WHEN EXISTS (
                          SELECT 1 FROM peminjaman p 
                          LEFT JOIN pengembalian pg ON p.id_peminjaman = pg.id_peminjaman
                          WHERE p.kode_buku = b.kode_buku AND pg.id_pengembalian IS NULL
                      ) THEN 'Dipinjam'
                      ELSE 'Tersedia'
                  END as status_buku
                  FROM buku b 
                  LEFT JOIN rak r ON b.kode_rak = r.kode_rak 
                  ORDER BY b.kode_buku";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting buku data: " . $e->getMessage());
        return [];
    }
}

// Fungsi untuk mengambil data peminjaman
function getPeminjamanData($conn) {
    try {
        $query = "SELECT p.*, m.nama_mhs, b.judul_buku,
                  CASE 
                      WHEN EXISTS (SELECT 1 FROM pengembalian WHERE id_peminjaman = p.id_peminjaman) THEN 'Dikembalikan'
                      WHEN p.batas_max_peminjaman < NOW() THEN 'Terlambat'
                      ELSE 'Aktif'
                  END as status_pinjam
                  FROM peminjaman p
                  JOIN mahasiswa m ON p.nim = m.nim
                  JOIN buku b ON p.kode_buku = b.kode_buku
                  ORDER BY p.tgl_pinjam DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting peminjaman data: " . $e->getMessage());
        return [];
    }
}

// Fungsi untuk mengambil data pengembalian
function getPengembalianData($conn) {
    try {
        $query = "SELECT pg.*, p.nim, m.nama_mhs, b.judul_buku 
                  FROM pengembalian pg
                  JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
                  JOIN mahasiswa m ON p.nim = m.nim
                  JOIN buku b ON pg.kode_buku = b.kode_buku
                  ORDER BY pg.tgl_kembali DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting pengembalian data: " . $e->getMessage());
        return [];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Perpustakaan</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* CSS TAMBAHAN UNTUK INFO ID OTOMATIS */
        .auto-id-notice {
            background-color: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
        
        .auto-id-notice i {
            color: #0066cc;
            margin-right: 8px;
        }

        /* Dashboard Statistic Cards */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.bg-blue {
            border-left: 5px solid #3498db;
        }

        .stat-card.bg-green {
            border-left: 5px solid #2ecc71;
        }

        .stat-card.bg-orange {
            border-left: 5px solid #e67e22;
        }

        .stat-card.bg-red {
            border-left: 5px solid #e74c3c;
        }

        /* Sidebar mobile styles */
        @media (max-width: 767.98px) {
            .sidebar-mobile {
                position: fixed;
                top: 0;
                left: -100%;
                width: 280px;
                height: 100vh;
                z-index: 1050;
                background: white;
                transition: left 0.3s;
                overflow-y: auto;
            }
            
            .sidebar-mobile.show {
                left: 0;
            }
            
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 1040;
            }
            
            .sidebar-overlay.show {
                display: block;
            }
            
            body.sidebar-open {
                overflow: hidden;
            }
        }

        /* Custom button styles */
        .btn-action {
            margin: 2px;
            padding: 0.25rem 0.5rem;
        }

        .btn-group-actions {
            display: flex;
            gap: 5px;
        }

        /* Table responsive */
        .table-responsive {
            overflow-x: auto;
        }

        /* Status badge */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .status-dipinjam {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-tersedia {
            background-color: #d1e7dd;
            color: #0f5132;
        }

        /* Quick Links hover effect */
        .quick-link-icon {
            transition: all 0.3s ease;
        }
        
        .quick-link-icon:hover {
            transform: scale(1.1);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
    </style>
</head>

<body>
    <?php if ($page == 'login' && !isset($_SESSION['logged_in'])): ?>
        <!-- ==================== HALAMAN LOGIN ==================== -->
        <div class="login-container">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-md-5">
                        <div class="login-box">
                            <div class="text-center mb-4">
                                <i class="bi bi-book-half text-primary" style="font-size: 4rem;"></i>
                                <h2 class="mt-3">SISTEM PERPUSTAKAAN</h2>
                                <p class="text-muted">Digital Library Management</p>
                            </div>

                            <?php if (isset($login_error)): ?>
                                <div class="alert alert-danger alert-dismissible fade show">
                                    <i class="bi bi-exclamation-triangle"></i> <?php echo $login_error; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

                            <form method="POST">
                                <div class="mb-3">
                                    <label class="form-label">Username</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                                        <input type="text" class="form-control" name="username" required placeholder="Masukkan Username">
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label">Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                        <input type="password" class="form-control" name="password" required placeholder="Masukkan Password">
                                    </div>
                                </div>

                                <button type="submit" name="login" class="btn btn-primary w-100 py-2">
                                    <i class="bi bi-box-arrow-in-right"></i> LOGIN
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif (isset($_SESSION['logged_in'])): ?>
        <!-- ==================== DASHBOARD DAN MODUL LAIN ==================== -->
        <?php
        // Ambil data untuk statistik menggunakan fungsi
        $total_mahasiswa = getTotalMahasiswa($conn);
        $total_buku = getTotalBuku($conn);
        $total_peminjaman = getTotalPeminjaman($conn);
        $total_pengembalian = getTotalPengembalian($conn);
        ?>

        <!-- Navbar dengan tombol toggle untuk sidebar mobile -->
        <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #00008B;">
            <div class="container-fluid">
                <a class="navbar-brand" href="index.php?page=dashboard">
                    <i class="bi bi-book-half me-2"></i> Sistem Perpustakaan
                </a>

                <button class="navbar-toggler d-md-none" type="button" id="sidebarToggle">
                    <i class="bi bi-list"></i>
                </button>

                <div class="navbar-nav ms-auto">
                    <span class="navbar-text me-3 d-none d-md-inline" style="color: white;">
                        <i class="bi bi-person-circle me-1"></i> <?php echo $_SESSION['username']; ?>
                    </span>
                    <a href="index.php?logout=true" class="btn btn-danger">
                        <i class="bi bi-box-arrow-right"></i> <span class="d-none d-md-inline">Logout</span>
                    </a>
                </div>
            </div>
        </nav>

        <!-- Notifikasi -->
        <?php if ($success): ?>
            <div class="container-fluid mt-3">
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle"></i> <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="container-fluid mt-3">
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo $error_msg; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        <?php endif; ?>

        <div class="container-fluid mt-3">
            <div class="row">
                <!-- Sidebar -->
                <div class="col-md-3 mb-4 sidebar-mobile" id="sidebar">
                    <div class="card" style="border: none; height: 100%;">
                        <div class="card-header text-black d-flex justify-content-between align-items-center" style="background-color: #ffffff; border-bottom: 2px solid #00008B;">
                            <h5 class="card-title mb-0"><i class="bi bi-menu-button-wide"></i> Menu Navigasi</h5>
                            <button class="btn btn-sm btn-outline-secondary d-md-none sidebar-close-btn" id="closeSidebar">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <div class="card-body p-0" style="flex: 1; overflow-y: auto;">
                            <div class="list-group list-group-flush">
                                <?php
                                $menu_items = [
                                    'dashboard' => ['icon' => 'speedometer2', 'text' => 'Dashboard'],
                                    'mahasiswa' => ['icon' => 'people', 'text' => 'Data Mahasiswa'],
                                    'buku' => ['icon' => 'book', 'text' => 'Data Buku'],
                                    'peminjaman' => ['icon' => 'clipboard-check', 'text' => 'Peminjaman'],
                                    'pengembalian' => ['icon' => 'arrow-return-left', 'text' => 'Pengembalian'],
                                    'tambah_data' => ['icon' => 'plus-circle', 'text' => 'Tambah Data'],
                                    'about' => ['icon' => 'info-circle', 'text' => 'Tentang']
                                ];

                                foreach ($menu_items as $key => $item):
                                    $is_active = $page == $key ? 'active' : '';
                                    $bg_color = $page == $key ? 'style="background-color: #00008B; color: white;"' : '';
                                ?>
                                    <a href="index.php?page=<?php echo $key; ?>"
                                        class="list-group-item list-group-item-action border-0 py-3 <?php echo $is_active; ?> sidebar-item"
                                        <?php echo $bg_color; ?>
                                        data-page="<?php echo $key; ?>">
                                        <i class="bi bi-<?php echo $item['icon']; ?> me-2"></i>
                                        <span><?php echo $item['text']; ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="col-md-9" id="mainContent">
                    <?php if ($page == 'dashboard'): ?>
                        <!-- ==================== DASHBOARD ==================== -->
                        <h2 class="mb-4"><i class="bi bi-speedometer2"></i> Dashboard</h2>

                        <!-- Statistik -->
                        <div class="row">
                            <div class="col-md-6 col-lg-3">
                                <div class="stat-card bg-blue">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6>Mahasiswa</h6>
                                            <h3><?php echo $total_mahasiswa; ?></h3>
                                        </div>
                                        <i class="bi bi-people" style="font-size: 2rem; opacity: 0.8;"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 col-lg-3">
                                <div class="stat-card bg-green">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6>Buku</h6>
                                            <h3><?php echo $total_buku; ?></h3>
                                        </div>
                                        <i class="bi bi-book" style="font-size: 2rem; opacity: 0.8;"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 col-lg-3">
                                <div class="stat-card bg-orange">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6>Peminjaman</h6>
                                            <h3><?php echo $total_peminjaman; ?></h3>
                                        </div>
                                        <i class="bi bi-clipboard-check" style="font-size: 2rem; opacity: 0.8;"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 col-lg-3">
                                <div class="stat-card bg-red">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6>Pengembalian</h6>
                                            <h3><?php echo $total_pengembalian; ?></h3>
                                        </div>
                                        <i class="bi bi-arrow-return-left" style="font-size: 2rem; opacity: 0.8;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Links -->
                        <div class="card mt-4">
                            <div class="card-body">
                                <h5 class="card-title"><i class="bi bi-lightning-charge"></i> Akses Cepat</h5>
                                <div class="row text-center">
                                    <!-- Tambah Mahasiswa -->
                                    <div class="col-md-2 mb-3">
                                        <a href="index.php?page=tambah_data" class="text-decoration-none">
                                            <div class="p-3 border rounded-circle bg-primary text-white mb-2 d-flex align-items-center justify-content-center quick-link-icon"
                                                style="width: 70px; height: 70px; margin: 0 auto;">
                                                <i class="bi bi-person-plus fs-4"></i>
                                            </div>
                                            <span class="text-dark">Tambah Mahasiswa</span>
                                        </a>
                                    </div>

                                    <!-- Tambah Buku -->
                                    <div class="col-md-2 mb-3">
                                        <a href="index.php?page=tambah_data" class="text-decoration-none">
                                            <div class="p-3 border rounded-circle bg-success text-white mb-2 d-flex align-items-center justify-content-center quick-link-icon"
                                                style="width: 70px; height: 70px; margin: 0 auto;">
                                                <i class="bi bi-book fs-4"></i>
                                            </div>
                                            <span class="text-dark">Tambah Buku</span>
                                        </a>
                                    </div>

                                    <!-- Peminjaman -->
                                    <div class="col-md-2 mb-3">
                                        <a href="index.php?page=peminjaman" class="text-decoration-none">
                                            <div class="p-3 border rounded-circle bg-warning text-white mb-2 d-flex align-items-center justify-content-center quick-link-icon"
                                                style="width: 70px; height: 70px; margin: 0 auto;">
                                                <i class="bi bi-clipboard-plus fs-4"></i>
                                            </div>
                                            <span class="text-dark">Peminjaman</span>
                                        </a>
                                    </div>

                                    <!-- Pengembalian -->
                                    <div class="col-md-2 mb-3">
                                        <a href="index.php?page=pengembalian" class="text-decoration-none">
                                            <div class="p-3 border rounded-circle bg-info text-white mb-2 d-flex align-items-center justify-content-center quick-link-icon"
                                                style="width: 70px; height: 70px; margin: 0 auto;">
                                                <i class="bi bi-arrow-return-left fs-4"></i>
                                            </div>
                                            <span class="text-dark">Pengembalian</span>
                                        </a>
                                    </div>

                                    <!-- Mahasiswa -->
                                    <div class="col-md-2 mb-3">
                                        <a href="index.php?page=mahasiswa" class="text-decoration-none">
                                            <div class="p-3 border rounded-circle text-white mb-2 d-flex align-items-center justify-content-center quick-link-icon"
                                                style="width: 70px; height: 70px; margin: 0 auto; background-color: #9c27b0;">
                                                <i class="bi bi-people fs-4"></i>
                                            </div>
                                            <span class="text-dark">Mahasiswa</span>
                                        </a>
                                    </div>

                                    <!-- Buku -->
                                    <div class="col-md-2 mb-3">
                                        <a href="index.php?page=buku" class="text-decoration-none">
                                            <div class="p-3 border rounded-circle bg-danger text-white mb-2 d-flex align-items-center justify-content-center quick-link-icon"
                                                style="width: 70px; height: 70px; margin: 0 auto;">
                                                <i class="bi bi-book fs-4"></i>
                                            </div>
                                            <span class="text-dark">Buku</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($page == 'mahasiswa'): ?>
                        <!-- ==================== HALAMAN MAHASISWA ==================== -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2><i class="bi bi-people"></i> Data Mahasiswa</h2>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tambahMahasiswaModal">
                                <i class="bi bi-person-plus"></i> <span class="d-none d-md-inline">Tambah Mahasiswa</span>
                            </button>
                        </div>

                        <?php
                        $mahasiswa_data = getMahasiswaData($conn);
                        ?>

                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>NIM</th>
                                                <th>Nama</th>
                                                <th>Jurusan</th>
                                                <th class="d-none d-md-table-cell">Tanggal Lahir</th>
                                                <th>Umur</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($mahasiswa_data)): ?>
                                                <?php foreach ($mahasiswa_data as $row): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($row['nim']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['nama_mhs']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['jurusan']); ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo date('d-m-Y', strtotime($row['tgl_lahir'])); ?></td>
                                                        <td><?php echo round($row['umur'] ?? 0); ?> tahun</td>
                                                        <td>
                                                            <div class="btn-group-actions">
                                                                <a href="?page=mahasiswa&edit_mahasiswa=<?php echo urlencode($row['nim']); ?>"
                                                                    class="btn btn-warning btn-sm btn-action">
                                                                    <i class="bi bi-pencil"></i>
                                                                </a>
                                                                <a href="?page=mahasiswa&hapus_mahasiswa=<?php echo urlencode($row['nim']); ?>"
                                                                    class="btn btn-danger btn-sm btn-action hapus-btn"
                                                                    data-id="<?php echo htmlspecialchars($row['nim']); ?>"
                                                                    data-nama="<?php echo htmlspecialchars($row['nama_mhs']); ?>"
                                                                    data-type="mahasiswa">
                                                                    <i class="bi bi-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center py-4">
                                                        <i class="bi bi-people text-muted" style="font-size: 3rem;"></i>
                                                        <p class="text-muted mt-2">Belum ada data mahasiswa</p>
                                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tambahMahasiswaModal">
                                                            <i class="bi bi-person-plus"></i> Tambah Mahasiswa Pertama
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($page == 'buku'): ?>
                        <!-- ==================== HALAMAN BUKU ==================== -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2><i class="bi bi-book"></i> Data Buku</h2>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tambahBukuModal">
                                <i class="bi bi-book-plus"></i> <span class="d-none d-md-inline">Tambah Buku</span>
                            </button>
                        </div>

                        <?php
                        $buku_data = getBukuData($conn);
                        ?>

                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Kode</th>
                                                <th>Judul</th>
                                                <th class="d-none d-md-table-cell">Pengarang</th>
                                                <th class="d-none d-md-table-cell">Penerbit</th>
                                                <th>Tahun</th>
                                                <th class="d-none d-md-table-cell">Rak</th>
                                                <th>Status</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($buku_data)): ?>
                                                <?php foreach ($buku_data as $row): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($row['kode_buku']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['judul_buku']); ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($row['pengarang']); ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($row['penerbit']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['tahun_terbit']); ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($row['lokasi_rak'] ?: 'Rak ' . $row['kode_rak']); ?></td>
                                                        <td>
                                                            <span class="status-badge <?php echo $row['status_buku'] == 'Dipinjam' ? 'status-dipinjam' : 'status-tersedia'; ?>">
                                                                <?php echo htmlspecialchars($row['status_buku']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group-actions">
                                                                <a href="?page=buku&edit_buku=<?php echo urlencode($row['kode_buku']); ?>"
                                                                    class="btn btn-warning btn-sm btn-action">
                                                                    <i class="bi bi-pencil"></i>
                                                                </a>
                                                                <a href="?page=buku&hapus_buku=<?php echo urlencode($row['kode_buku']); ?>"
                                                                    class="btn btn-danger btn-sm btn-action hapus-btn"
                                                                    data-id="<?php echo htmlspecialchars($row['kode_buku']); ?>"
                                                                    data-judul="<?php echo htmlspecialchars($row['judul_buku']); ?>"
                                                                    data-status="<?php echo htmlspecialchars($row['status_buku']); ?>"
                                                                    data-type="buku">
                                                                    <i class="bi bi-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-4">
                                                        <i class="bi bi-book text-muted" style="font-size: 3rem;"></i>
                                                        <p class="text-muted mt-2">Belum ada data buku</p>
                                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tambahBukuModal">
                                                            <i class="bi bi-book-plus"></i> Tambah Buku Pertama
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($page == 'peminjaman'): ?>
                        <!-- ==================== HALAMAN PEMINJAMAN ==================== -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2><i class="bi bi-clipboard-check"></i> Data Peminjaman</h2>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tambahPeminjamanModal">
                                <i class="bi bi-plus-circle"></i> <span class="d-none d-md-inline">Tambah Peminjaman</span>
                            </button>
                        </div>

                        <?php
                        $peminjaman_data = getPeminjamanData($conn);
                        ?>

                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th class="d-none d-md-table-cell">ID</th>
                                                <th>NIM</th>
                                                <th>Mahasiswa</th>
                                                <th class="d-none d-md-table-cell">Buku</th>
                                                <th>Tanggal Pinjam</th>
                                                <th class="d-none d-md-table-cell">Batas Kembali</th>
                                                <th>Status</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($peminjaman_data)): ?>
                                                <?php foreach ($peminjaman_data as $row):
                                                    $badge_class = '';
                                                    if ($row['status_pinjam'] == 'Aktif') $badge_class = 'bg-success';
                                                    elseif ($row['status_pinjam'] == 'Terlambat') $badge_class = 'bg-danger';
                                                    else $badge_class = 'bg-secondary';
                                                ?>
                                                    <tr>
                                                        <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($row['id_peminjaman']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['nim']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['nama_mhs']); ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($row['judul_buku']); ?></td>
                                                        <td><?php echo date('d-m-Y', strtotime($row['tgl_pinjam'])); ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo date('d-m-Y', strtotime($row['batas_max_peminjaman'])); ?></td>
                                                        <td>
                                                            <span class="badge <?php echo $badge_class; ?>">
                                                                <?php echo htmlspecialchars($row['status_pinjam']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group-actions">
                                                                <?php if ($row['status_pinjam'] == 'Aktif'): ?>
                                                                    <a href="?page=peminjaman&edit_peminjaman=<?php echo urlencode($row['id_peminjaman']); ?>"
                                                                        class="btn btn-warning btn-sm btn-action">
                                                                        <i class="bi bi-pencil"></i>
                                                                    </a>
                                                                <?php endif; ?>
                                                                <a href="?page=peminjaman&hapus_peminjaman=<?php echo urlencode($row['id_peminjaman']); ?>"
                                                                    class="btn btn-danger btn-sm btn-action hapus-btn"
                                                                    data-id="<?php echo htmlspecialchars($row['id_peminjaman']); ?>"
                                                                    data-status="<?php echo htmlspecialchars($row['status_pinjam']); ?>"
                                                                    data-type="peminjaman">
                                                                    <i class="bi bi-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-4">
                                                        <i class="bi bi-clipboard text-muted" style="font-size: 3rem;"></i>
                                                        <p class="text-muted mt-2">Belum ada data peminjaman</p>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($page == 'pengembalian'): ?>
                        <!-- ==================== HALAMAN PENGEMBALIAN ==================== -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2><i class="bi bi-arrow-return-left"></i> Data Pengembalian</h2>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tambahPengembalianModal">
                                <i class="bi bi-plus-circle"></i> <span class="d-none d-md-inline">Tambah Pengembalian</span>
                            </button>
                        </div>

                        <?php
                        $pengembalian_data = getPengembalianData($conn);
                        ?>

                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th class="d-none d-md-table-cell">ID</th>
                                                <th>ID Pinjam</th>
                                                <th>Mahasiswa</th>
                                                <th class="d-none d-md-table-cell">Buku</th>
                                                <th>Tanggal Kembali</th>
                                                <th>Denda</th>
                                                <th>Kondisi</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($pengembalian_data)): ?>
                                                <?php foreach ($pengembalian_data as $row): ?>
                                                    <tr>
                                                        <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($row['id_pengembalian']); ?></td>
                                                        <td><?php echo htmlspecialchars($row['id_peminjaman']); ?></td>
                                                        <td>
                                                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($row['nama_mhs']); ?></span>
                                                            <small class="d-md-none"><?php echo substr(htmlspecialchars($row['nama_mhs']), 0, 15) . '...'; ?></small>
                                                            <br class="d-md-none">
                                                            <small>NIM: <?php echo htmlspecialchars($row['nim']); ?></small>
                                                        </td>
                                                        <td class="d-none d-md-table-cell"><?php echo htmlspecialchars($row['judul_buku']); ?></td>
                                                        <td><?php echo date('d-m-Y', strtotime($row['tgl_kembali'])); ?></td>
                                                        <td>Rp <?php echo number_format($row['denda'], 0, ',', '.'); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $row['hilang_rusak'] == 'Baik' ? 'success' : 'warning'; ?>">
                                                                <?php echo htmlspecialchars($row['hilang_rusak']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group-actions">
                                                                <a href="?page=pengembalian&edit_pengembalian=<?php echo urlencode($row['id_pengembalian']); ?>"
                                                                    class="btn btn-warning btn-sm btn-action">
                                                                    <i class="bi bi-pencil"></i>
                                                                </a>
                                                                <a href="?page=pengembalian&hapus_pengembalian=<?php echo urlencode($row['id_pengembalian']); ?>"
                                                                    class="btn btn-danger btn-sm btn-action hapus-btn"
                                                                    data-id="<?php echo htmlspecialchars($row['id_pengembalian']); ?>"
                                                                    data-type="pengembalian">
                                                                    <i class="bi bi-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-4">
                                                        <i class="bi bi-arrow-return-left text-muted" style="font-size: 3rem;"></i>
                                                        <p class="text-muted mt-2">Belum ada data pengembalian</p>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($page == 'tambah_data'): ?>
                        <!-- ==================== HALAMAN TAMBAH DATA ==================== -->
                        <h2 class="mb-4"><i class="bi bi-plus-circle"></i> Tambah Data</h2>

                        <div class="row">
                            <!-- Tambah Mahasiswa -->
                            <div class="col-12 col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header bg-primary text-white">
                                        <i class="bi bi-person-plus"></i> Tambah Mahasiswa
                                    </div>
                                    <div class="card-body">
                                        <form method="POST">
                                            <div class="mb-3">
                                                <label class="form-label">NIM</label>
                                                <input type="text" class="form-control" name="nim" required placeholder="Masukkan NIM">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Nama</label>
                                                <input type="text" class="form-control" name="nama" required placeholder="Masukkan Nama">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Jurusan</label>
                                                <input type="text" class="form-control" name="jurusan" required placeholder="Masukkan Jurusan">
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Tanggal Lahir</label>
                                                <input type="date" class="form-control" name="tgl_lahir" required>
                                            </div>
                                            <button type="submit" name="tambah_mahasiswa" class="btn btn-primary w-100">
                                                <i class="bi bi-save"></i> Simpan
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Tambah Buku -->
                            <div class="col-12 col-md-6 mb-4">
                                <div class="card">
                                    <div class="card-header bg-success text-white">
                                        <i class="bi bi-book-plus"></i> Tambah Buku
                                    </div>
                                    <div class="card-body">
                                        <div class="auto-id-notice">
                                            <i class="bi bi-info-circle"></i> Kode buku akan dibuat otomatis oleh sistem (mulai dari 1001)
                                        </div>
                                        <form method="POST">
                                            <div class="mb-3">
                                                <label class="form-label">Judul Buku</label>
                                                <input type="text" class="form-control" name="judul" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Pengarang</label>
                                                <input type="text" class="form-control" name="pengarang" required>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Penerbit</label>
                                                <input type="text" class="form-control" name="penerbit" required>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Tahun Terbit</label>
                                                    <input type="number" class="form-control" name="tahun" required>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">Kode Rak</label>
                                                    <select class="form-select" name="kode_rak">
                                                        <option value="1">1 - Teknologi</option>
                                                        <option value="2">2 - Sastra</option>
                                                        <option value="3">3 - Sains</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <button type="submit" name="tambah_buku" class="btn btn-success w-100">
                                                <i class="bi bi-save"></i> Simpan
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php elseif ($page == 'about'): ?>
                        <!-- ==================== HALAMAN TENTANG ==================== -->
                        <!-- Kode about section tetap sama seperti sebelumnya -->
                        <!-- ... (kode about section tetap sama) ... -->

                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ==================== MODAL-MODAL ==================== -->
        <!-- Modal Tambah/Edit Mahasiswa -->
        <div class="modal fade" id="tambahMahasiswaModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <?php echo ($action_mode == 'edit_mahasiswa' && $edit_data) ? 'Edit Mahasiswa' : 'Tambah Mahasiswa'; ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <?php if ($action_mode == 'edit_mahasiswa' && $edit_data): ?>
                                <!-- EDIT MODE -->
                                <input type="hidden" name="nim_edit" value="<?php echo htmlspecialchars($edit_data['nim']); ?>">
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label class="form-label">NIM</label>
                                <input type="text" class="form-control" name="nim" 
                                       value="<?php echo ($action_mode == 'edit_mahasiswa' && $edit_data) ? htmlspecialchars($edit_data['nim']) : ''; ?>" 
                                       <?php echo ($action_mode == 'edit_mahasiswa' && $edit_data) ? 'readonly' : 'required'; ?>>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Nama</label>
                                <input type="text" class="form-control" name="nama"
                                    value="<?php echo ($action_mode == 'edit_mahasiswa' && $edit_data) ? htmlspecialchars($edit_data['nama_mhs']) : ''; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Jurusan</label>
                                <input type="text" class="form-control" name="jurusan"
                                    value="<?php echo ($action_mode == 'edit_mahasiswa' && $edit_data) ? htmlspecialchars($edit_data['jurusan']) : ''; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tanggal Lahir</label>
                                <input type="date" class="form-control" name="tgl_lahir"
                                    value="<?php echo ($action_mode == 'edit_mahasiswa' && $edit_data) ? $edit_data['tgl_lahir'] : ''; ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <?php if ($action_mode == 'edit_mahasiswa' && $edit_data): ?>
                                <button type="submit" name="edit_mahasiswa" class="btn btn-primary">Update</button>
                            <?php else: ?>
                                <button type="submit" name="tambah_mahasiswa" class="btn btn-primary">Simpan</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal Tambah/Edit Buku -->
        <div class="modal fade" id="tambahBukuModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <?php echo ($action_mode == 'edit_buku' && $edit_data) ? 'Edit Buku' : 'Tambah Buku'; ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <?php if ($action_mode == 'edit_buku' && $edit_data): ?>
                                <!-- EDIT MODE -->
                                <input type="hidden" name="kode_buku_edit" value="<?php echo htmlspecialchars($edit_data['kode_buku']); ?>">
                                <div class="mb-3">
                                    <label class="form-label">Kode Buku</label>
                                    <input type="number" class="form-control" name="kode_buku"
                                           value="<?php echo htmlspecialchars($edit_data['kode_buku']); ?>" readonly>
                                </div>
                            <?php else: ?>
                                <!-- ADD MODE -->
                                <div class="auto-id-notice">
                                    <i class="bi bi-info-circle"></i> Kode buku akan dibuat otomatis oleh sistem (mulai dari 1001)
                                </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="form-label">Judul Buku</label>
                                <input type="text" class="form-control" name="judul"
                                    value="<?php echo ($action_mode == 'edit_buku' && $edit_data) ? htmlspecialchars($edit_data['judul_buku']) : ''; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Pengarang</label>
                                <input type="text" class="form-control" name="pengarang"
                                    value="<?php echo ($action_mode == 'edit_buku' && $edit_data) ? htmlspecialchars($edit_data['pengarang']) : ''; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Penerbit</label>
                                <input type="text" class="form-control" name="penerbit"
                                    value="<?php echo ($action_mode == 'edit_buku' && $edit_data) ? htmlspecialchars($edit_data['penerbit']) : ''; ?>" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Tahun Terbit</label>
                                    <input type="number" class="form-control" name="tahun"
                                        value="<?php echo ($action_mode == 'edit_buku' && $edit_data) ? htmlspecialchars($edit_data['tahun_terbit']) : ''; ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Kode Rak</label>
                                    <select class="form-select" name="kode_rak">
                                        <option value="1" <?php echo ($action_mode == 'edit_buku' && $edit_data && $edit_data['kode_rak'] == 1) ? 'selected' : ''; ?>>1 - Teknologi</option>
                                        <option value="2" <?php echo ($action_mode == 'edit_buku' && $edit_data && $edit_data['kode_rak'] == 2) ? 'selected' : ''; ?>>2 - Sastra</option>
                                        <option value="3" <?php echo ($action_mode == 'edit_buku' && $edit_data && $edit_data['kode_rak'] == 3) ? 'selected' : ''; ?>>3 - Sains</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <?php if ($action_mode == 'edit_buku' && $edit_data): ?>
                                <button type="submit" name="edit_buku" class="btn btn-primary">Update</button>
                            <?php else: ?>
                                <button type="submit" name="tambah_buku" class="btn btn-primary">Simpan</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal Tambah/Edit Peminjaman -->
        <div class="modal fade" id="tambahPeminjamanModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <?php echo ($action_mode == 'edit_peminjaman' && $edit_data) ? 'Edit Peminjaman' : 'Tambah Peminjaman'; ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <?php if ($action_mode == 'edit_peminjaman' && $edit_data): ?>
                                <input type="hidden" name="id_peminjaman_edit" value="<?php echo htmlspecialchars($edit_data['id_peminjaman']); ?>">
                                <div class="mb-3">
                                    <label class="form-label">ID Peminjaman</label>
                                    <input type="number" class="form-control" name="id_peminjaman"
                                           value="<?php echo htmlspecialchars($edit_data['id_peminjaman']); ?>" readonly>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label">Mahasiswa (NIM)</label>
                                <select class="form-select" name="nim" required>
                                    <option value="">Pilih Mahasiswa</option>
                                    <?php
                                    try {
                                        $stmt = $conn->prepare("SELECT * FROM mahasiswa ORDER BY nama_mhs");
                                        $stmt->execute();
                                        $mahasiswa_list = $stmt->fetchAll();
                                        
                                        foreach ($mahasiswa_list as $mhs) {
                                            $selected = ($action_mode == 'edit_peminjaman' && $edit_data && $edit_data['nim'] == $mhs['nim']) ? 'selected' : '';
                                            echo "<option value='" . htmlspecialchars($mhs['nim']) . "' $selected>" . 
                                                 htmlspecialchars($mhs['nim']) . " - " . htmlspecialchars($mhs['nama_mhs']) . "</option>";
                                        }
                                    } catch (PDOException $e) {
                                        error_log("Error fetching mahasiswa list: " . $e->getMessage());
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Buku</label>
                                <select class="form-select" name="kode_buku" required>
                                    <option value="">Pilih Buku</option>
                                    <?php
                                    try {
                                        $query = "SELECT b.*, 
                                                  CASE 
                                                      WHEN EXISTS (
                                                          SELECT 1 FROM peminjaman p 
                                                          LEFT JOIN pengembalian pg ON p.id_peminjaman = pg.id_peminjaman
                                                          WHERE p.kode_buku = b.kode_buku AND pg.id_pengembalian IS NULL
                                                      ) THEN 'Dipinjam'
                                                      ELSE 'Tersedia'
                                                  END as status_buku
                                           FROM buku b 
                                           ORDER BY b.judul_buku";
                                        $stmt = $conn->prepare($query);
                                        $stmt->execute();
                                        $buku_list = $stmt->fetchAll();
                                        
                                        foreach ($buku_list as $buku) {
                                            $selected = ($action_mode == 'edit_peminjaman' && $edit_data && $edit_data['kode_buku'] == $buku['kode_buku']) ? 'selected' : '';
                                            $disabled = ($buku['status_buku'] == 'Dipinjam' && !$selected) ? 'disabled' : '';
                                            echo "<option value='" . htmlspecialchars($buku['kode_buku']) . "' $selected $disabled>" . 
                                                 htmlspecialchars($buku['kode_buku']) . " - " . htmlspecialchars($buku['judul_buku']) . 
                                                 ($buku['status_buku'] == 'Dipinjam' ? ' (Sedang Dipinjam)' : '') . 
                                                 "</option>";
                                        }
                                    } catch (PDOException $e) {
                                        error_log("Error fetching buku list: " . $e->getMessage());
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Lama Peminjaman (hari)</label>
                                <select class="form-select" name="lama_pinjam" required>
                                    <?php
                                    $lama_pinjam = 14; // default
                                    if ($action_mode == 'edit_peminjaman' && $edit_data) {
                                        $pinjam_date = strtotime($edit_data['tgl_pinjam']);
                                        $batas_date = strtotime($edit_data['batas_max_peminjaman']);
                                        $lama_pinjam = round(($batas_date - $pinjam_date) / (60 * 60 * 24));
                                    }
                                    ?>
                                    <option value="7" <?php echo ($lama_pinjam == 7) ? 'selected' : ''; ?>>7 Hari</option>
                                    <option value="14" <?php echo ($lama_pinjam == 14 || !$action_mode) ? 'selected' : ''; ?>>14 Hari</option>
                                    <option value="30" <?php echo ($lama_pinjam == 30) ? 'selected' : ''; ?>>30 Hari</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <?php if ($action_mode == 'edit_peminjaman' && $edit_data): ?>
                                <button type="submit" name="edit_peminjaman" class="btn btn-primary">Update</button>
                            <?php else: ?>
                                <button type="submit" name="tambah_peminjaman" class="btn btn-primary">Simpan</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal Tambah/Edit Pengembalian -->
        <div class="modal fade" id="tambahPengembalianModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <?php echo ($action_mode == 'edit_pengembalian' && $edit_data) ? 'Edit Pengembalian' : 'Tambah Pengembalian'; ?>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <?php if ($action_mode == 'edit_pengembalian' && $edit_data): ?>
                                <input type="hidden" name="id_pengembalian_edit" value="<?php echo htmlspecialchars($edit_data['id_pengembalian']); ?>">
                                <div class="mb-3">
                                    <label class="form-label">ID Pengembalian</label>
                                    <input type="number" class="form-control" name="id_pengembalian"
                                           value="<?php echo htmlspecialchars($edit_data['id_pengembalian']); ?>" readonly>
                                </div>
                            <?php else: ?>
                                <div class="mb-3">
                                    <label class="form-label">ID Peminjaman</label>
                                    <select class="form-select" name="id_peminjaman" required>
                                        <option value="">Pilih Peminjaman</option>
                                        <?php
                                        try {
                                            $query = "SELECT p.id_peminjaman, p.nim, m.nama_mhs, b.judul_buku,
                                                    CASE 
                                                        WHEN EXISTS (SELECT 1 FROM pengembalian WHERE id_peminjaman = p.id_peminjaman) THEN 'Sudah Dikembalikan'
                                                        ELSE 'Belum Dikembalikan'
                                                    END as status_pinjam
                                           FROM peminjaman p
                                           JOIN mahasiswa m ON p.nim = m.nim
                                           JOIN buku b ON p.kode_buku = b.kode_buku
                                           ORDER BY p.tgl_pinjam DESC";
                                            $stmt = $conn->prepare($query);
                                            $stmt->execute();
                                            $peminjaman_list = $stmt->fetchAll();
                                            
                                            foreach ($peminjaman_list as $pinjam) {
                                                $disabled = ($pinjam['status_pinjam'] == 'Sudah Dikembalikan') ? 'disabled' : '';
                                                echo "<option value='" . htmlspecialchars($pinjam['id_peminjaman']) . "' $disabled>" .
                                                    htmlspecialchars($pinjam['id_peminjaman']) . " - " . htmlspecialchars($pinjam['nama_mhs']) . 
                                                    " (" . htmlspecialchars($pinjam['judul_buku']) . ") - " . 
                                                    htmlspecialchars($pinjam['status_pinjam']) . "</option>";
                                            }
                                        } catch (PDOException $e) {
                                            error_log("Error fetching peminjaman list: " . $e->getMessage());
                                        }
                                        ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label">Denda (Rp)</label>
                                <input type="number" class="form-control" name="denda"
                                    value="<?php echo ($action_mode == 'edit_pengembalian' && $edit_data) ? htmlspecialchars($edit_data['denda']) : '0'; ?>" min="0">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Kondisi Buku</label>
                                <select class="form-select" name="kondisi">
                                    <option value="Baik" <?php echo ($action_mode == 'edit_pengembalian' && $edit_data && $edit_data['hilang_rusak'] == 'Baik') ? 'selected' : ''; ?>>Baik</option>
                                    <option value="Rusak Ringan" <?php echo ($action_mode == 'edit_pengembalian' && $edit_data && $edit_data['hilang_rusak'] == 'Rusak Ringan') ? 'selected' : ''; ?>>Rusak Ringan</option>
                                    <option value="Rusak Berat" <?php echo ($action_mode == 'edit_pengembalian' && $edit_data && $edit_data['hilang_rusak'] == 'Rusak Berat') ? 'selected' : ''; ?>>Rusak Berat</option>
                                    <option value="Hilang" <?php echo ($action_mode == 'edit_pengembalian' && $edit_data && $edit_data['hilang_rusak'] == 'Hilang') ? 'selected' : ''; ?>>Hilang</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <?php if ($action_mode == 'edit_pengembalian' && $edit_data): ?>
                                <button type="submit" name="edit_pengembalian" class="btn btn-primary">Update</button>
                            <?php else: ?>
                                <button type="submit" name="tambah_pengembalian" class="btn btn-primary">Simpan</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- SweetAlert2 JS -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

        <!-- JavaScript untuk toggle sidebar mobile, auto open modal, dan SweetAlert2 -->
        <script>
            // Kode JavaScript tetap sama seperti sebelumnya
            // ... (kode JavaScript tetap sama) ...
        </script>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
