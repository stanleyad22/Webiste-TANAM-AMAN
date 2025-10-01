<?php 
    $servername = "127.0.0.1";
    $username = "root";
    $password = "";
    $dbname = "tand8989_koleksi_data";
    $conn = mysqli_connect($servername, $username, $password, $dbname);

    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }

    date_default_timezone_set('Asia/Jakarta');

    // ---- FILTER BULAN, TAHUN & PENGAMBILAN DATA
    $DATE_COL = 'tanggal_pengambilan_data';
    $bulanFilter = isset($_GET['bulan']) ? max(1, min(12, (int)$_GET['bulan'])) : (int)date('n');
    $tahunFilter = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
    // -- [BARU] Filter Pengambilan Data --
    $pengambilanFilter = isset($_GET['pengambilan']) ? (int)$_GET['pengambilan'] : null;


    // ==== DAFTAR TAHUN, BULAN, & PENGAMBILAN DATA YANG ADA DI DB ====
    $years = [];
    $monthsByYear = [];
    $pengambilanOptions = []; 

    // [DIUBAH] Query untuk mengambil tahun dari kolom teks 'tanggal_pengambilan_data'
    // Format: "DD nama_bulan YYYY" (contoh: "05 Agustus 2024")
    $YEAR_EXPR = "CAST(SUBSTRING_INDEX($DATE_COL, ' ', -1) AS UNSIGNED)";
    $yearSql = "SELECT DISTINCT $YEAR_EXPR AS y
                FROM riwayat_prediksi
                WHERE $DATE_COL IS NOT NULL AND $DATE_COL RLIKE '^[0-9]{1,2} [a-zA-Z]+ [0-9]{4}$'
                ORDER BY y DESC";
    if ($resY = mysqli_query($conn, $yearSql)) {
        while ($r = mysqli_fetch_assoc($resY)) {
            if ((int)$r['y'] > 2000) { 
                $years[] = (int)$r['y'];
            }
        }
    }

    // Query untuk mengambil tahun dan bulan dari kolom teks 'tanggal_pengambilan_data'
    $MONTH_EXPR = "(CASE SUBSTRING_INDEX(SUBSTRING_INDEX($DATE_COL, ' ', 2), ' ', -1)
                        WHEN 'Januari' THEN 1 WHEN 'Februari' THEN 2 WHEN 'Maret' THEN 3
                        WHEN 'April' THEN 4 WHEN 'Mei' THEN 5 WHEN 'Juni' THEN 6
                        WHEN 'Juli' THEN 7 WHEN 'Agustus' THEN 8 WHEN 'September' THEN 9
                        WHEN 'Oktober' THEN 10 WHEN 'November' THEN 11 WHEN 'Desember' THEN 12
                        ELSE NULL END)";
    $ymSql = "SELECT DISTINCT $YEAR_EXPR AS y, $MONTH_EXPR AS m
            FROM riwayat_prediksi
            WHERE $DATE_COL IS NOT NULL AND $DATE_COL RLIKE '^[0-9]{1,2} [a-zA-Z]+ [0-9]{4}$'
            ORDER BY y DESC, m ASC";
    if ($resYM = mysqli_query($conn, $ymSql)) {
        while ($r = mysqli_fetch_assoc($resYM)) {
            $y = (int)$r['y']; $m = (int)$r['m'];
            if ($y > 2000 && $m >= 1 && $m <= 12) {
                if (!isset($monthsByYear[$y])) $monthsByYear[$y] = [];
                if (!in_array($m, $monthsByYear[$y], true)) $monthsByYear[$y][] = $m;
            }
        }
    }
    
    // -- Query untuk mendapatkan opsi filter Pengambilan Data --
    $pengambilanSql = "SELECT DISTINCT pengambilan_data 
                       FROM riwayat_prediksi 
                       WHERE pengambilan_data IS NOT NULL 
                       ORDER BY pengambilan_data ASC";
    if ($resP = mysqli_query($conn, $pengambilanSql)) {
        while ($r = mysqli_fetch_assoc($resP)) {
            $pengambilanOptions[] = (int)$r['pengambilan_data'];
        }
    }


    // ==== Tetapkan default: tahun/bulan terbaru dari DB bila filter GET tidak valid ====
    if (!empty($years)) {
        if (!in_array($tahunFilter, $years, true)) {
            $tahunFilter = $years[0]; // tahun terbaru (karena DESC)
        }
        $availableMonths = $monthsByYear[$tahunFilter] ?? [];
        if (empty($availableMonths)) {
            // tidak ada bulan untuk tahun tsb → fallback ke tahun terbaru yg punya bulan
            foreach ($years as $y) {
                if (!empty($monthsByYear[$y])) { $tahunFilter = $y; $availableMonths = $monthsByYear[$y]; break; }
            }
        }
        if (empty($availableMonths)) {
            // benar-benar tidak ada data tanggal di DB
            $bulanFilter = null;
        } else {
            if (!in_array($bulanFilter, $availableMonths, true)) {
                $bulanFilter = max($availableMonths); // ambil bulan terbaru di tahun tsb
            }
        }
    } else {
        // Tidak ada data tanggal sama sekali → biarkan default hari ini (atau null)
        $tahunFilter = (int)date('Y');
        $bulanFilter = (int)date('n');
    }

    // --- CATATAN: Bagian di bawah ini untuk KUOTA HARIAN, tetap menggunakan 'tanggal_input' ---
    // --- karena kolom ini kemungkinan besar adalah timestamp kapan data dimasukkan (created_at) ---
    // --- dan tidak berhubungan dengan tanggal pengambilan data di lapangan. ---
    $TIMESTAMP_COL = 'tanggal_input';
    date_default_timezone_set('Asia/Jakarta');
    $today = date('Y-m-d');

    $USE_DB_UTC = false;
    $todayCount = 0;
    if ($USE_DB_UTC) {
        $sqlCount = "SELECT COUNT(*) FROM riwayat_prediksi WHERE DATE(CONVERT_TZ($TIMESTAMP_COL, '+00:00', '+07:00')) = ?";
    } else {
        $sqlCount = "SELECT COUNT(*) FROM riwayat_prediksi WHERE DATE($TIMESTAMP_COL) = ?";
    }

    if ($stmt = mysqli_prepare($conn, $sqlCount)) {
        mysqli_stmt_bind_param($stmt, "s", $today);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $c);
        if (mysqli_stmt_fetch($stmt)) {
            $todayCount = (int)$c;
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log('[quota] prepare failed: ' . mysqli_error($conn));
    }

    // Progress: 32 Kuota per hari
    $MAX_STEPS        = 32;
    $progressPercent  = min(100, round((min($todayCount, $MAX_STEPS) / $MAX_STEPS) * 100, 1));
    $progressLabel    = $progressPercent . '%';
    $progressStepLabel = $todayCount . '/' . $MAX_STEPS;


    $sql = "SELECT
            TRIM(SUBSTRING_INDEX(kecamatan, ',', 1))                                         AS kecamatan,
            TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(kecamatan, ',', 2), ',', -1))               AS kabupaten,
            TRIM(SUBSTRING_INDEX(kecamatan, ',', -1))                                        AS provinsi,

            AVG(populasi_wereng)      AS avg_populasi_wereng,
            AVG(suhu)                 AS avg_suhu,
            AVG(presipitasi)          AS avg_presipitasi,
            AVG(persentase_insidensi) AS avg_persentase_insidensi,

            SUM(CASE WHEN LOWER(varietas_padi) = 'rentan' THEN 1 ELSE 0 END)          AS rentan,
            SUM(CASE WHEN LOWER(varietas_padi) = 'tahan'  THEN 1 ELSE 0 END)          AS tahan,
            virulensi
        FROM riwayat_prediksi 
        GROUP BY kecamatan;"; 
    $res = mysqli_query($conn, $sql);

    $agg = [];
    $areas = []; 
    while ($row = mysqli_fetch_assoc($res)) {
        $kec = trim($row['kecamatan'] ?? '');
        $kab = trim($row['kabupaten'] ?? '');
        $prov = trim($row['provinsi'] ?? '');

        if ($kec !== '' && $kab !== '' && $prov !== '') {
            $key = $prov.'|'.$kab.'|'.$kec;
            if (!isset($areas[$key])) {
                $areas[$key] = ['provinsi'=>$prov, 'kabupaten'=>$kab, 'kecamatan'=>$kec];
            }
        }

        if ($kec === '') continue;
        if (!isset($agg[$kec])) {
            $agg[$kec] = [
                'sum_pop'=>0,'cnt_pop'=>0,
                'sum_suhu'=>0,'cnt_suhu'=>0,
                'sum_pres'=>0,'cnt_pres'=>0,
                'sum_inc'=>0,'cnt_inc'=>0,
                'vir'=>['Tidak Virulen'=>0,'Virulen'=>0,'Sangat Virulen'=>0],
                'vir_last'=>null,
                'var'=>['Rentan'=>0,'Tahan'=>0]
            ];
        }

        $val_pop = $row['populasi_wereng'] ?? $row['avg_populasi_wereng'] ?? null;
        $val_suhu = $row['suhu'] ?? $row['avg_suhu'] ?? null;
        $val_pres = $row['presipitasi'] ?? $row['avg_presipitasi'] ?? null;
        $val_inc  = $row['persentase_insidensi'] ?? $row['avg_persentase_insidensi'] ?? null;

        if (is_numeric($val_pop)) { $agg[$kec]['sum_pop'] += (float)$val_pop; $agg[$kec]['cnt_pop']++; }
        if (is_numeric($val_suhu)) { $agg[$kec]['sum_suhu'] += (float)$val_suhu; $agg[$kec]['cnt_suhu']++; }
        if (is_numeric($val_pres)) { $agg[$kec]['sum_pres'] += (float)$val_pres; $agg[$kec]['cnt_pres']++; }
        if (is_numeric($val_inc))  { $agg[$kec]['sum_inc']  += (float)$val_inc;  $agg[$kec]['cnt_inc']++; }

        $v = strtolower(trim($row['virulensi'] ?? ''));
        if ($v !== '') {
            if (strpos($v,'sangat') !== false)      { $agg[$kec]['vir']['Sangat Virulen']++; $agg[$kec]['vir_last'] = 'Sangat Virulen'; }
            elseif (strpos($v,'tidak') !== false)   { $agg[$kec]['vir']['Tidak Virulen']++;  $agg[$kec]['vir_last'] = 'Tidak Virulen'; }
            else                                    { $agg[$kec]['vir']['Virulen']++;        $agg[$kec]['vir_last'] = 'Virulen'; }
        }

        if (isset($row['rentan'])) { $agg[$kec]['var']['Rentan'] += (int)$row['rentan']; }
        if (isset($row['tahan']))  { $agg[$kec]['var']['Tahan']  += (int)$row['tahan']; }

        $var = trim($row['varietas_padi'] ?? '');
        if ($var !== '') {
            if (strcasecmp($var,'rentan')===0) $agg[$kec]['var']['Rentan']++;
            elseif (strcasecmp($var,'tahan')===0) $agg[$kec]['var']['Tahan']++;
        }

    }

    $stats = [];
    foreach ($agg as $kec => $a) {
        $dominant = $a['vir_last'];
        if ($dominant === null) {
            $maxCnt = -1;
            foreach ($a['vir'] as $label => $cnt) {
                if ($cnt > $maxCnt) { $maxCnt = $cnt; $dominant = $label; }
            }
            if ($maxCnt <= 0) $dominant = null;
        }

        $totalVar = max(0, (int)$a['var']['Rentan'] + (int)$a['var']['Tahan']);
        $varPct = [];
        if ($totalVar > 0) {
            foreach ($a['var'] as $k => $v) {
                $varPct[$k] = round(($v / $totalVar) * 100, 1);
            }
        }

        $stats[$kec] = [
            "incidence_pct"        => $a['cnt_inc']  ? round($a['sum_inc']  / $a['cnt_inc'], 1)  : 0,
            "mean_populasi_wereng" => $a['cnt_pop']  ? round($a['sum_pop']  / $a['cnt_pop'], 1)  : null,
            "mean_suhu"            => $a['cnt_suhu'] ? round($a['sum_suhu'] / $a['cnt_suhu'], 1) : null,
            "mean_presipitasi"     => $a['cnt_pres'] ? round($a['sum_pres'] / $a['cnt_pres'], 1) : null,
            "virulensi"            => $dominant,
            "varietas"             => $varPct
        ];
    }

    function safeName($s) {
        $s = trim($s);
        $s = preg_replace('/^(Kabupaten|Kota)\s+/i', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        if (function_exists('mb_convert_case')) {
            $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
        } else {
            $s = ucwords(strtolower($s));
        }
        $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], ' ', $s);
        return $s;
    }

    $geoFeatures = [];
    $missing = [];

    foreach ($areas as $area) {
        $prov = safeName($area['provinsi']);
        $kab  = safeName($area['kabupaten']);
        $kec  = safeName($area['kecamatan']);

        $path = __DIR__ . "/assets/geo/Provinsi {$prov}/Kabupaten {$kab}/Kecamatan {$kec}.geojson";

        if (!file_exists($path)) {
            $missing[] = substr($path, strlen(__DIR__));
            continue;
        }

        $gj = json_decode(@file_get_contents($path), true);
        if (!is_array($gj) || !isset($gj['type'])) {
            $missing[] = "(format salah) " . substr($path, strlen(__DIR__));
            continue;
        }

        if ($gj['type'] === 'FeatureCollection' && isset($gj['features']) && is_array($gj['features'])) {
            foreach ($gj['features'] as $f) {
                if (!isset($f['properties']) || !is_array($f['properties'])) $f['properties'] = [];
                $f['properties'] = array_merge($f['properties'], [
                    'provinsi'  => $area['provinsi'],
                    'kabupaten' => $area['kabupaten'],
                    'kecamatan' => $area['kecamatan'],
                    'stats'     => $stats[$area['kecamatan']] ?? null
                ]);
                $geoFeatures[] = $f;
            }
        } elseif ($gj['type'] === 'Feature') {
            if (!isset($gj['properties']) || !is_array($gj['properties'])) $gj['properties'] = [];
            $gj['properties'] = array_merge($gj['properties'], [
                'provinsi'  => $area['provinsi'],
                'kabupaten' => $area['kabupaten'],
                'kecamatan' => $area['kecamatan'],
                'stats'     => $stats[$area['kecamatan']] ?? null
            ]);
            $geoFeatures[] = $gj;
        } else {
            $missing[] = "(jenis tidak didukung) " . substr($path, strlen(__DIR__));
        }
    }

    $geojson = ["type" => "FeatureCollection", "features" => $geoFeatures];

    if (!empty($missing)) {
        error_log("GeoJSON tidak ditemukan/invalid: " . implode(' | ', $missing));
    }

    function koleksiWilayah($sumbu, $bulan = null, $tahun = null) {
        global $conn; 
        $DATE_COL_KOLEKSI = 'tanggal_input'; // Tetap gunakan kolom lama untuk tabel ini

        $warna = ["#ff6666", "#ff9966", "#ffff99", "#99ff99", "#66ffcc", "#99ffff", "#9999ff", "#ff99ff", "#ce99ff"];

        $WHERE = [];
        if (!empty($bulan)) $WHERE[] = "MONTH($DATE_COL_KOLEKSI) = " . intval($bulan);
        if (!empty($tahun)) $WHERE[] = "YEAR($DATE_COL_KOLEKSI) = " . intval($tahun);
        $whereSql = $WHERE ? ("WHERE " . implode(" AND ", $WHERE)) : "";

        $sql = "
            SELECT provinsi AS distinct_provinsi, COUNT(*) AS kabupaten_count
            FROM koleksi_wilayah
            $whereSql
            GROUP BY provinsi
            ORDER BY provinsi
        ";

        $result = mysqli_query($conn, $sql);
        if (!$result) {
            error_log('[pie] SQL error: ' . mysqli_error($conn) . ' | ' . $sql);
            if ($sumbu == 1) return [];
            if ($sumbu == 2) return [];
            if ($sumbu == 3) return [];
            return '';
        }

        $labels = [];
        $data = [];
        $colors = [];
        $count = 0;

        while ($row = mysqli_fetch_assoc($result)) {
            $labels[] = $row['distinct_provinsi'];
            $data[]   = (int)$row['kabupaten_count'];
            $colors[] = $warna[$count % count($warna)];
            $count++;
        }

        if ($sumbu == 1)      return $labels;
        elseif ($sumbu == 2)  return $data;
        elseif ($sumbu == 3)  return $colors;
        else                  return ''; 
    }
