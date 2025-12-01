<?php
session_start();
require 'koneksi.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['status']) || $_SESSION['status'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "Akses ditolak.", "data" => []]);
    exit;
}

$per_page = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$per_page = max(1, min(100, $per_page));

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'tanggal_input';
$dir  = isset($_GET['dir'])  ? strtoupper($_GET['dir']) : 'DESC';
$dir  = ($dir === 'ASC') ? 'ASC' : 'DESC'; // sanitize

$sortable = [
    'tanggal_input'        => 'tanggal_input',
    'kecamatan'            => 'kecamatan',
    'nama_wilayah'         => 'nama_wilayah',
    'waktu_semai'          => 'waktu_semai',
    'pengambilan_data'     => 'pengambilan_data',
    'suhu'                 => 'suhu',
    'presipitasi'          => 'presipitasi',
    'populasi_wereng'      => 'populasi_wereng',
    'varietas_padi'        => 'varietas_padi',
    'virulensi'            => 'virulensi',
    'persentase_insidensi' => 'persentase_insidensi'
];
$orderBy = isset($sortable[$sort]) ? $sortable[$sort] : 'tanggal_input';

$offset = ($page - 1) * $per_page;

$sqlCount = "SELECT COUNT(*) AS total
             FROM riwayat_prediksi
             WHERE verifikasi = 0";
$resCount = mysqli_query($conn, $sqlCount);
$rowCount = $resCount ? mysqli_fetch_assoc($resCount) : ['total' => 0];
$total    = (int)$rowCount['total'];
$total_pages = ($total > 0) ? (int)ceil($total / $per_page) : 1;
if ($page > $total_pages) $page = $total_pages;

$data = [];
$sql = "SELECT 
            id, 
            tanggal_input, 
            kecamatan, 
            nama_wilayah, 
            waktu_semai, 
            pengambilan_data, 
            suhu, 
            presipitasi, 
            populasi_wereng, 
            varietas_padi, 
            virulensi, 
            persentase_insidensi
        FROM riwayat_prediksi
        WHERE verifikasi = 0
        ORDER BY $orderBy $dir
        LIMIT ? OFFSET ?";

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "ii", $per_page, $offset);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $kecamatan_parts = explode(',', (string)$row['kecamatan']);
            $row['kecamatan_simple'] = trim($kecamatan_parts[0]);
            $data[] = $row;
        }
        mysqli_stmt_close($stmt);

        echo json_encode([
            "success"      => true,
            "data"         => $data,
            "page"         => $page,
            "per_page"     => $per_page,
            "total"        => $total,
            "total_pages"  => $total_pages,
            "sort"         => $orderBy,
            "dir"          => $dir
        ]);
    } else {
        error_log("SQL Execute Error: " . mysqli_error($conn));
        echo json_encode(["success" => false, "message" => "Gagal mengambil data.", "data" => []]);
    }
} else {
    error_log("SQL Prepare Error: " . mysqli_error($conn));
    echo json_encode(["success" => false, "message" => "Kesalahan server.", "data" => []]);
}

mysqli_close($conn);
