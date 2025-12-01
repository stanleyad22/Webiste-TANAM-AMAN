<?php
session_start();
require 'koneksi.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['email']) || empty($data['password'])) {
    echo json_encode(["success" => false, "message" => "Email dan password wajib diisi."]);
    exit;
}

$email = $data['email'];
$password = $data['password'];

$sql = "SELECT id, nama_lengkap, password, status FROM users WHERE email = ?";
if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) === 1) {
        $user = mysqli_fetch_assoc($result);
        
        if (password_verify($password, $user['password'])) {
            
            session_regenerate_id(true); 
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
            $_SESSION['status'] = $user['status'];
            
            echo json_encode([
                "success" => true, 
                "message" => "Login berhasil!",
                "user" => [
                    "nama" => $user['nama_lengkap'],
                    "status" => $user['status']
                ]
            ]);
            
        } else {
            echo json_encode(["success" => false, "message" => "Email atau password salah."]);
        }
    } else {
        echo json_encode(["success" => false, "message" => "Email atau password salah."]);
    }
    mysqli_stmt_close($stmt);
    
} else {
    error_log("SQL Prepare Error: " . mysqli_error($conn));
    echo json_encode(["success" => false, "message" => "Terjadi kesalahan server."]);
}

mysqli_close($conn);
?>
