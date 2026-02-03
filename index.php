<?php
/**
 * 🧾 Smart Receipt AI - フル機能 & 高精度安定版
 * 特徴：座標ベースの行結合 (環境差を解消) + ログ管理 + CSV出力
 */

// --- 1. 設定と環境構成 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://cv-receipt.cognitiveservices.azure.com/";
$apiKey   = "acFa9r1gRfWfvNsBjsLFsyec437ihmUsWXpA1WKVYD4z5yrPBrrMJQQJ99CBACNns7RXJ3w3AAAFACOGcllL";
$logFile  = 'ocr_debug.log';

// --- 2. Azure SQL 接続設定 ---
$serverName = "tcp:receipt-server.database.windows.net,1433";
$connectionOptions = [
    "Database" => "db_receipt",
    "Uid" => "jn240329",
    "PWD" => "15828415312dY",
    "CharacterSet" => "UTF-8"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die(json_encode(['error' => 'Database connection failed: ' . print_r(sqlsrv_errors(), true)]));
}

// --- 3. アクション処理 (CSV/ログ/クリア) ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // CSVダウンロード
    if ($action == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'バッチID', 'ファイル名', '項目名', '金額', '合計フラグ', '登録日時']);
        $sql = "SELECT id, upload_batch_id, file_name, item_name, price, is_total, created_at FROM Receipts ORDER BY id DESC";
        $stmt = sqlsrv_query($conn, $sql);
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'], $row['upload_batch_id'], $row['file_name'], 
                $row['item_name'], $row['price'], $row['is_total'], 
                $row['created_at']->format('Y-m-d H:i:s')
            ]);
        }
        fclose($output); exit;
    }

    // ログダウンロード
    if ($action == 'download_log') {
        if (file_exists($logFile)) {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="ocr_debug.log"');
            readfile($logFile); exit;
        } else {
            die("ログファイルが見つかりません。");
        }
    }

    // 表示クリア
    if ($action == 'clear_view') {
        header("Location: " . strtok($_SERVER["PHP_SELF"], '?'));
        exit;
    }
}

