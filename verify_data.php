<?php
session_start();
require 'koneksi.php'; 

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['status']) || $_SESSION['status'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "Akses ditolak."]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$idsToVerify = isset($data['ids']) && is_array($data['ids']) ? $data['ids'] : [];

$validatedIds = [];
foreach ($idsToVerify as $id) {
    if (is_numeric($id) && $id > 0) {
        $validatedIds[] = (int)$id;
    }
}

if (empty($validatedIds)) {
    echo json_encode(["success" => false, "message" => "Tidak ada ID valid yang dipilih."]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($validatedIds), '?'));
$types = str_repeat('i', count($validatedIds));

$sql = "UPDATE riwayat_prediksi SET verifikasi = 1 WHERE id IN ($placeholders) AND verifikasi = 0"; // Update hanya yg belum terverifikasi

if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, $types, ...$validatedIds); 
    
    if (mysqli_stmt_execute($stmt)) {
        $affected_rows = mysqli_stmt_affected_rows($stmt);
        echo json_encode(["success" => true, "message" => "$affected_rows data berhasil diverifikasi."]);
    } else {
        error_log("SQL Execute Error: " . mysqli_error($conn));
        echo json_encode(["success" => false, "message" => "Gagal memverifikasi data."]);
    }
    mysqli_stmt_close($stmt);
} else {
    error_log("SQL Prepare Error: " . mysqli_error($conn));
    echo json_encode(["success" => false, "message" => "Kesalahan server saat persiapan query."]);
}

mysqli_close($conn);
?>