?>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Tanamaman</title>
    <link rel="icon" type="image/x-icon" href="assets/img/logo.png">
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito:200,200i,300,300i,400,400i,600,600i,700,700i,800,800i,900,900i&amp;display=swap">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.12.0/css/all.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        .chart-area { position: relative; min-height: 275px;}
        .chart-area canvas { position: relative; z-index: 1; display: block; }

        /* Tombol 'Kembali' di tengah donut */
        .chart-back-btn{
            font-size:.8rem; padding:.2rem .5rem;
            position: absolute;
            left: 50%;
            top: calc(50% + 8px); 
            transform: translate(-50%, -50%);
            z-index: 3;
            box-shadow: 0 2px 8px rgba(0,0,0,.2);
            opacity: .95;
            display: none;
        }

        .chart-back-btn.is-visible{ display: inline-block; }

        #mapTungro { height: 420px; border-radius: .5rem; }

        #wrapper #content-wrapper{
            background-color: #ffffff;
        }
        .global-filter .form-select { min-width: 140px; }
        .title-header{
            font-family: 'Papyrus';
            color: #000000;
        }
        .card-header-text{
            color: #ffffff;
        }
        .card{
            --bs-card-cap-bg :#6CD756;
        }
        :root{
            --bs-primary :#4AB83F;
            --bs-primary-rgb: 74, 184, 63;

            --bs-success : #6CD756;
            --bs-success-rgb: 108, 215, 86;

            --bs-danger : #FE6555;
            --bs-danger-rgb: 254, 101, 85;

            --bs-warning : #FCDA00;
            --bs-warning-rgb: 252, 218, 0;
        }
        .legend { background: #fff; padding: .5rem .75rem; line-height: 1.1; border-radius:.5rem; box-shadow:0 1px 6px rgba(0,0,0,.1);}
        .legend i { width: 16px; height: 10px; float: left; margin-right: .5rem; opacity: 0.8; }

        .gauge-wrap{
            position: relative;
            height: 75px;
            display: flex;
            flex-direction: row;
        }
        .gauge-inner{
            max-width: 300px;
        }
        #gauge, #apiGauge{
            display: block;
        }
        .gauge-label{
            position: absolute;
            left: 50%;
            top: 60%;
            transform: translate(-50%, -50%);
            text-align: center;
            z-index: 2;
        }
        .gauge-title{
            position: absolute;
            top: 10%;
            left: 50%;
            text-align: center;
            transform: translate(-50%, -50%);
            font-size:1.05rem; 
            font-weight: bold;
            margin-bottom:.15rem; }
        .gauge-percent{ font-weight:700; font-size:1.95rem; line-height:1; }

        .welcome-chat{
            display:flex; gap:12px; align-items:flex-start;
            margin: 8px 0 14px 0;
        }
        .welcome-avatar{
            width:56px; height:56px; border-radius:14px;
            background:#FFFFFF; display:flex; align-items:center; justify-content:center;
            font-size:34px; 
            flex: 0 0 56px;
            overflow:hidden;
        }
        .welcome-avatar img{ width:100%; height:100%; object-fit:contain; }
        .welcome-bubble{
            background:#ffffff; color:#666; border:1px solid #eef2ee;
            padding:14px 16px; border-radius:16px; box-shadow:0 2px 10px rgba(0,0,0,.06);
            font-size:1.05rem; line-height:1.35;
        }
        .info-modal .welcome-cursor{
            display:inline-block;width:2px;height:1.05em;margin-left:2px;
            background:#888;animation:blink 1s steps(2,start) infinite;vertical-align:-2px;
        }
        @keyframes blink { 50%{ background:transparent; } }

        .pie-level-badge{
            position:absolute;
            top:-10%;
            left:50%;
            transform:translateX(-50%);
            z-index:2;
            background:#ffffff;
            padding:4px 10px;
            border-radius:999px;
            font-size:1.05rem;
            font-weight: bold;
            color:#555;
            pointer-events:none; /* supaya tidak menghalangi klik chart */
        }

    
    </style>
