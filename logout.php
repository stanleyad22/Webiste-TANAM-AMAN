<?php
session_start();

// Hapus semua variabel session
$_SESSION = array();

// Hancurkan session
session_destroy();

// Redirect kembali ke halaman utama
// GANTI 'index.php' dengan nama file utama Anda jika berbeda
header("Location: index.php"); 
exit;
?>
