<?php
session_start();
require 'koneksi.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['nama']) || empty($data['email']) || empty($data['password']) || empty($data['domisili']) || empty($data['telepon'])) {
    echo json_encode(["success" => false, "message" => "Semua kolom wajib diisi."]);
    exit;
}

$nama_lengkap = $data['nama'];
$email = $data['email'];
$password = $data['password'];
$domisili = $data['domisili'];
$nomor_telepon = $data['telepon'];

$sql_check = "SELECT id FROM users WHERE email = ?";
if ($stmt_check = mysqli_prepare($conn, $sql_check)) {
    mysqli_stmt_bind_param($stmt_check, "s", $email);
    mysqli_stmt_execute($stmt_check);
    mysqli_stmt_store_result($stmt_check);

    if (mysqli_stmt_num_rows($stmt_check) > 0) {
        echo json_encode(["success" => false, "message" => "Email ini sudah terdaftar. Silakan gunakan email lain."]);
        mysqli_stmt_close($stmt_check);
        mysqli_close($conn);
        exit;
    }
    mysqli_stmt_close($stmt_check);
}

$hashed_password = password_hash($password, PASSWORD_BCRYPT);

$sql_insert = "INSERT INTO users (nama_lengkap, email, password, domisili, nomor_telepon, status) VALUES (?, ?, ?, ?, ?, 'user')";
if ($stmt_insert = mysqli_prepare($conn, $sql_insert)) {
    mysqli_stmt_bind_param($stmt_insert, "sssss", $nama_lengkap, $email, $hashed_password, $domisili, $nomor_telepon);
    
    if (mysqli_stmt_execute($stmt_insert)) {
        echo json_encode(["success" => true, "message" => "Registrasi berhasil! Silakan login."]);
    } else {
        error_log("SQL Error: " . mysqli_error($conn));
        echo json_encode(["success" => false, "message" => "Terjadi kesalahan. Silakan coba lagi."]);
    }
    mysqli_stmt_close($stmt_insert);
} else {
    error_log("SQL Prepare Error: " . mysqli_error($conn));
    echo json_encode(["success" => false, "message" => "Terjadi kesalahan server."]);
}

mysqli_close($conn);
?>