</head>

<body id="page-top">
    <div id="wrapper">
        <div class="d-flex flex-column" id="content-wrapper">
            <div id="content">
                <div class="container-fluid">
                    <div class="d-sm-flex justify-content-between align-items-center mb-2" style="margin-top: 20px;">
                        <h3 class="mb-2 mt-2 title-header">Dashboard Tanamaman</h3>
                    </div>
                    <div class="row">
                        
                    </div>
                    <div class="row g-2 align-items-end mb-3 global-filter">
                        <div class="col-auto">
                            <label class="small text-muted mb-1">Tahun</label>
                            <select id="filterTahun" class="form-select form-select-sm" style="min-width:100px;">
                            <?php foreach ($years as $y): 
                                $sel = ($y == $tahunFilter) ? 'selected' : ''; ?>
                                <option value="<?= htmlspecialchars($y) ?>" <?= $sel ?>><?= htmlspecialchars($y) ?></option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <label class="small text-muted mb-1">Bulan</label>
                            <select id="filterBulan" class="form-select form-select-sm" style="min-width:120px;" <?= empty($monthsByYear[$tahunFilter]) ? 'disabled' : '' ?>>
                            <?php
                                $namaBulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                                $mList = $monthsByYear[$tahunFilter] ?? [];
                                foreach ($mList as $m) {
                                $sel = ($m == $bulanFilter) ? 'selected' : '';
                                echo "<option value=\"$m\" $sel>{$namaBulan[$m]}</option>";
                                }
                            ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <label class="small text-muted mb-1">Pengambilan Data</label>
                            <select id="filterPengambilan" class="form-select form-select-sm" style="min-width:120px;">
                                <option value="">Semua</option>
                            <?php foreach ($pengambilanOptions as $p): 
                                $sel = ($p == $pengambilanFilter) ? 'selected' : ''; ?>
                                <option value="<?= htmlspecialchars($p) ?>" <?= $sel ?>><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto ms-auto">
                            <label class="small text-muted mb-1 d-block">&nbsp;</label>
                            <button id="openInfoPanel" style="background:#6CD756" type="button" class="btn btn-success btn-sm text-white">
                                <i class="fas fa-info-circle me-1"></i> Informasi Situs
                            </button>
                        </div>
                        <div class="modal fade info-modal" id="infoPanel" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content p-3" style="border-radius:16px;">
                            <div class="text-center">
                                <img src="assets/img/farmer.png" alt="Petani" style="width:56px;height:56px;">
                                <h5 class="fw-bold mt-2 mb-2">Informasi Situs</h5>
                            </div>

                            <div class="px-1">
                                <span id="welcomeTyped"></span><span class="welcome-cursor"></span>
                            </div>

                            <div class="pt-3">
                                <button type="button" class="btn w-100" data-bs-dismiss="modal" style="background:#6CD756; color:#FFFFFF">Tutup</button>
                            </div>
                            </div>
                        </div>
                        </div>

                    </div>
                

                    <div class="row">
                        <div class="col-lg-7 col-xl-8">
                            <div class="card shadow mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="fw-bold m-0 card-header-text">Informasi Prediksi</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-2 align-items-end mb-3">
                                    <div class="col-md-6">
                                        <label class="small text-muted mb-1">Filter Provinsi</label>
                                        <select id="filterProvinsi" class="form-select form-select-sm">
                                        <option value="">Semua Provinsi</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small text-muted mb-1">Tingkat Keparahan</label>
                                        <select id="filterSeverity" class="form-select form-select-sm">
                                        <option value="">Semua Keparahan</option>
                                        <option value="1-10">1–10% (Ringan)</option>
                                        <option value="10-30">&gt;10–30% (Sedang)</option>
                                        <option value="30-50">&gt;30–50% (Berat)</option>
                                        <option value="50+">≥50% (Sangat Berat)</option>
                                        </select>
                                    </div>
                                    </div>

                                    <div id="mapTungro"></div>
                                    <div id="statPanel" class="mt-3"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5 col-xl-4">
                            <div class="card shadow mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="fw-bold m-0 card-header-text">Data Jumlah Prediksi per Wilayah</h6>
                                </div>
                                <div class="card-body">
                                    <div class="chart-area mb-4">
                                        <canvas id="pieChart" style="margin-top: 30px;"></canvas>
                                        <button id="backButton" class="btn btn-secondary btn-sm chart-back-btn">Kembali</button>
                                    </div>
                                </div>
                            </div>
                            <div class="card shadow mb-4">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="fw-bold m-0 card-header-text">Kuota Prediksi Hari Ini</h6>
                                </div>
                                <div class="chart-area gauge-wrap px-3">       <div class="gauge-inner mx-auto">           <canvas id="apiGauge"
                                                data-percent="<?= htmlspecialchars($progressPercent) ?>"
                                                data-steps="<?= htmlspecialchars($progressStepLabel) ?>"></canvas>
                                    </div>

                                    <div class="gauge-title">Kuota Prediksi Hari Ini</div>
                                    <div class="gauge-label">
                                        <div class="gauge-percent"><?= htmlspecialchars($progressLabel) ?></div>
                                        <div class="gauge-steps text-muted small"><?= htmlspecialchars($progressStepLabel) ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <footer class="bg-white sticky-footer"></footer>
        </div><a class="border rounded d-inline scroll-to-top" href="#page-top"><i class="fas fa-angle-up"></i></a>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.8.0/Chart.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
        const panelEl = document.getElementById('infoPanel');
        const openBtn = document.getElementById('openInfoPanel');

        // Buka panel saat tombol diklik
        if (openBtn && panelEl) {
            openBtn.addEventListener('click', () => {
            new bootstrap.Modal(panelEl).show();
            });
        }

        let hasTyped = false;
        function runTypewriter(){
            if (hasTyped) return; hasTyped = true;

            const el = document.getElementById('welcomeTyped');
            const cursor = document.querySelector('#infoPanel .welcome-cursor');
            if (!el) return;

            const parts = [
            'Website ini menampilkan ringkasan hasil prediksi insidensi penyakit tungro yang dikumpulkan dari aplikasi Tanamaman.',
            { type: 'break' },
            { type: 'break' },
            'Aplikasi Tanamaman adalah aplikasi untuk membantu petani di Indonesia dalam memprediksi penyakit tungro pada padi.',
            { type: 'break' },
            { type: 'break' },
            'Klik link berikut untuk mendownload aplikasi tanamaman ',
            { href: 'https://drive.google.com/drive/folders/1Xw7g16SIevxKxDVOrl5R_DXwYfKp1s-n?usp=sharing', text: 'Aplikasi Tanamaman' }
            ];

            let partIdx = 0, charIdx = 0;
            let textNode = document.createTextNode('');
            el.appendChild(textNode);

            function type() {
            if (partIdx >= parts.length) {
                setTimeout(() => cursor && (cursor.style.display = 'none'), 900);
                return;
            }

            const part = parts[partIdx];

            if (part.type === 'break') {
                el.appendChild(document.createElement('br'));
                partIdx++;
                textNode = document.createTextNode('');
                el.appendChild(textNode);
                return void setTimeout(type, 25);
            }

            if (typeof part === 'string') {
                if (charIdx < part.length) {
                textNode.textContent += part.charAt(charIdx++);
                return void setTimeout(type, 25);
                } else {
                partIdx++; charIdx = 0;
                return void setTimeout(type, 25);
                }
            }

            const a = document.createElement('a');
            a.href = part.href; a.target = '_blank'; a.rel = 'noopener'; a.textContent = '';
            el.appendChild(a);

            let linkIdx = 0;
            (function typeLink() {
                if (linkIdx < part.text.length) {
                a.textContent += part.text.charAt(linkIdx++);
                setTimeout(typeLink, 18);
                } else {
                partIdx++; charIdx = 0;
                textNode = document.createTextNode('');
                el.appendChild(textNode);
                setTimeout(type, 18);
                }
            })();
            }

            setTimeout(type, 200);
        }

        if (panelEl) {
            panelEl.addEventListener('shown.bs.modal', runTypewriter);
        }
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
        const chartArea = document.querySelector('.chart-area');
        const backButton = document.getElementById('backButton');
        if (chartArea && backButton && backButton.parentElement !== chartArea) {
            chartArea.appendChild(backButton);
        }
        });
            const ctx = document.getElementById('pieChart').getContext('2d');
            const pieArea = document.getElementById('pieChart').parentElement;

            const levelLabelEl = document.createElement('div');
            levelLabelEl.id = 'pieLevelLabel';
            levelLabelEl.className = 'pie-level-badge';
            pieArea.appendChild(levelLabelEl);

            const LEVEL_NAME = {
                provinsi: 'Provinsi',
                kabupaten: 'Kabupaten',
                kecamatan: 'Kecamatan',
                nama_wilayah: 'Wilayah'
            };

            // fungsi update teks label
            function updatePieLevelLabel(extraText = '') {
                const name = LEVEL_NAME[currentLevel] || currentLevel;
                levelLabelEl.textContent = `${name}${extraText ? ' • ' + extraText : ''}`;
            }
            let currentLevel = 'provinsi'; // Level awal: provinsi
            let history = []; // Menyimpan riwayat data sebelumnya untuk fungsi Back

            const backButton = document.getElementById('backButton');

            const bulanSelect = document.getElementById('filterBulan');
            const tahunSelect = document.getElementById('filterTahun');
            const pengambilanSelect = document.getElementById('filterPengambilan');

            const monthsByYear = <?php echo json_encode($monthsByYear, JSON_UNESCAPED_UNICODE); ?>;
            const monthNames = ['', 'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

            function rebuildMonthOptions() {
                const year = parseInt(tahunSelect.value, 10);
                const list = (monthsByYear[String(year)] || monthsByYear[year]) || [];
                bulanSelect.innerHTML = '';

                if (!list.length) {
                bulanSelect.disabled = true;
                return;
                }
                bulanSelect.disabled = false;

                // urutkan ASC, lalu auto-pilih yang terbaru (max)
                const sorted = list.slice().sort((a,b)=>a-b);
                const latest = sorted[sorted.length - 1];

                for (const m of sorted) {
                const opt = document.createElement('option');
                opt.value = String(m);
                opt.textContent = monthNames[m] || m;
                bulanSelect.appendChild(opt);
                }

                // Jika value sebelumnya tidak valid, pilih bulan terbaru
                if (!sorted.includes(parseInt(bulanSelect.value, 10))) {
                bulanSelect.value = String(latest);
                }
            }

            // Saat Tahun berubah → rebuild bulan & reload chart
            tahunSelect.addEventListener('change', () => {
                rebuildMonthOptions();
                reloadTopLevel();
            });

            // Bulan berubah → reload chart
            bulanSelect.addEventListener('change', reloadTopLevel);
            // Saat Pengambilan Data berubah -> reload chart
            pengambilanSelect.addEventListener('change', reloadTopLevel);

            // Sinkronkan dropdown pada load pertama (jaga-jaga)
            rebuildMonthOptions();

            // fungsi muat ulang top-level (Provinsi) sesuai filter
            function reloadTopLevel() {
                // bersihkan history & kembali ke level provinsi
                history = [];
                currentLevel = 'provinsi';
                backButton.classList.remove('is-visible');

                updatePieLevelLabel();
                const postData = `level=provinsi&sumbu=1&bulan=${encodeURIComponent(bulanSelect.value)}&tahun=${encodeURIComponent(tahunSelect.value)}&pengambilan=${encodeURIComponent(pengambilanSelect.value)}`;

                fetch('api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: postData
                })
                .then(r => r.json())
                .then(data => {
                    pieChart.data.labels = data.labels || [];
                    pieChart.data.datasets[0].data = data.data || [];
                    pieChart.data.datasets[0].backgroundColor = data.colors || [];
                    pieChart.update();
                    updatePieLevelLabel();
                })
                .catch(err => console.error(err));
                }


                let pieChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                    data: [],
                    backgroundColor: [],
                    borderColor: ['#ffffff'],
                    borderWidth: 1
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    legend: { display: true },
                    onClick: function (evt, elements) {
                    if (elements.length === 0) return;
                    if (currentLevel === 'nama_wilayah') return;

                    const index = elements[0]._index;
                    const selectedLabel = pieChart.data.labels[index];

                    history.push({
                        level: currentLevel,
                        labels: pieChart.data.labels.slice(),
                        data: pieChart.data.datasets[0].data.slice(),
                        colors: pieChart.data.datasets[0].backgroundColor.slice()
                    });

                    backButton.classList.add('is-visible');

                    let postData = `sumbu=1&bulan=${encodeURIComponent(bulanSelect.value)}&tahun=${encodeURIComponent(tahunSelect.value)}&pengambilan=${encodeURIComponent(pengambilanSelect.value)}`;

                    if (currentLevel === 'provinsi') {
                        postData += `&provinsi=${encodeURIComponent(selectedLabel)}`;
                        currentLevel = 'kabupaten';
                    } else if (currentLevel === 'kabupaten') {
                        postData += `&kabupaten=${encodeURIComponent(selectedLabel)}`;
                        currentLevel = 'kecamatan';
                    } else if (currentLevel === 'kecamatan') {
                        postData += `&kecamatan=${encodeURIComponent(selectedLabel)}`;
                        currentLevel = 'nama_wilayah';
                    }

                    updatePieLevelLabel();

                    fetch('api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: postData
                    })
                    .then(response => response.json())
                    .then(data => {
                        pieChart.data.labels = data.labels || [];
                        pieChart.data.datasets[0].data = data.data || [];
                        pieChart.data.datasets[0].backgroundColor = data.colors || [];
                        pieChart.update();
                    })
                    .catch(error => console.error('Error:', error));
                    }
                }
            });

            reloadTopLevel();

            backButton.addEventListener('click', function () {
                if (history.length > 0) {
                    const previousState = history.pop(); // Ambil data sebelumnya
                    currentLevel = previousState.level; // Kembali ke level sebelumnya
                    pieChart.data.labels = previousState.labels;
                    pieChart.data.datasets[0].data = previousState.data;
                    pieChart.data.datasets[0].backgroundColor = previousState.colors;
                    pieChart.update();

                    updatePieLevelLabel();

                    // Sembunyikan tombol Back jika tidak ada history
                    if (history.length === 0) {
                        backButton.classList.remove('is-visible');
                    }
                } else {
                    console.log("Tidak ada data sebelumnya untuk kembali.");
                }
            });

        </script>
    
    <script>
        (function(){
        const provSelect   = document.getElementById('filterProvinsi');
        const sevSelect    = document.getElementById('filterSeverity');
        const bulanSelect  = document.getElementById('filterBulan');
        const tahunSelect  = document.getElementById('filterTahun');
        const pengambilanSelect = document.getElementById('filterPengambilan');
        const statPanel    = document.getElementById('statPanel');

        // ====== Setup Map ======
        const map = L.map('mapTungro', { 
            worldCopyJump: true,        // biar mulus saat nyebrang 180° lon/garis tanggal
            inertia: true,
            inertiaDeceleration: 3000,  // gesekan inersia nyaman (opsional)
        });
        const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19});
        osm.addTo(map);

        const indoBounds = L.latLngBounds([[-11.2, 95.0], [6.2, 141.0]]);
        map.fitBounds(indoBounds, { animate: false });
        map.setMaxBounds(indoBounds.pad(0.05));
        map.setMinZoom(3);
        map.setMaxZoom(19);
        map.on('drag', () => map.panInsideBounds(indoBounds, { animate: true }));

        // ====== State ======
        let currentMapLevel = null;     // 'provinsi' | 'kabupaten' | 'kecamatan'
        let geojsonData     = null;     // FeatureCollection dari server
        let gjLayer         = null;

        let zoomLock = false;           // kunci sementara agar zoomend diabaikan
        let zoomChangeTimer = null;     // debounce zoomend
        
        // ====== Util warna insidensi ======
        function getColor(d) {
            return d > 50 ? '#9B1D3A' :
                d > 30 ? '#C12F38' :
                d > 10 ? '#E8765D' :
                d >  0 ? '#E4C98B' : '#58E26F';
        }
        function style(feature){
            const inc = feature?.properties?.stats?.incidence_pct ?? 0;
            return { weight:1, opacity:1, color:'#ffffff', dashArray:'3', fillOpacity:0.75, fillColor:getColor(inc) };
        }
        
        const legend = L.control({ position: 'bottomright' });
        legend.onAdd = function () {
        const div = L.DomUtil.create('div', 'legend');

        // urutannya disesuaikan dengan threshold getColor()
        const items = [
            { label: '0%',        sample: 0   },
            { label: '1–10%',     sample: 1   },
            { label: '>10–30%',   sample: 11  },
            { label: '>30–50%',   sample: 31  },
            { label: '≥50%',      sample: 51  }
        ];

        let html = '<div class="mb-1"><b>Insidensi (%)</b></div>';
        items.forEach(it => {
            html += `<div><i style="background:${getColor(it.sample)}"></i> ${it.label}</div>`;
        });

        div.innerHTML = html;
        return div;
        };
        legend.addTo(map);

        // ====== UI: severity range match ======
        function severityMatch(inc, sevVal){
            if(!sevVal) return true;
            if(sevVal==='0')     return inc == 0;
            if(sevVal==='1-10')  return inc > 0 && inc <= 10;
            if(sevVal==='10-30') return inc > 10 && inc <= 30;
            if(sevVal==='30-50') return inc > 30 && inc <= 50;
            if(sevVal==='50+')   return inc > 50;
            return true;
        }

        // ====== Panel statistik ======
        function showStatsForFeature(f){
            const p = f.properties || {};
            const s = p.stats || {};
            // Tentukan nama tampil sesuai level (atau fallback)
            const name = p.kecamatan || p.kabupaten || p.provinsi || '(tanpa nama)';
            const virLabel = s.virulensi || '-';
            const vars = s.varietas || {};
            const rentan = Number(vars.Rentan ?? vars.rentan ?? 0);
            const tahan  = Number(vars.Tahan  ?? vars.tahan  ?? 0);
            const total  = rentan + tahan;
            const rDisp  = rentan.toFixed(1);
            const tDisp  = tahan.toFixed(1);
            const rWidth = total ? (rentan/total)*100 : 0;
            const tWidth = total ? (tahan /total)*100 : 0;

            const varietasHTML = total === 0
            ? `<div class="text-muted">-</div>`
            : `
                <div class="d-flex justify-content-between small mb-1">
                <span>Varietas</span>
                <span>Rentan ${rDisp}% • Tahan ${tDisp}%</span>
                </div>
                <div class="progress" style="height:20px;">
                <div class="progress-bar bg-warning" role="progressbar" style="width:${rWidth}%;">Rentan ${rDisp}%</div>
                <div class="progress-bar bg-success" role="progressbar" style="width:${tWidth}%;">Tahan ${tDisp}%</div>
                </div>`;

            statPanel.innerHTML = `
            <h6 class="mb-2"><b>${name}</b> — Rata-rata Insidensi: <b>${s.incidence_pct ?? 0}%</b></h6>
            <table class="table table-sm mb-2">
                <tbody>
                <tr><td>Rata-rata Populasi Wereng</td><td class="text-end">${s.mean_populasi_wereng ?? '-'}</td></tr>
                <tr><td>Rata-rata Suhu</td><td class="text-end">${s.mean_suhu ?? '-'} ℃</td></tr>
                <tr><td>Rata-rata Presipitasi</td><td class="text-end">${s.mean_presipitasi ?? '-'} mm</td></tr>
                <tr><td>Virulensi</td><td class="text-end">${virLabel}</td></tr>
                </tbody>
            </table>
            ${varietasHTML}
            `;
        }

        function onEachFeature(feature, layer){
            const p = feature.properties || {};
            const name = p.kecamatan || p.kabupaten || p.provinsi || '(tanpa nama)';
            const inc  = p.stats?.incidence_pct ?? 0;
            const vlab = p.stats?.virulensi ? `<br>Virulensi: <b>${p.stats.virulensi}</b>` : '';
            layer.bindTooltip(`${name}<br>Insidensi: <b>${inc}%</b>${vlab}`, {sticky:true});
            layer.on({
            mouseover: e => { const l = e.target; l.setStyle({weight:2, color:'#666', dashArray:'', fillOpacity:0.85}); l.bringToFront(); },
            mouseout : e => { gjLayer && gjLayer.resetStyle(e.target); },
            click: e => {
                const bounds = e.target.getBounds();
                zoomLock = true;
                map.flyToBounds(bounds, { padding:[20,20], duration:0.35 });
                showStatsForFeature(e.target.feature);
                setTimeout(() => { zoomLock = false; handleZoomChange(); }, 380);
            }
            });
        }

        // ====== Render layer dari geojsonData + filter UI ======
        function applyLayer(opts = {}) {
            const preserveView = !!opts.preserveView;

            if (gjLayer) { map.removeLayer(gjLayer); gjLayer = null; }
            if (!geojsonData) return;

            const selectedProv = provSelect.value;
            const selectedSev  = sevSelect.value;

            const filtered = {
                type: 'FeatureCollection',
                features: (geojsonData.features || []).filter(f => {
                const p = f.properties || {};
                const inc = p.stats?.incidence_pct ?? 0;
                const provOk = !selectedProv || (p.provinsi === selectedProv);
                const sevOk  = severityMatch(inc, selectedSev);
                return provOk && sevOk;
                })
            };

            if (!filtered.features.length) {
                statPanel.innerHTML = `<div class="alert alert-warning mb-0">Tidak ada area yang cocok dengan filter.</div>`;
                if (!opts.preserveView) {
                    try { map.fitBounds(indoBounds, { animate: true, padding:[20,20] }); } catch(e){}
                }
                return;
            }


            gjLayer = L.geoJSON(filtered, { style, onEachFeature });
            gjLayer.addTo(map);

            // Hanya fitBounds saat BUKAN ganti level (misal load awal / user apply filter berat)
            if (!preserveView) {
                try { map.fitBounds(gjLayer.getBounds(), { padding:[20,20], animate:false }); } catch(e){}
            }

            // tampilkan stats pertama
            showStatsForFeature(filtered.features[0]);
        }


        // ====== Hitung level dari persentase zoom ======
        function decideLevelByZoom(){
            const z    = map.getZoom();
            const minZ = map.getMinZoom() ?? 0;
            const maxZ = map.getMaxZoom() ?? (osm.options.maxZoom ?? 19);
            const pct  = Math.max(0, Math.min(100, ((z - minZ) / (maxZ - minZ)) * 100));
            if (pct < 30) return 'provinsi';
            if (pct < 40) return 'kabupaten';
            return 'kecamatan';
        }


        // ====== Load data dari server sesuai level ======
        let loading = false;
        async function loadGeoByLevel(level, opts = {}) {
            if (loading) return;
            loading = true;
            currentMapLevel = level;
            
            // -- Sertakan semua filter saat memuat data peta --
            const body = new URLSearchParams({
                level: level,
                bulan: bulanSelect ? (bulanSelect.value || '') : '',
                tahun: tahunSelect ? (tahunSelect.value || '') : '',
                pengambilan: pengambilanSelect ? (pengambilanSelect.value || '') : '', // <-- Tambahkan ini
                filter_provinsi: provSelect ? (provSelect.value || '') : ''
            });

            try {
                const resp = await fetch('map_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
                });
                const json = await resp.json();
                geojsonData = json.geojson || {type:'FeatureCollection',features:[]};
                // saat ganti level → preserveView=true untuk hindari bounce
                applyLayer({ preserveView: !!opts.preserveView });
            } catch (e) {
                console.error(e);
            } finally {
                loading = false;
            }
        }

        // ========= Refresh opsi Provinsi & Keparahan =========
        async function refreshFilters(opts = { reload: true }) {
            const prevProv = provSelect.value || '';
            const prevSev  = sevSelect.value  || '';

            // -- Sertakan semua filter saat refresh --
            const body = new URLSearchParams({
                act:   'filters',
                bulan: bulanSelect ? (bulanSelect.value || '') : '',
                tahun: tahunSelect ? (tahunSelect.value || '') : '',
                pengambilan: pengambilanSelect ? (pengambilanSelect.value || '') : '' // <-- Tambahkan ini
            });

            try {
            const resp = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            const json = await resp.json();

            // --- Rebuild Provinsi ---
            provSelect.innerHTML = '';
            const optAllProv = document.createElement('option');
            optAllProv.value = '';
            optAllProv.textContent = 'Semua Provinsi';
            provSelect.appendChild(optAllProv);

            (json.provinsi || []).forEach(p => {
                const o = document.createElement('option');
                o.value = p;
                o.textContent = p;
                provSelect.appendChild(o);
            });

            // restore pilihan lama jika masih tersedia
            if (prevProv && (json.provinsi || []).includes(prevProv)) {
                provSelect.value = prevProv;
            } else {
                provSelect.value = '';
            }

            // --- Rebuild Severity ---
            const labelMap = {
                '0'     : '0% (Tidak Ada Insidensi)',
                '1-10'  : '1–10% (Ringan)',
                '10-30' : '>10–30% (Sedang)',
                '30-50' : '>30–50% (Berat)',
                '50+'   : '≥50% (Sangat Berat)'
            };

            sevSelect.innerHTML = '';
            const optAllSev = document.createElement('option');
            optAllSev.value = '';
            optAllSev.textContent = 'Semua Keparahan';
            sevSelect.appendChild(optAllSev);

            (json.severity || []).forEach(k => {
                const o = document.createElement('option');
                o.value = k;
                o.textContent = labelMap[k] || k;
                sevSelect.appendChild(o);
            });

            if (prevSev && (json.severity || []).includes(prevSev)) {
                sevSelect.value = prevSev;
            } else {
                sevSelect.value = '';
            }

            // setelah opsi diperbarui → muat ulang layer sesuai filter baru
            if (opts.reload) {
                loadGeoByLevel(currentMapLevel || 'provinsi', { preserveView: true });
            }
            } catch (e) {
            console.error('refreshFilters failed', e);
            }
        }

        // ===== Reaksi terhadap filter UI =====
        sevSelect.addEventListener('change', () => applyLayer({ preserveView: true }));
        // Fokus ke area terpilih (fitBounds) setelah pilih provinsi
        provSelect.addEventListener('change', () => {
            loadGeoByLevel(currentMapLevel || 'provinsi', { preserveView: false });
        });

        if (bulanSelect) bulanSelect.addEventListener('change', () => {
            refreshFilters({ reload: true });
        });
        if (tahunSelect) tahunSelect.addEventListener('change', () => {
            refreshFilters({ reload: true });
        });
        // -- Tambahkan event listener untuk filter baru --
        if (pengambilanSelect) pengambilanSelect.addEventListener('change', () => {
            refreshFilters({ reload: true });
        });


        // ===== Initial load =====
        // 1) bangun opsi awal sesuai bulan/tahun default
        refreshFilters({ reload: false }).then(() => {
            // 2) baru load peta pertama kali
            loadGeoByLevel(decideLevelByZoom(), { preserveView: false });
        });


        // ====== Reaksi terhadap zoom ======
        function handleZoomChange(){
            if (zoomLock) return; // sedang lock → abaikan
            if (zoomChangeTimer) clearTimeout(zoomChangeTimer);
            zoomChangeTimer = setTimeout(() => {
                const desired = decideLevelByZoom();
                if (desired !== currentMapLevel) {
                // ganti level tanpa fitBounds (preserve view)
                loadGeoByLevel(desired, { preserveView: true });
                }
            }, 120); // debounce 120ms
        }
        map.on('zoomend', handleZoomChange);

        })();
    </script>

    <script>
        (function(){
        const gaugeEl = document.getElementById('apiGauge');
        if(!gaugeEl) return;

        const pct       = Number(gaugeEl.dataset.percent || 0); // 0..100
        const stepsText = gaugeEl.dataset.steps || '';

        const value = Math.max(0, Math.min(100, pct));
        const data  = [value, 100 - value];

        const filledColor = 'rgba(54, 162, 235, 1)';   // warna progress
        const emptyColor  = 'rgba(230, 236, 245, .9)';

        const ctxGauge = gaugeEl.getContext('2d');
        new Chart(ctxGauge, {
            type: 'doughnut',
            data: {
            labels: ['Terpakai','Sisa'],
            datasets: [{
                data: data,
                backgroundColor: [filledColor, emptyColor],
                borderWidth: 0
            }]
            },
            options: {
            maintainAspectRatio: false,
            cutoutPercentage: 75,
            rotation: Math.PI,          // mulai dari 180°
            circumference: Math.PI,     // setengah lingkaran
            legend: { display: false },
            tooltips: { enabled: false },
            animation: { animateRotate: true, duration: 700 }
            }
        });

        // update label overlay
        const pctLabel  = document.querySelector('.gauge-percent');
        const stepLabel = document.querySelector('.gauge-steps');
        if (pctLabel)  pctLabel.textContent  = value.toFixed(1) + '%';
        if (stepLabel) stepLabel.textContent = stepsText;
        })();
    </script>



</body>

<?php
    mysqli_close($conn);
        
?>