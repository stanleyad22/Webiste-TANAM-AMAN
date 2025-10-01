<?php

$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "tand8989_koleksi_data";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    echo(json_encode(["success" => false, "message" => "Connection failed: " . $conn->connect_error]));
}

$data = json_decode(file_get_contents('php://input'), true);

$kecamatan = $data['kecamatan'];
$nama_wilayah = $data['nama_wilayah'];
$pengambilan_data = $data['pengambilan_data'];
$waktu_semai = $data['waktu_semai'];
$populasi_wereng = $data['populasi_wereng'];
$varietas_padi = $data['varietas_padi'];
$suhu = $data['suhu'];
$presipitasi = $data['presipitasi'];
$virulensi = $data['virulensi'];
$tanggal_pengambilan_data = $data['tanggal_pengambilan_data'];
$persentase_insidensi = $data['persentase_insidensi'];

$stmt = $conn->prepare("INSERT INTO riwayat_prediksi (kecamatan, nama_wilayah, pengambilan_data, waktu_semai, populasi_wereng, varietas_padi, suhu, presipitasi, virulensi, tanggal_input, tanggal_pengambilan_data, persentase_insidensi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
$stmt->bind_param("ssssissdssd", $kecamatan, $nama_wilayah, $pengambilan_data, $waktu_semai, $populasi_wereng, $varietas_padi, $suhu, $presipitasi, $virulensi, $tanggal_pengambilan_data, $persentase_insidensi);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Record created successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