// --- 4. AJAX POST 処理 (OCR解析ロジック) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $batchId = $_POST['batch_id'] ?? uniqid('BT_');
    
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];
        $imgData = file_get_contents($tmpName);

        // Azure OCR API 呼び出し
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // ログ記録
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] File: $fileName, HTTP: $httpCode\n", FILE_APPEND);

        if ($httpCode !== 200) {
            header('HTTP/1.1 500 Internal Server Error');
            echo json_encode(['error' => "OCR API Error: $httpCode"]); exit;
        }

        $data = json_decode($response, true);
        $rawLines = $data['readResult']['blocks'][0]['lines'] ?? [];
        
        // --- 【重要】座標ベースの行統合ロジック ---
        // 同じ高さ(y軸)にある文字列を1つの行として結合し、環境差を吸収する
        $rows = [];
        foreach ($rawLines as $line) {
            $yCenter = ($line['boundingBox'][1] + $line['boundingBox'][3] + $line['boundingBox'][5] + $line['boundingBox'][7]) / 4;
            $found = false;
            foreach ($rows as &$row) {
                if (abs($row['y'] - $yCenter) < 15) { // 15px以内のズレは同じ行とみなす
                    $row['text'] .= " " . $line['text'];
                    $found = true;
                    break;
                }
            }
            if (!$found) $rows[] = ['y' => $yCenter, 'text' => $line['text']];
        }

        $currentItems = [];
        $logTotal = 0;
        
        foreach ($rows as $row) {
            $text = trim($row['text']);
            
            // 1. 合計金額の抽出
            if (preg_match('/合計/u', $text)) {
                if (preg_match('/[¥￥\d,]{3,}/u', $text, $m)) {
                    $val = (int)preg_replace('/[^\d]/', '', $m[0]);
                    if ($val > $logTotal) $logTotal = $val;
                }
                continue;
            }

            // 2. 除外ワード
            if (preg_match('/消費税|対象|お釣|預り|現計|点数|番号|TEL|登録/u', $text)) continue;

            // 3. 商品名と金額の抽出 (行末の数字を金額、それ以外を品名)
            if (preg_match('/(.*?)(\d{1,3}(?:,\d{3})*|\d{2,})$/u', $text, $matches)) {
                $itemName = trim($matches[1]);
                $price = (int)str_replace(',', '', $matches[2]);
                
                // 品名の不要な記号を削除
                $itemName = preg_replace('/[¥￥\s　＊\*√軽轻\.\-\/…．]/u', '', $itemName);

                if (mb_strlen($itemName) >= 2 && $price > 0) {
                    $currentItems[] = ['name' => $itemName, 'price' => $price];
                }
            }
        }

        // DB保存
        $sumOfItems = 0;
        foreach ($currentItems as $it) {
            $sql = "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 0)";
            sqlsrv_query($conn, $sql, [$batchId, $fileName, $it['name'], $it['price']]);
            $sumOfItems += $it['price'];
        }
        if ($logTotal > 0) {
            $sql = "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 1)";
            sqlsrv_query($conn, $sql, [$batchId, $fileName, '合計(OCR読み取り)', $logTotal]);
        }
        
        header('Content-Type: application/json');
        echo json_encode(['file' => $fileName, 'items' => $currentItems, 'total' => $logTotal, 'sum' => $sumOfItems]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Receipt AI - 完全版</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #4361ee; --secondary: #3f37c9; --success: #4cc9f0; --danger: #f72585; --bg: #f8f9fd; --card-bg: #ffffff; --text-main: #2b2d42; --text-muted: #8d99ae; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); padding: 20px; color: var(--text-main); line-height: 1.6; }
        .box { max-width: 650px; margin: 40px auto; background: var(--card-bg); padding: 40px; border-radius: 24px; box-shadow: 0 20px 60px rgba(0,0,0,0.05); }
        h2 { text-align: center; font-weight: 700; color: var(--primary); margin-bottom: 30px; }
        .upload-section { background: #f1f4ff; border: 2px dashed #adc1ff; border-radius: 16px; padding: 40px 20px; text-align: center; margin-bottom: 25px; }
        .btn-main { width: 100%; padding: 18px; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; border: none; border-radius: 14px; font-weight: 600; cursor: pointer; display: flex; justify-content: center; align-items: center; gap: 12px; }
        #status { text-align: center; margin-top: 20px; padding: 15px; border-radius: 12px; background: #e7f0ff; color: var(--primary); font-size: 14px; }
        .card { background: #fff; border: 1px solid #edf2f7; padding: 20px; margin-bottom: 15px; border-radius: 18px; }
        .row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f8f9fa; }
        .grand-total-box { margin-top: 35px; padding: 30px; background: var(--text-main); color: white; border-radius: 22px; text-align: center; }
        .amount-big { font-size: 40px; font-weight: 800; color: var(--success); }
        .nav-bar { margin-top: 45px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .nav-link { font-size: 12px; color: var(--text-muted); text-decoration: none; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center; transition: 0.3s; }
        .nav-link:hover { background: #eee; }
    </style>
</head>
<body>
    <div class="box">
        <h2><i class="fa-solid fa-receipt"></i> Smart Receipt AI</h2>
        
        <div class="upload-section">
            <i class="fa-solid fa-cloud-arrow-up" style="font-size: 48px; color: var(--primary); margin-bottom: 15px; display: block;"></i>
            <input type="file" id="fileInput" multiple accept="image/*">
            <p style="font-size: 11px; color: var(--text-muted);">座標統合解析により環境差を解消済み</p>
        </div>

        <button id="submitBtn" class="btn-main"><i class="fa-solid fa-wand-magic-sparkles"></i> 解析を開始して保存</button>
        
        <div id="status" style="display:none;"><i class="fa-solid fa-spinner fa-spin"></i> <span id="statusText">接続中...</span></div>
        <div id="resultsArea"></div>

        <div id="grandTotalContainer" class="grand-total-box" style="display:none;">
            <div>今回の解析商品 合計</div>
            <div class="amount-big">¥<span id="allFileSum">0</span></div>
        </div>

        <div class="nav-bar">
            <a href="?action=csv" class="nav-link"><i class="fa-solid fa-file-csv"></i><br>CSV保存</a>
            <a href="?action=download_log" class="nav-link"><i class="fa-solid fa-terminal"></i><br>ログ表示</a>
            <a href="?action=clear_view" class="nav-link" style="color: var(--danger);"><i class="fa-solid fa-eraser"></i><br>クリア</a>
        </div>
    </div>

    <script>
    let runningTotal = 0;

    document.getElementById('submitBtn').onclick = async function() {
        const fileInput = document.getElementById('fileInput');
        const resultsArea = document.getElementById('resultsArea');
        const status = document.getElementById('status');
        const totalDisplay = document.getElementById('allFileSum');
        const files = fileInput.files;
        
        if (!files.length) return alert("ファイルを選択してください。");

        this.disabled = true;
        status.style.display = "block";
        resultsArea.innerHTML = "<h3>解析結果:</h3>";
        runningTotal = 0;
        
        const batchId = "BT_" + Date.now();

        for (let i = 0; i < files.length; i++) {
            document.getElementById('statusText').innerText = `解析中 (${i+1}/${files.length}): ${files[i].name}`;
            try {
                const formData = new FormData();
                formData.append('receipts[]', files[i]);
                formData.append('batch_id', batchId);

                const response = await fetch('', { method: 'POST', body: formData });
                const resData = await response.json();

                runningTotal += resData.sum;
                totalDisplay.innerText = runningTotal.toLocaleString();
                document.getElementById('grandTotalContainer').style.display = "block";

                let html = `<div class="card"><small style="color:var(--text-muted)">${resData.file}</small>`;
                resData.items.forEach(it => {
                    html += `<div class="row"><span>${it.name}</span><span>¥${it.price.toLocaleString()}</span></div>`;
                });
                if (resData.total > 0) {
                    html += `<div class="row" style="color:var(--danger); font-weight:bold;"><span>(OCR読取合計)</span><span>¥${resData.total.toLocaleString()}</span></div>`;
                }
                html += `</div>`;
                resultsArea.insertAdjacentHTML('beforeend', html);
            } catch (err) {
                resultsArea.insertAdjacentHTML('beforeend', `<div style="color:red">失敗: ${files[i].name}</div>`);
            }
        }
        document.getElementById('statusText').innerText = "完了しました";
        this.disabled = false;
    };
    </script>
</body>
</html>
