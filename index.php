<?php
// ==================== KONEKSI DATABASE OTOMATIS ====================
$host = "localhost";      // Standard untuk local server
$username = "root";       // Default user XAMPP
$password = "";           // Password kosong (default)
$database = "db_tugas_perpus"; // Database yang dibuat sendiri

// Koneksi ke MySQL
$conn = mysqli_connect($host, $username, $password);

if (!$conn) {
    die("Koneksi ke MySQL gagal: " . mysqli_connect_error());
}

// Buat database jika belum ada
$query = "CREATE DATABASE IF NOT EXISTS $database 
          CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
mysqli_query($conn, $query);

// Pilih database
mysqli_select_db($conn, $database);

// ==================== FUNGSI GENERATE ID OTOMATIS ====================
function generateKodeBuku($conn) {
    // Cari kode buku terakhir
    $query = "SELECT kode_buku FROM buku ORDER BY kode_buku DESC LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if (mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $last_kode = $row['kode_buku'];
        $new_kode = $last_kode + 1;
    } else {
        $new_kode = 1001;
    }
    
    return $new_kode;
}

// ==================== BUAT TABEL JIKA BELUM ADA ====================
$tables = [
    "CREATE TABLE IF NOT EXISTS `mahasiswa` (
      `nim` varchar(20) PRIMARY KEY,
      `nama_mhs` varchar(50),
      `jurusan` varchar(50),
      `tgl_lahir` date
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `petugas` (
      `id_petugas` int PRIMARY KEY AUTO_INCREMENT,
      `nama_petugas` varchar(100),
      `jabatan` varchar(50)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `rak` (
      `kode_rak` int PRIMARY KEY,
      `lokasi_rak` varchar(100)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `buku` (
      `kode_buku` int PRIMARY KEY,
      `judul_buku` varchar(100),
      `pengarang` varchar(100),
      `penerbit` varchar(100),
      `tahun_terbit` year,
      `kode_rak` int
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `peminjaman` (
      `id_peminjaman` int PRIMARY KEY AUTO_INCREMENT,
      `nim` varchar(20),
      `kode_buku` int,
      `tgl_pinjam` datetime,
      `batas_max_peminjaman` datetime,
      `id_petugas` int
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS `pengembalian` (
      `id_pengembalian` int PRIMARY KEY AUTO_INCREMENT,
      `id_peminjaman` int,
      `kode_buku` int,
      `tgl_kembali` datetime,
      `kode_perpanjang` int,
      `denda` int,
      `hilang_rusak` varchar(100),
      `id_petugas` int
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

// Eksekusi semua query pembuatan tabel
foreach ($tables as $table_query) {
    mysqli_query($conn, $table_query);
}

// ==================== INSERT DATA AWAL ====================
// Data rak
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM rak");
$row = mysqli_fetch_assoc($result);
if ($row['count'] == 0) {
    mysqli_query($conn, "INSERT INTO rak (kode_rak, lokasi_rak) VALUES 
                        (1, 'Rak A - Teknologi'),
                        (2, 'Rak B - Sastra'),
                        (3, 'Rak C - Sains')");
}

// Data petugas
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM petugas");
$row = mysqli_fetch_assoc($result);
if ($row['count'] == 0) {
    mysqli_query($conn, "INSERT INTO petugas (nama_petugas, jabatan) VALUES 
                        ('Admin Perpustakaan', 'Administrator')");
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
        $nim = mysqli_real_escape_string($conn, $_POST['nim']);
        $nama = mysqli_real_escape_string($conn, $_POST['nama']);
        $jurusan = mysqli_real_escape_string($conn, $_POST['jurusan']);
        $tgl_lahir = $_POST['tgl_lahir'];

        $cek = mysqli_query($conn, "SELECT * FROM mahasiswa WHERE nim = '$nim'");
        if (mysqli_num_rows($cek) > 0) {
            $error_msg = "NIM $nim sudah terdaftar!";
        } else {
            $query = "INSERT INTO mahasiswa (nim, nama_mhs, jurusan, tgl_lahir) VALUES ('$nim', '$nama', '$jurusan', '$tgl_lahir')";
            if (mysqli_query($conn, $query)) {
                $success = "Mahasiswa $nama berhasil ditambahkan! NIM: $nim";
                $action_mode = '';
                $edit_data = null;
            } else {
                $error_msg = "Gagal: " . mysqli_error($conn);
            }
        }
    }

    // 2. EDIT MAHASISWA
    if (isset($_POST['edit_mahasiswa'])) {
        $nim_old = mysqli_real_escape_string($conn, $_POST['nim_edit']);
        $nim = mysqli_real_escape_string($conn, $_POST['nim']);
        $nama = mysqli_real_escape_string($conn, $_POST['nama']);
        $jurusan = mysqli_real_escape_string($conn, $_POST['jurusan']);
        $tgl_lahir = $_POST['tgl_lahir'];

        $query = "UPDATE mahasiswa SET 
                  nim = '$nim',
                  nama_mhs = '$nama', 
                  jurusan = '$jurusan', 
                  tgl_lahir = '$tgl_lahir' 
                  WHERE nim = '$nim_old'";

        if (mysqli_query($conn, $query)) {
            $success = "Data mahasiswa berhasil diperbarui!";
            $action_mode = '';
            $edit_data = null;
        } else {
            $error_msg = "Gagal: " . mysqli_error($conn);
        }
    }

    // 3. HAPUS MAHASISWA
    if (isset($_GET['hapus_mahasiswa'])) {
        $nim = mysqli_real_escape_string($conn, $_GET['hapus_mahasiswa']);
        
        // Cek apakah mahasiswa masih memiliki peminjaman aktif
        $cek_pinjam = mysqli_query($conn, "SELECT * FROM peminjaman WHERE nim = '$nim' AND id_peminjaman NOT IN (SELECT id_peminjaman FROM pengembalian)");
        if (mysqli_num_rows($cek_pinjam) > 0) {
            $error_msg = "Mahasiswa masih memiliki peminjaman aktif! Tidak dapat dihapus.";
        } else {
            $query = "DELETE FROM mahasiswa WHERE nim = '$nim'";
            if (mysqli_query($conn, $query)) {
                $success = "Mahasiswa berhasil dihapus!";
                $action_mode = '';
                $edit_data = null;
            } else {
                $error_msg = "Gagal: " . mysqli_error($conn);
            }
        }
    }

    // 4. GET DATA EDIT MAHASISWA
    if (isset($_GET['edit_mahasiswa'])) {
        $nim_edit = mysqli_real_escape_string($conn, $_GET['edit_mahasiswa']);
        $query_edit = "SELECT * FROM mahasiswa WHERE nim = '$nim_edit'";
        $result_edit = mysqli_query($conn, $query_edit);
        if ($result_edit && mysqli_num_rows($result_edit) > 0) {
            $edit_data = mysqli_fetch_assoc($result_edit);
            $action_mode = 'edit_mahasiswa';
        }
    }

    // ========== BUKU ==========
    // 1. TAMBAH BUKU - MODIFIKASI: AUTO GENERATE KODE BUKU
    if (isset($_POST['tambah_buku'])) {
        // Generate kode buku otomatis
        $kode_buku = generateKodeBuku($conn);
        $judul = mysqli_real_escape_string($conn, $_POST['judul']);
        $pengarang = mysqli_real_escape_string($conn, $_POST['pengarang']);
        $penerbit = mysqli_real_escape_string($conn, $_POST['penerbit']);
        $tahun = $_POST['tahun'];
        $kode_rak = $_POST['kode_rak'];

        $cek = mysqli_query($conn, "SELECT * FROM buku WHERE kode_buku = '$kode_buku'");
        if (mysqli_num_rows($cek) > 0) {
            $error_msg = "Kode buku $kode_buku sudah ada!";
        } else {
            $query = "INSERT INTO buku (kode_buku, judul_buku, pengarang, penerbit, tahun_terbit, kode_rak) 
                      VALUES ('$kode_buku', '$judul', '$pengarang', '$penerbit', '$tahun', '$kode_rak')";
            if (mysqli_query($conn, $query)) {
                $success = "Buku '$judul' berhasil ditambahkan! Kode: $kode_buku";
                $action_mode = '';
                $edit_data = null;
            } else {
                $error_msg = "Gagal: " . mysqli_error($conn);
            }
        }
    }

    // 2. EDIT BUKU
    if (isset($_POST['edit_buku'])) {
        $kode_buku_old = mysqli_real_escape_string($conn, $_POST['kode_buku_edit']);
        $kode_buku = mysqli_real_escape_string($conn, $_POST['kode_buku']);
        $judul = mysqli_real_escape_string($conn, $_POST['judul']);
        $pengarang = mysqli_real_escape_string($conn, $_POST['pengarang']);
        $penerbit = mysqli_real_escape_string($conn, $_POST['penerbit']);
        $tahun = $_POST['tahun'];
        $kode_rak = $_POST['kode_rak'];

        $query = "UPDATE buku SET 
                  judul_buku = '$judul', 
                  pengarang = '$pengarang', 
                  penerbit = '$penerbit', 
                  tahun_terbit = '$tahun', 
                  kode_rak = '$kode_rak' 
                  WHERE kode_buku = '$kode_buku_old'";

        if (mysqli_query($conn, $query)) {
            $success = "Data buku berhasil diperbarui!";
            $action_mode = '';
            $edit_data = null;
        } else {
            $error_msg = "Gagal: " . mysqli_error($conn);
        }
    }

    // 3. HAPUS BUKU dengan SweetAlert2 konfirmasi
    if (isset($_GET['hapus_buku'])) {
        $kode = mysqli_real_escape_string($conn, $_GET['hapus_buku']);
        
        // Cek apakah buku sedang dipinjam
        $cek_pinjam = mysqli_query($conn, "SELECT * FROM peminjaman p 
                                          LEFT JOIN pengembalian pg ON p.id_peminjaman = pg.id_peminjaman
                                          WHERE p.kode_buku = '$kode' AND pg.id_pengembalian IS NULL");
        
        if (mysqli_num_rows($cek_pinjam) > 0) {
            // Simpan pesan error dalam session untuk ditampilkan dengan SweetAlert2
            $_SESSION['sweetalert_error'] = "Buku ini sedang dipinjam dan tidak dapat dihapus!";
            $_SESSION['error_title'] = "Gagal Hapus";
            $_SESSION['error_icon'] = "error";
        } else {
            $query = "DELETE FROM buku WHERE kode_buku = '$kode'";
            if (mysqli_query($conn, $query)) {
                $success = "Buku berhasil dihapus!";
                $action_mode = '';
                $edit_data = null;
            } else {
                $error_msg = "Gagal: " . mysqli_error($conn);
            }
        }
    }

    // 4. GET DATA EDIT BUKU
    if (isset($_GET['edit_buku'])) {
        $kode_edit = mysqli_real_escape_string($conn, $_GET['edit_buku']);
        $query_edit = "SELECT * FROM buku WHERE kode_buku = '$kode_edit'";
        $result_edit = mysqli_query($conn, $query_edit);
        if ($result_edit && mysqli_num_rows($result_edit) > 0) {
            $edit_data = mysqli_fetch_assoc($result_edit);
            $action_mode = 'edit_buku';
        }
    }

    // ========== PEMINJAMAN ==========
    // 1. TAMBAH PEMINJAMAN
    if (isset($_POST['tambah_peminjaman'])) {
        $nim = mysqli_real_escape_string($conn, $_POST['nim']);
        $kode_buku = mysqli_real_escape_string($conn, $_POST['kode_buku']);
        $lama_pinjam = mysqli_real_escape_string($conn, $_POST['lama_pinjam']);

        // Cek apakah buku sedang dipinjam
        $cek_buku = mysqli_query($conn, "SELECT * FROM peminjaman p 
                                        LEFT JOIN pengembalian pg ON p.id_peminjaman = pg.id_peminjaman
                                        WHERE p.kode_buku = '$kode_buku' AND pg.id_pengembalian IS NULL");
        
        if (mysqli_num_rows($cek_buku) > 0) {
            $_SESSION['sweetalert_error'] = "Buku ini sedang dipinjam!";
            $_SESSION['error_title'] = "Gagal Pinjam";
            $_SESSION['error_icon'] = "warning";
        } else {
            $batas = date('Y-m-d H:i:s', strtotime("+$lama_pinjam days"));
            $id_petugas = 1;

            $query = "INSERT INTO peminjaman (nim, kode_buku, tgl_pinjam, batas_max_peminjaman, id_petugas) 
                      VALUES ('$nim', '$kode_buku', NOW(), '$batas', '$id_petugas')";
            if (mysqli_query($conn, $query)) {
                $success = "Peminjaman berhasil ditambahkan!";
                $action_mode = '';
                $edit_data = null;
            } else {
                $error_msg = "Gagal: " . mysqli_error($conn);
            }
        }
    }

    // 2. HAPUS PEMINJAMAN
    if (isset($_GET['hapus_peminjaman'])) {
        $id = mysqli_real_escape_string($conn, $_GET['hapus_peminjaman']);
        
        // Cek apakah peminjaman sudah dikembalikan
        $cek_kembali = mysqli_query($conn, "SELECT * FROM pengembalian WHERE id_peminjaman = '$id'");
        if (mysqli_num_rows($cek_kembali) > 0) {
            $_SESSION['sweetalert_error'] = "Peminjaman ini sudah dikembalikan!";
            $_SESSION['error_title'] = "Gagal Hapus";
            $_SESSION['error_icon'] = "warning";
        } else {
            $query = "DELETE FROM peminjaman WHERE id_peminjaman = '$id'";
            if (mysqli_query($conn, $query)) {
                $success = "Peminjaman berhasil dihapus!";
                $action_mode = '';
                $edit_data = null;
            }
        }
    }

    // 3. GET DATA EDIT PEMINJAMAN
    if (isset($_GET['edit_peminjaman'])) {
        $id_edit = mysqli_real_escape_string($conn, $_GET['edit_peminjaman']);
        $query_edit = "SELECT * FROM peminjaman WHERE id_peminjaman = '$id_edit'";
        $result_edit = mysqli_query($conn, $query_edit);
        if ($result_edit && mysqli_num_rows($result_edit) > 0) {
            $edit_data = mysqli_fetch_assoc($result_edit);
            $action_mode = 'edit_peminjaman';
        }
    }

    // 4. EDIT PEMINJAMAN
    if (isset($_POST['edit_peminjaman'])) {
        $id_peminjaman_old = mysqli_real_escape_string($conn, $_POST['id_peminjaman_edit']);
        $id_peminjaman = mysqli_real_escape_string($conn, $_POST['id_peminjaman']);
        $nim = mysqli_real_escape_string($conn, $_POST['nim']);
        $kode_buku = mysqli_real_escape_string($conn, $_POST['kode_buku']);
        $lama_pinjam = mysqli_real_escape_string($conn, $_POST['lama_pinjam']);

        $batas = date('Y-m-d H:i:s', strtotime("+$lama_pinjam days"));

        $query = "UPDATE peminjaman SET 
                  nim = '$nim', 
                  kode_buku = '$kode_buku', 
                  batas_max_peminjaman = '$batas' 
                  WHERE id_peminjaman = '$id_peminjaman_old'";

        if (mysqli_query($conn, $query)) {
            $success = "Peminjaman berhasil diperbarui!";
            $action_mode = '';
            $edit_data = null;
        } else {
            $error_msg = "Gagal: " . mysqli_error($conn);
        }
    }

    // ========== PENGEMBALIAN ==========
    // 1. TAMBAH PENGEMBALIAN
    if (isset($_POST['tambah_pengembalian'])) {
        $id_peminjaman = mysqli_real_escape_string($conn, $_POST['id_peminjaman']);
        $denda = mysqli_real_escape_string($conn, $_POST['denda']);
        $kondisi = mysqli_real_escape_string($conn, $_POST['kondisi']);

        // Cek apakah sudah pernah dikembalikan
        $cek_sudah_kembali = mysqli_query($conn, "SELECT * FROM pengembalian WHERE id_peminjaman = '$id_peminjaman'");
        if (mysqli_num_rows($cek_sudah_kembali) > 0) {
            $_SESSION['sweetalert_error'] = "Buku ini sudah dikembalikan sebelumnya!";
            $_SESSION['error_title'] = "Gagal Pengembalian";
            $_SESSION['error_icon'] = "warning";
        } else {
            $query_pinjam = "SELECT kode_buku, id_petugas FROM peminjaman WHERE id_peminjaman = '$id_peminjaman'";
            $result_pinjam = mysqli_query($conn, $query_pinjam);

            if ($row_pinjam = mysqli_fetch_assoc($result_pinjam)) {
                $kode_buku = $row_pinjam['kode_buku'];
                $id_petugas = $row_pinjam['id_petugas'];

                $query = "INSERT INTO pengembalian (id_peminjaman, kode_buku, tgl_kembali, denda, hilang_rusak, id_petugas) 
                          VALUES ('$id_peminjaman', '$kode_buku', NOW(), '$denda', '$kondisi', '$id_petugas')";
                if (mysqli_query($conn, $query)) {
                    $success = "Buku berhasil dikembalikan!";
                    $action_mode = '';
                    $edit_data = null;
                } else {
                    $error_msg = "Gagal: " . mysqli_error($conn);
                }
            }
        }
    }

    // 2. HAPUS PENGEMBALIAN
    if (isset($_GET['hapus_pengembalian'])) {
        $id = mysqli_real_escape_string($conn, $_GET['hapus_pengembalian']);
        $query = "DELETE FROM pengembalian WHERE id_pengembalian = '$id'";
        if (mysqli_query($conn, $query)) {
            $success = "Pengembalian berhasil dihapus!";
            $action_mode = '';
            $edit_data = null;
        }
    }

    // 3. GET DATA EDIT PENGEMBALIAN
    if (isset($_GET['edit_pengembalian'])) {
        $id_edit = mysqli_real_escape_string($conn, $_GET['edit_pengembalian']);
        $query_edit = "SELECT * FROM pengembalian WHERE id_pengembalian = '$id_edit'";
        $result_edit = mysqli_query($conn, $query_edit);
        if ($result_edit && mysqli_num_rows($result_edit) > 0) {
            $edit_data = mysqli_fetch_assoc($result_edit);
            $action_mode = 'edit_pengembalian';
        }
    }

    // 4. EDIT PENGEMBALIAN
    if (isset($_POST['edit_pengembalian'])) {
        $id_pengembalian_old = mysqli_real_escape_string($conn, $_POST['id_pengembalian_edit']);
        $id_pengembalian = mysqli_real_escape_string($conn, $_POST['id_pengembalian']);
        $denda = mysqli_real_escape_string($conn, $_POST['denda']);
        $kondisi = mysqli_real_escape_string($conn, $_POST['kondisi']);

        $query = "UPDATE pengembalian SET 
                  denda = '$denda', 
                  hilang_rusak = '$kondisi', 
                  tgl_kembali = NOW() 
                  WHERE id_pengembalian = '$id_pengembalian_old'";

        if (mysqli_query($conn, $query)) {
            $success = "Data pengembalian berhasil diperbarui!";
            $action_mode = '';
            $edit_data = null;
        } else {
            $error_msg = "Gagal: " . mysqli_error($conn);
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
        // Ambil data untuk statistik
        $total_mahasiswa = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM mahasiswa"))['total'];
        $total_buku = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM buku"))['total'];
        $total_peminjaman = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM peminjaman"))['total'];
        $total_pengembalian = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM pengembalian"))['total'];
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
                        $query_mhs = "SELECT *, TIMESTAMPDIFF(YEAR, tgl_lahir, CURDATE()) as umur FROM mahasiswa ORDER BY nim";
                        $result_mhs = mysqli_query($conn, $query_mhs);
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
                                            <?php if (mysqli_num_rows($result_mhs) > 0): ?>
                                                <?php while ($row = mysqli_fetch_assoc($result_mhs)): ?>
                                                    <tr>
                                                        <td><?php echo $row['nim']; ?></td>
                                                        <td><?php echo $row['nama_mhs']; ?></td>
                                                        <td><?php echo $row['jurusan']; ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo date('d-m-Y', strtotime($row['tgl_lahir'])); ?></td>
                                                        <td><?php echo $row['umur']; ?> tahun</td>
                                                        <td>
                                                            <div class="btn-group-actions">
                                                                <a href="?page=mahasiswa&edit_mahasiswa=<?php echo $row['nim']; ?>"
                                                                    class="btn btn-warning btn-sm btn-action">
                                                                    <i class="bi bi-pencil"></i>
                                                                </a>
                                                                <a href="?page=mahasiswa&hapus_mahasiswa=<?php echo $row['nim']; ?>"
                                                                    class="btn btn-danger btn-sm btn-action hapus-btn"
                                                                    data-id="<?php echo $row['nim']; ?>"
                                                                    data-nama="<?php echo htmlspecialchars($row['nama_mhs']); ?>"
                                                                    data-type="mahasiswa">
                                                                    <i class="bi bi-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
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
                        // Query untuk mendapatkan status buku (apakah dipinjam)
                        $query_buku = "SELECT b.*, r.lokasi_rak,
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
                        $result_buku = mysqli_query($conn, $query_buku);
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
                                            <?php if (mysqli_num_rows($result_buku) > 0): ?>
                                                <?php while ($row = mysqli_fetch_assoc($result_buku)): ?>
                                                    <tr>
                                                        <td><?php echo $row['kode_buku']; ?></td>
                                                        <td><?php echo $row['judul_buku']; ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo $row['pengarang']; ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo $row['penerbit']; ?></td>
                                                        <td><?php echo $row['tahun_terbit']; ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo $row['lokasi_rak'] ?: 'Rak ' . $row['kode_rak']; ?></td>
                                                        <td>
                                                            <span class="status-badge <?php echo $row['status_buku'] == 'Dipinjam' ? 'status-dipinjam' : 'status-tersedia'; ?>">
                                                                <?php echo $row['status_buku']; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group-actions">
                                                                <a href="?page=buku&edit_buku=<?php echo $row['kode_buku']; ?>"
                                                                    class="btn btn-warning btn-sm btn-action">
                                                                    <i class="bi bi-pencil"></i>
                                                                </a>
                                                                <a href="?page=buku&hapus_buku=<?php echo $row['kode_buku']; ?>"
                                                                    class="btn btn-danger btn-sm btn-action hapus-btn"
                                                                    data-id="<?php echo $row['kode_buku']; ?>"
                                                                    data-judul="<?php echo htmlspecialchars($row['judul_buku']); ?>"
                                                                    data-status="<?php echo $row['status_buku']; ?>"
                                                                    data-type="buku">
                                                                    <i class="bi bi-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
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
                        $query_pinjam = "SELECT p.*, m.nama_mhs, b.judul_buku,
                                        CASE 
                                            WHEN EXISTS (SELECT 1 FROM pengembalian WHERE id_peminjaman = p.id_peminjaman) THEN 'Dikembalikan'
                                            WHEN p.batas_max_peminjaman < NOW() THEN 'Terlambat'
                                            ELSE 'Aktif'
                                        END as status_pinjam
                              FROM peminjaman p
                              JOIN mahasiswa m ON p.nim = m.nim
                              JOIN buku b ON p.kode_buku = b.kode_buku
                              ORDER BY p.tgl_pinjam DESC";
                        $result_pinjam = mysqli_query($conn, $query_pinjam);
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
                                            <?php if (mysqli_num_rows($result_pinjam) > 0): ?>
                                                <?php while ($row = mysqli_fetch_assoc($result_pinjam)):
                                                    $badge_class = '';
                                                    if ($row['status_pinjam'] == 'Aktif') $badge_class = 'bg-success';
                                                    elseif ($row['status_pinjam'] == 'Terlambat') $badge_class = 'bg-danger';
                                                    else $badge_class = 'bg-secondary';
                                                ?>
                                                    <tr>
                                                        <td class="d-none d-md-table-cell"><?php echo $row['id_peminjaman']; ?></td>
                                                        <td><?php echo $row['nim']; ?></td>
                                                        <td><?php echo $row['nama_mhs']; ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo $row['judul_buku']; ?></td>
                                                        <td><?php echo date('d-m-Y', strtotime($row['tgl_pinjam'])); ?></td>
                                                        <td class="d-none d-md-table-cell"><?php echo date('d-m-Y', strtotime($row['batas_max_peminjaman'])); ?></td>
                                                        <td>
                                                            <span class="badge <?php echo $badge_class; ?>">
                                                                <?php echo $row['status_pinjam']; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group-actions">
                                                                <?php if ($row['status_pinjam'] == 'Aktif'): ?>
                                                                    <a href="?page=peminjaman&edit_peminjaman=<?php echo $row['id_peminjaman']; ?>"
                                                                        class="btn btn-warning btn-sm btn-action">
                                                                        <i class="bi bi-pencil"></i>
                                                                    </a>
                                                                <?php endif; ?>
                                                                <a href="?page=peminjaman&hapus_peminjaman=<?php echo $row['id_peminjaman']; ?>"
                                                                    class="btn btn-danger btn-sm btn-action hapus-btn"
                                                                    data-id="<?php echo $row['id_peminjaman']; ?>"
                                                                    data-status="<?php echo $row['status_pinjam']; ?>"
                                                                    data-type="peminjaman">
                                                                    <i class="bi bi-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
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
                        $query_kembali = "SELECT pg.*, p.nim, m.nama_mhs, b.judul_buku 
                                FROM pengembalian pg
                                JOIN peminjaman p ON pg.id_peminjaman = p.id_peminjaman
                                JOIN mahasiswa m ON p.nim = m.nim
                                JOIN buku b ON pg.kode_buku = b.kode_buku
                                ORDER BY pg.tgl_kembali DESC";
                        $result_kembali = mysqli_query($conn, $query_kembali);
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
                                            <?php if (mysqli_num_rows($result_kembali) > 0): ?>
                                                <?php while ($row = mysqli_fetch_assoc($result_kembali)): ?>
                                                    <tr>
                                                        <td class="d-none d-md-table-cell"><?php echo $row['id_pengembalian']; ?></td>
                                                        <td><?php echo $row['id_peminjaman']; ?></td>
                                                        <td>
                                                            <span class="d-none d-md-inline"><?php echo $row['nama_mhs']; ?></span>
                                                            <small class="d-md-none"><?php echo substr($row['nama_mhs'], 0, 15) . '...'; ?></small>
                                                            <br class="d-md-none">
                                                            <small>NIM: <?php echo $row['nim']; ?></small>
                                                        </td>
                                                        <td class="d-none d-md-table-cell"><?php echo $row['judul_buku']; ?></td>
                                                        <td><?php echo date('d-m-Y', strtotime($row['tgl_kembali'])); ?></td>
                                                        <td>Rp <?php echo number_format($row['denda'], 0, ',', '.'); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $row['hilang_rusak'] == 'Baik' ? 'success' : 'warning'; ?>">
                                                                <?php echo $row['hilang_rusak']; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group-actions">
                                                                <a href="?page=pengembalian&edit_pengembalian=<?php echo $row['id_pengembalian']; ?>"
                                                                    class="btn btn-warning btn-sm btn-action">
                                                                    <i class="bi bi-pencil"></i>
                                                                </a>
                                                                <a href="?page=pengembalian&hapus_pengembalian=<?php echo $row['id_pengembalian']; ?>"
                                                                    class="btn btn-danger btn-sm btn-action hapus-btn"
                                                                    data-id="<?php echo $row['id_pengembalian']; ?>"
                                                                    data-type="pengembalian">
                                                                    <i class="bi bi-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
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
                        <div class="about-header" style="background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%); color: white; padding: 2rem 0; margin-bottom: 2rem; position: relative; overflow: hidden;">
                            <div class="container">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h1 class="mb-3">
                                            <i class="bi bi-info-circle" style="animation: float 3s ease-in-out infinite;"></i> Tentang Sistem
                                        </h1>
                                        <p class="lead">
                                            Sistem Manajemen Perpustakaan Digital untuk memudahkan pengelolaan data mahasiswa,
                                            buku, dan transaksi perpustakaan.
                                        </p>
                                        <span class="badge bg-success">
                                            <i class="bi bi-info"></i> Versi 1.0.1
                                        </span>
                                    </div>
                                    <div class="col-md-4 text-center">
                                        <i class="bi bi-book-half" style="font-size: 6rem; opacity: 0.8; animation: rotate 20s linear infinite;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-8">
                                <!-- Deskripsi Sistem -->
                                <div class="card mb-4" style="animation: fadeInUp 0.8s ease-out;">
                                    <div class="card-body">
                                        <h4 class="card-title"><i class="bi bi-book feature-icon" style="transition: transform 0.3s;"></i> Tentang Sistem Perpustakaan</h4>
                                        <p>
                                            Sistem Perpustakaan Digital ini dikembangkan untuk membantu pengelolaan perpustakaan
                                            di lingkungan perguruan tinggi. Sistem ini memungkinkan administrasi perpustakaan
                                            untuk mengelola data mahasiswa, koleksi buku, dan transaksi peminjaman secara
                                            digital dan terintegrasi.
                                        </p>
                                        <p>
                                            Dengan sistem ini, proses peminjaman dan pengembalian buku menjadi lebih cepat,
                                            akurat, dan terdata dengan baik. Sistem juga dilengkapi dengan fitur pelacakan
                                            riwayat transaksi dan perhitungan denda otomatis.
                                        </p>
                                    </div>
                                </div>

                                <!-- Fitur Utama -->
                                <div class="card mb-4" style="animation: fadeInUp 0.8s ease-out 0.2s both;">
                                    <div class="card-body">
                                        <h4 class="card-title"><i class="bi bi-check-circle feature-icon" style="transition: transform 0.3s;"></i> Fitur Utama</h4>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <ul class="list-unstyled">
                                                    <li class="mb-2" style="animation: slideInLeft 0.5s ease-out 0.3s both;"><i class="bi bi-check text-success me-2"></i> Manajemen Data Mahasiswa</li>
                                                    <li class="mb-2" style="animation: slideInLeft 0.5s ease-out 0.4s both;"><i class="bi bi-check text-success me-2"></i> Katalog Buku Digital</li>
                                                    <li class="mb-2" style="animation: slideInLeft 0.5s ease-out 0.5s both;"><i class="bi bi-check text-success me-2"></i> Sistem Peminjaman Online</li>
                                                    <li class="mb-2" style="animation: slideInLeft 0.5s ease-out 0.6s both;"><i class="bi bi-check text-success me-2"></i> Pengembalian Buku</li>
                                                </ul>
                                            </div>
                                            <div class="col-md-6">
                                                <ul class="list-unstyled">
                                                    <li class="mb-2" style="animation: slideInRight 0.5s ease-out 0.3s both;"><i class="bi bi-check text-success me-2"></i> Perhitungan Denda Otomatis</li>
                                                    <li class="mb-2" style="animation: slideInRight 0.5s ease-out 0.4s both;"><i class="bi bi-check text-success me-2"></i> Laporan dan Statistik</li>
                                                    <li class="mb-2" style="animation: slideInRight 0.5s ease-out 0.5s both;"><i class="bi bi-check text-success me-2"></i> Dashboard Monitoring</li>
                                                    <li class="mb-2" style="animation: slideInRight 0.5s ease-out 0.6s both;"><i class="bi bi-check text-success me-2"></i> Sistem Login Aman</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Teknologi -->
                                <div class="card" style="animation: fadeInUp 0.8s ease-out 0.4s both;">
                                    <div class="card-body">
                                        <h4 class="card-title"><i class="bi bi-code-slash feature-icon" style="transition: transform 0.3s;"></i> Teknologi yang Digunakan</h4>
                                        <div class="row mt-3">
                                            <div class="col-12 col-md-4 text-center mb-3">
                                                <div class="p-3 border rounded tech-card" style="transition: all 0.3s; animation: fadeInUp 0.5s ease-out 0.5s both;">
                                                    <i class="bi bi-filetype-php" style="font-size: 2rem; color: #777bb4;"></i>
                                                    <h5 class="mt-2">PHP</h5>
                                                    <p class="text-muted mb-0">Backend Language</p>
                                                </div>
                                            </div>
                                            <div class="col-12 col-md-4 text-center mb-3">
                                                <div class="p-3 border rounded tech-card" style="transition: all 0.3s; animation: fadeInUp 0.5s ease-out 0.6s both;">
                                                    <i class="bi bi-database" style="font-size: 2rem; color: #00758f;"></i>
                                                    <h5 class="mt-2">MySQL</h5>
                                                    <p class="text-muted mb-0">Database System</p>
                                                </div>
                                            </div>
                                            <div class="col-12 col-md-4 text-center mb-3">
                                                <div class="p-3 border rounded tech-card" style="transition: all 0.3s; animation: fadeInUp 0.5s ease-out 0.7s both;">
                                                    <i class="bi bi-bootstrap" style="font-size: 2rem; color: #7952b3;"></i>
                                                    <h5 class="mt-2">Bootstrap 5</h5>
                                                    <p class="text-muted mb-0">Frontend Framework</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <!-- Informasi Sistem -->
                                <div class="card mb-4" style="animation: fadeInRight 0.8s ease-out 0.2s both;">
                                    <div class="card-body">
                                        <h4 class="card-title"><i class="bi bi-info-circle feature-icon" style="transition: transform 0.3s;"></i> Informasi Sistem</h4>
                                        <ul class="list-group list-group-flush">
                                            <li class="list-group-item d-flex justify-content-between">
                                                <span>Versi</span>
                                                <span class="badge bg-success" style="transition: transform 0.3s;">1.0.1</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between">
                                                <span>Status</span>
                                                <span class="badge bg-success" style="transition: transform 0.3s;">Aktif</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between">
                                                <span>Database</span>
                                                <span class="badge bg-success" style="transition: transform 0.3s;">Connected</span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between">
                                                <span>Last Update</span>
                                                <span><?php echo date('d/m/Y'); ?></span>
                                            </li>
                                            <li class="list-group-item d-flex justify-content-between">
                                                <span>Server Time</span>
                                                <span id="serverTime"><?php echo date('H:i:s'); ?></span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>

                                <!-- Pengembang -->
                                <div class="card" style="animation: fadeInRight 0.8s ease-out 0.4s both;">
                                    <div class="card-body">
                                        <h4 class="card-title"><i class="bi bi-people feature-icon" style="transition: transform 0.3s;"></i> Tim Pengembang</h4>

                                        <!-- Kartu Anggota Kelompok -->
                                        <div class="row row-cols-2 g-2 mb-3">
                                            <div class="col">
                                                <div class="border rounded p-2 text-center team-card" style="transition: all 0.3s; animation: fadeInUp 0.5s ease-out 0.5s both;">
                                                    <i class="bi bi-person-circle text-primary" style="font-size: 1.5rem;"></i>
                                                    <div class="fw-bold small mt-1">Fariz</div>
                                                </div>
                                            </div>
                                            <div class="col">
                                                <div class="border rounded p-2 text-center team-card" style="transition: all 0.3s; animation: fadeInUp 0.5s ease-out 0.6s both;">
                                                    <i class="bi bi-person-circle text-success" style="font-size: 1.5rem;"></i>
                                                    <div class="fw-bold small mt-1">Haikal</div>
                                                </div>
                                            </div>
                                            <div class="col">
                                                <div class="border rounded p-2 text-center team-card" style="transition: all 0.3s; animation: fadeInUp 0.5s ease-out 0.7s both;">
                                                    <i class="bi bi-person-circle text-warning" style="font-size: 1.5rem;"></i>
                                                    <div class="fw-bold small mt-1">Jordan</div>
                                                </div>
                                            </div>
                                            <div class="col">
                                                <div class="border rounded p-2 text-center team-card" style="transition: all 0.3s; animation: fadeInUp 0.5s ease-out 0.8s both;">
                                                    <i class="bi bi-person-circle text-danger" style="font-size: 1.5rem;"></i>
                                                    <div class="fw-bold small mt-1">Bambang</div>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="border rounded p-2 text-center team-card" style="transition: all 0.3s; animation: fadeInUp 0.5s ease-out 0.9s both;">
                                                    <i class="bi bi-person-circle text-info" style="font-size: 1.5rem;"></i>
                                                    <div class="fw-bold small mt-1">Fathur</div>
                                                </div>
                                            </div>
                                        </div>

                                        <p class="text-muted small" style="animation: fadeInUp 0.8s ease-out 1s both;">
                                            Sistem ini dikembangkan oleh kelompok untuk keperluan pembelajaran dan administrasi perpustakaan, sistem ini 100% dibuat oleh Ai hanya sekedar untuk pembelajaran.
                                        </p>

                                        <div class="text-center" style="animation: fadeInUp 0.8s ease-out 1.1s both;">
                                            <a href="index.php?page=dashboard" class="btn btn-primary btn-sm pulse-btn">
                                                <i class="bi bi-arrow-left"></i> Kembali ke Dashboard
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <style>
                            @keyframes float {

                                0%,
                                100% {
                                    transform: translateY(0);
                                }

                                50% {
                                    transform: translateY(-10px);
                                }
                            }

                            @keyframes pulse {

                                0%,
                                100% {
                                    transform: scale(1);
                                }

                                50% {
                                    transform: scale(1.05);
                                }
                            }

                            @keyframes rotate {
                                from {
                                    transform: rotate(0deg);
                                }

                                to {
                                    transform: rotate(360deg);
                                }
                            }

                            @keyframes fadeInUp {
                                from {
                                    opacity: 0;
                                    transform: translateY(30px);
                                }

                                to {
                                    opacity: 1;
                                    transform: translateY(0);
                                }
                            }

                            @keyframes fadeInRight {
                                from {
                                    opacity: 0;
                                    transform: translateX(30px);
                                }

                                to {
                                    opacity: 1;
                                    transform: translateX(0);
                                }
                            }

                            @keyframes slideInLeft {
                                from {
                                    opacity: 0;
                                    transform: translateX(-30px);
                                }

                                to {
                                    opacity: 1;
                                    transform: translateX(0);
                                }
                            }

                            @keyframes slideInRight {
                                from {
                                    opacity: 0;
                                    transform: translateX(30px);
                                }

                                to {
                                    opacity: 1;
                                    transform: translateX(0);
                                }
                            }

                            .feature-icon:hover {
                                transform: scale(1.2);
                            }

                            .tech-card:hover {
                                transform: translateY(-5px);
                                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
                            }

                            .team-card:hover {
                                transform: scale(1.05);
                                box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
                            }

                            .badge:hover {
                                transform: scale(1.1);
                            }

                            .pulse-btn:hover {
                                animation: pulse 0.5s;
                            }
                        </style>

                        <script>
                            // Update waktu server
                            function updateServerTime() {
                                const now = new Date();
                                const hours = now.getHours().toString().padStart(2, '0');
                                const minutes = now.getMinutes().toString().padStart(2, '0');
                                const seconds = now.getSeconds().toString().padStart(2, '0');
                                document.getElementById('serverTime').textContent = `${hours}:${minutes}:${seconds}`;
                            }

                            // Update setiap detik
                            setInterval(updateServerTime, 1000);
                        </script>
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
                                <input type="hidden" name="nim_edit" value="<?php echo $edit_data['nim']; ?>">
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label class="form-label">NIM</label>
                                <input type="text" class="form-control" name="nim" 
                                       value="<?php echo ($action_mode == 'edit_mahasiswa' && $edit_data) ? $edit_data['nim'] : ''; ?>" 
                                       <?php echo ($action_mode == 'edit_mahasiswa' && $edit_data) ? 'readonly' : 'required'; ?>>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Nama</label>
                                <input type="text" class="form-control" name="nama"
                                    value="<?php echo ($action_mode == 'edit_mahasiswa' && $edit_data) ? $edit_data['nama_mhs'] : ''; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Jurusan</label>
                                <input type="text" class="form-control" name="jurusan"
                                    value="<?php echo ($action_mode == 'edit_mahasiswa' && $edit_data) ? $edit_data['jurusan'] : ''; ?>" required>
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
                                <input type="hidden" name="kode_buku_edit" value="<?php echo $edit_data['kode_buku']; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Kode Buku</label>
                                    <input type="number" class="form-control" name="kode_buku"
                                           value="<?php echo $edit_data['kode_buku']; ?>" readonly>
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
                                    value="<?php echo ($action_mode == 'edit_buku' && $edit_data) ? $edit_data['judul_buku'] : ''; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Pengarang</label>
                                <input type="text" class="form-control" name="pengarang"
                                    value="<?php echo ($action_mode == 'edit_buku' && $edit_data) ? $edit_data['pengarang'] : ''; ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Penerbit</label>
                                <input type="text" class="form-control" name="penerbit"
                                    value="<?php echo ($action_mode == 'edit_buku' && $edit_data) ? $edit_data['penerbit'] : ''; ?>" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Tahun Terbit</label>
                                    <input type="number" class="form-control" name="tahun"
                                        value="<?php echo ($action_mode == 'edit_buku' && $edit_data) ? $edit_data['tahun_terbit'] : ''; ?>" required>
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
                                <input type="hidden" name="id_peminjaman_edit" value="<?php echo $edit_data['id_peminjaman']; ?>">
                                <div class="mb-3">
                                    <label class="form-label">ID Peminjaman</label>
                                    <input type="number" class="form-control" name="id_peminjaman"
                                           value="<?php echo $edit_data['id_peminjaman']; ?>" readonly>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label">Mahasiswa (NIM)</label>
                                <select class="form-select" name="nim" required>
                                    <option value="">Pilih Mahasiswa</option>
                                    <?php
                                    $query_mhs = "SELECT * FROM mahasiswa ORDER BY nama_mhs";
                                    $result_mhs = mysqli_query($conn, $query_mhs);
                                    while ($mhs = mysqli_fetch_assoc($result_mhs)) {
                                        $selected = ($action_mode == 'edit_peminjaman' && $edit_data && $edit_data['nim'] == $mhs['nim']) ? 'selected' : '';
                                        echo "<option value='" . $mhs['nim'] . "' $selected>" . $mhs['nim'] . " - " . $mhs['nama_mhs'] . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Buku</label>
                                <select class="form-select" name="kode_buku" required>
                                    <option value="">Pilih Buku</option>
                                    <?php
                                    $query_buku = "SELECT b.*, 
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
                                    $result_buku = mysqli_query($conn, $query_buku);
                                    while ($buku = mysqli_fetch_assoc($result_buku)) {
                                        $selected = ($action_mode == 'edit_peminjaman' && $edit_data && $edit_data['kode_buku'] == $buku['kode_buku']) ? 'selected' : '';
                                        $disabled = ($buku['status_buku'] == 'Dipinjam' && !$selected) ? 'disabled' : '';
                                        echo "<option value='" . $buku['kode_buku'] . "' $selected $disabled>" . 
                                             $buku['kode_buku'] . " - " . $buku['judul_buku'] . 
                                             ($buku['status_buku'] == 'Dipinjam' ? ' (Sedang Dipinjam)' : '') . 
                                             "</option>";
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
                                <input type="hidden" name="id_pengembalian_edit" value="<?php echo $edit_data['id_pengembalian']; ?>">
                                <div class="mb-3">
                                    <label class="form-label">ID Pengembalian</label>
                                    <input type="number" class="form-control" name="id_pengembalian"
                                           value="<?php echo $edit_data['id_pengembalian']; ?>" readonly>
                                </div>
                            <?php else: ?>
                                <div class="mb-3">
                                    <label class="form-label">ID Peminjaman</label>
                                    <select class="form-select" name="id_peminjaman" required>
                                        <option value="">Pilih Peminjaman</option>
                                        <?php
                                        $query_pinjam = "SELECT p.id_peminjaman, p.nim, m.nama_mhs, b.judul_buku,
                                                        CASE 
                                                            WHEN EXISTS (SELECT 1 FROM pengembalian WHERE id_peminjaman = p.id_peminjaman) THEN 'Sudah Dikembalikan'
                                                            ELSE 'Belum Dikembalikan'
                                                        END as status_pinjam
                                               FROM peminjaman p
                                               JOIN mahasiswa m ON p.nim = m.nim
                                               JOIN buku b ON p.kode_buku = b.kode_buku
                                               ORDER BY p.tgl_pinjam DESC";
                                        $result_pinjam = mysqli_query($conn, $query_pinjam);
                                        while ($pinjam = mysqli_fetch_assoc($result_pinjam)) {
                                            $disabled = ($pinjam['status_pinjam'] == 'Sudah Dikembalikan') ? 'disabled' : '';
                                            echo "<option value='" . $pinjam['id_peminjaman'] . "' $disabled>" .
                                                $pinjam['id_peminjaman'] . " - " . $pinjam['nama_mhs'] . " (" . $pinjam['judul_buku'] . ") - " . 
                                                $pinjam['status_pinjam'] . "</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label">Denda (Rp)</label>
                                <input type="number" class="form-control" name="denda"
                                    value="<?php echo ($action_mode == 'edit_pengembalian' && $edit_data) ? $edit_data['denda'] : '0'; ?>" min="0">
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
            document.addEventListener('DOMContentLoaded', function() {
                const sidebarToggle = document.getElementById('sidebarToggle');
                const closeSidebar = document.getElementById('closeSidebar');
                const sidebar = document.getElementById('sidebar');
                const sidebarItems = document.querySelectorAll('.sidebar-item');

                // Buat overlay
                const overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);

                // Fungsi untuk membuka sidebar
                function openSidebar() {
                    sidebar.classList.add('show');
                    overlay.classList.add('show');
                    document.body.classList.add('sidebar-open');
                }

                // Fungsi untuk menutup sidebar
                function closeSidebarFunc() {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                }

                // Event listeners untuk sidebar
                if (sidebarToggle) {
                    sidebarToggle.addEventListener('click', openSidebar);
                }

                if (closeSidebar) {
                    closeSidebar.addEventListener('click', closeSidebarFunc);
                }

                overlay.addEventListener('click', closeSidebarFunc);

                // Tutup sidebar ketika item diklik (untuk mobile)
                sidebarItems.forEach(item => {
                    item.addEventListener('click', function() {
                        if (window.innerWidth < 768) {
                            closeSidebarFunc();
                        }
                    });
                });

                // Tutup sidebar dengan ESC key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && window.innerWidth < 768) {
                        closeSidebarFunc();
                    }
                });

                // Auto-close sidebar ketika resize ke desktop
                window.addEventListener('resize', function() {
                    if (window.innerWidth >= 768) {
                        closeSidebarFunc();
                    }
                });

                // Auto open modal jika ada edit data
                <?php if ($action_mode && $edit_data): ?>
                    <?php if ($action_mode == 'edit_mahasiswa'): ?>
                        $(document).ready(function() {
                            $('#tambahMahasiswaModal').modal('show');
                        });
                    <?php elseif ($action_mode == 'edit_buku'): ?>
                        $(document).ready(function() {
                            $('#tambahBukuModal').modal('show');
                        });
                    <?php elseif ($action_mode == 'edit_peminjaman'): ?>
                        $(document).ready(function() {
                            $('#tambahPeminjamanModal').modal('show');
                        });
                    <?php elseif ($action_mode == 'edit_pengembalian'): ?>
                        $(document).ready(function() {
                            $('#tambahPengembalianModal').modal('show');
                        });
                    <?php endif; ?>
                <?php endif; ?>

                // SweetAlert2 untuk error messages dari PHP
                <?php if (isset($_SESSION['sweetalert_error'])): ?>
                    Swal.fire({
                        title: '<?php echo $_SESSION['error_title'] ?? "Error"; ?>',
                        text: '<?php echo $_SESSION['sweetalert_error']; ?>',
                        icon: '<?php echo $_SESSION['error_icon'] ?? "error"; ?>',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        // Hapus session setelah ditampilkan
                        <?php 
                        unset($_SESSION['sweetalert_error']);
                        unset($_SESSION['error_title']);
                        unset($_SESSION['error_icon']);
                        ?>
                    });
                <?php endif; ?>

                // Custom confirmation dengan SweetAlert2 untuk tombol hapus
                document.querySelectorAll('.hapus-btn').forEach(button => {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        
                        const type = this.getAttribute('data-type');
                        const id = this.getAttribute('data-id');
                        const href = this.getAttribute('href');
                        
                        let title = '';
                        let text = '';
                        let icon = 'warning';
                        
                        switch(type) {
                            case 'mahasiswa':
                                const nama = this.getAttribute('data-nama');
                                title = 'Hapus Mahasiswa?';
                                text = `Apakah Anda yakin ingin menghapus mahasiswa ${nama}?`;
                                break;
                            case 'buku':
                                const judul = this.getAttribute('data-judul');
                                const status = this.getAttribute('data-status');
                                title = 'Hapus Buku?';
                                text = `Apakah Anda yakin ingin menghapus buku "${judul}"?`;
                                
                                // Jika buku sedang dipinjam, tampilkan warning
                                if (status === 'Dipinjam') {
                                    Swal.fire({
                                        title: 'Tidak Dapat Dihapus!',
                                        text: `Buku "${judul}" sedang dipinjam dan tidak dapat dihapus.`,
                                        icon: 'error',
                                        confirmButtonText: 'OK'
                                    });
                                    return false;
                                }
                                break;
                            case 'peminjaman':
                                const pinjamStatus = this.getAttribute('data-status');
                                title = 'Hapus Peminjaman?';
                                text = 'Apakah Anda yakin ingin menghapus data peminjaman ini?';
                                
                                // Jika peminjaman sudah dikembalikan, tampilkan warning
                                if (pinjamStatus === 'Dikembalikan') {
                                    Swal.fire({
                                        title: 'Tidak Dapat Dihapus!',
                                        text: 'Peminjaman ini sudah dikembalikan dan tidak dapat dihapus.',
                                        icon: 'error',
                                        confirmButtonText: 'OK'
                                    });
                                    return false;
                                }
                                break;
                            case 'pengembalian':
                                title = 'Hapus Pengembalian?';
                                text = 'Apakah Anda yakin ingin menghapus data pengembalian ini?';
                                break;
                        }
                        
                        Swal.fire({
                            title: title,
                            text: text,
                            icon: icon,
                            showCancelButton: true,
                            confirmButtonColor: '#d33',
                            cancelButtonColor: '#3085d6',
                            confirmButtonText: 'Ya, Hapus!',
                            cancelButtonText: 'Batal'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                window.location.href = href;
                            }
                        });
                    });
                });
            });
        </script>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>