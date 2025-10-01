<?php 
    $servername = "127.0.0.1";
    $username = "root";
    $password = "";
    $dbname = "tand8989_koleksi_data";
    $conn = mysqli_connect($servername, $username, $password, $dbname);

    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }

    function koleksiKabupaten($provinsi, $sumbu) {
        $warna = ["#ff6666", "#ff9966", "#ffff99", "#99ff99", "#66ffcc", "#99ffff", "#9999ff", "#ff99ff", "#ce99ff"];
    
        $sql = "SELECT kabupaten AS distinct_kabupaten, COUNT(*) AS kabupaten 
                FROM koleksi_wilayah WHERE provinsi = '$provinsi' GROUP BY kabupaten;";
        $result = mysqli_query($GLOBALS['conn'], $sql);
    
        $labels = [];
        $data = [];
        $colors = [];
        $count = 0;
    
        while ($row = mysqli_fetch_assoc($result)) {
            $labels[] = $row['distinct_kabupaten'];
            $data[] = $row['kabupaten'];
            $colors[] = $warna[$count % count($warna)];
            $count++;
        }
    
        if ($sumbu == 1) {
            return $labels;
        } elseif ($sumbu == 2) {
            return $data;
        } elseif ($sumbu == 3) {
            return $colors;
        }
    }

    function koleksiKecamatan($kabupaten, $sumbu) {
        $warna = ["#ff6666", "#ff9966", "#ffff99", "#99ff99", "#66ffcc", "#99ffff", "#9999ff", "#ff99ff", "#ce99ff"];
        
        $sql = "SELECT kecamatan AS distinct_kecamatan, COUNT(*) AS kecamatan_count 
                FROM koleksi_wilayah WHERE kabupaten = '$kabupaten' GROUP BY kecamatan;";
        $result = mysqli_query($GLOBALS['conn'], $sql);
        
        $labels = [];
        $data = [];
        $colors = [];
        $count = 0;
        
        while ($row = mysqli_fetch_assoc($result)) {
            $labels[] = $row['distinct_kecamatan'];
            $data[] = $row['kecamatan_count'];
            $colors[] = $warna[$count % count($warna)];
            $count++;
        }
        
        if ($sumbu == 1) {
            return $labels;
        } elseif ($sumbu == 2) {
            return $data;
        } elseif ($sumbu == 3) {
            return $colors;
        }
    }

    function koleksiWilayahByKecamatan($kecamatan, $sumbu) {
        $warna = ["#ff6666", "#ff9966", "#ffff99", "#99ff99", "#66ffcc", "#99ffff", "#9999ff", "#ff99ff", "#ce99ff"];
        
        $sql = "SELECT nama_wilayah AS distinct_nama_wilayah, COUNT(*) AS jumlah 
                FROM koleksi_wilayah WHERE kecamatan = '$kecamatan' GROUP BY nama_wilayah;";
        $result = mysqli_query($GLOBALS['conn'], $sql);
        
        $labels = [];
        $data = [];
        $colors = [];
        $count = 0;
    
        while ($row = mysqli_fetch_assoc($result)) {
            $labels[] = $row['distinct_nama_wilayah'];
            $data[] = $row['jumlah'];
            $colors[] = $warna[$count % count($warna)];
            $count++;
        }
    
        if ($sumbu == 1) {
            return $labels;
        } elseif ($sumbu == 2) {
            return $data;
        } elseif ($sumbu == 3) {
            return $colors;
        }
    }
    
?>
