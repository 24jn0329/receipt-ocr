<?php
/**
 * 🧾 Smart Receipt AI - 最終修正版
 * 修正内容：座標統合の許容範囲を拡大、正規表現の柔軟化、ログ・CSV完備
 */

@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://cv-receipt.cognitiveservices.azure.com/";
$apiKey   = "acFa9r1gRfWfvNsBjsLFsyec437ihmUsWXpA1WKVYD4z5yrPBrrMJQQJ99CBACNns7RXJ3w3AAAFACOGcllL";
$logFile  = 'ocr_debug.log';

// --- Azure SQL 接続設定 ---
$serverName = "tcp:receipt-server.database.windows.net,1433";
$connectionOptions = [
    "Database" => "db_receipt",
    "Uid" => "jn240329",
    "PWD" => "15828415312dY",
    "CharacterSet" => "UTF-8"
];
$conn = sqlsrv_connect($serverName, $connectionOptions);

// --- アクション処理 ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'バッチID', 'ファイル名', '項目名', '金額', '合計フラグ', '登録日時']);
        $sql = "SELECT id, upload_batch_id, file_name, item_name, price, is_total, created_at FROM Receipts ORDER BY id DESC";
        $stmt = sqlsrv_query($conn, $sql);
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($output, [$row['id'],$row['upload_batch_id'],$row['file_name'],$row['item_name'],$row['price'],$row['is_total'],$row['created_at']->format('Y-m-d H:i:s')]);
        }
        fclose($output); exit;
    }
    if ($action == 'download_log') {
        if (file_exists($logFile)) {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="ocr_debug.log"');
            readfile($logFile); exit;
        }
    }
    if ($action == 'clear_view') {
        header("Location: " . strtok($_SERVER["PHP_SELF"], '?'));
        exit;
    }
}

// --- OCR解析処理 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $batchId = $_POST['batch_id'] ?? uniqid('BT_');
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];
        
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($tmpName));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        $rawLines = $data['readResult']['blocks'][0]['lines'] ?? [];
        
        // 座標統合：横方向に離れた「商品名」と「金額」をくっつける
        $rows = [];
        foreach ($rawLines as $line) {
            $yCoords = [$line['boundingBox'][1], $line['boundingBox'][3], $line['boundingBox'][5], $line['boundingBox'][7]];
            $yCenter = array_sum($yCoords) / 4;
            $found = false;
            foreach ($rows as &$row) {
                if (abs($row['y'] - $yCenter) < 20) { // 許容範囲を20pxに拡大
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
            // 合計判定
            if (preg_match('/合計/u', $text)) {
                if (preg_match('/[¥￥\d,]{3,}/u', $text, $m)) {
                    $logTotal = (int)preg_replace('/[^\d]/', '', $m[0]);
                }
                continue;
            }
            // 除外
            if (preg_match('/消費税|対象|お釣|預り|現計/u', $text)) continue;

            // 商品名と金額の抽出
            if (preg_match('/(.*?)([¥￥]?\s?\d{1,3}(?:,\d{3})*|\d{2,})$/u', $text, $matches)) {
                $name = preg_replace('/[¥￥\s　＊\*√軽轻\.\-\/…．]/u', '', $matches[1]);
                $price = (int)preg_replace('/[^\d]/', '', $matches[2]);
                if (mb_strlen($name) >= 2 && $price > 0 && $price < 100000) {
                    $currentItems[] = ['name' => $name, 'price' => $price];
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
        
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] $fileName: Found " . count($currentItems) . " items.\n", FILE_APPEND);
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
    <title>Smart Receipt AI</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #4361ee; --success: #4cc9f0; --danger: #f72585; --bg: #f8f9fd; --text-main: #2b2d42; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); padding: 20px; color: var(--text-main); }
        .box { max-width: 600px; margin: auto; background: white; padding: 30px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .upload-section { border: 2px dashed #adc1ff; padding: 30px; text-align: center; border-radius: 15px; margin-bottom: 20px; background: #f1f4ff; }
        .btn-main { width: 100%; padding: 15px; background: var(--primary); color: white; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; }
        .card { border: 1px solid #edf2f7; padding: 15px; margin-top: 15px; border-radius: 12px; background: #fff; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f8f9fa; }
        .total-box { background: #2b2d42; color: #4cc9f0; padding: 25px; margin-top: 25px; border-radius: 15px; text-align: center; }
        .nav-bar { margin-top: 30px; display: flex; gap: 10px; justify-content: center; }
        .nav-link { font-size: 12px; color: #8d99ae; text-decoration: none; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center; color:var(--primary);">🧾 Smart Receipt AI</h2>
        <div class="upload-section">
            <input type="file" id="fileInput" multiple accept="image/*">
        </div>
        <button id="submitBtn" class="btn-main">解析を開始して保存</button>
        <div id="status" style="display:none; text-align:center; margin-top:10px;"></div>
        <div id="resultsArea"></div>
        <div id="totalArea" class="total-box" style="display:none;">
            <div>今回の解析商品 合計</div>
            <div style="font-size:32px; font-weight:800;">¥<span id="grandTotal">0</span></div>
        </div>
        <div class="nav-bar">
            <a href="?action=csv" class="nav-link"><i class="fa-solid fa-file-csv"></i> CSV保存</a>
            <a href="?action=download_log" class="nav-link"><i class="fa-solid fa-terminal"></i> ログ</a>
            <a href="?action=clear_view" class="nav-link" style="color:var(--danger);"><i class="fa-solid fa-eraser"></i> クリア</a>
        </div>
    </div>
    <script>
    let gTotal = 0;
    document.getElementById('submitBtn').onclick = async function() {
        const files = document.getElementById('fileInput').files;
        if(!files.length) return alert("ファイルを選んでください");
        this.disabled = true;
        const status = document.getElementById('status');
        status.style.display = "block";
        
        for (let i=0; i<files.length; i++) {
            status.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> 解析中... (${i+1}/${files.length})`;
            const fd = new FormData();
            fd.append('receipts[]', files[i]);
            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                gTotal += data.sum;
                document.getElementById('grandTotal').innerText = gTotal.toLocaleString();
                document.getElementById('totalArea').style.display = "block";
                let html = `<div class="card"><small>${data.file}</small>`;
                data.items.forEach(it => {
                    html += `<div class="row"><span>${it.name}</span><span>¥${it.price.toLocaleString()}</span></div>`;
                });
                html += `</div>`;
                document.getElementById('resultsArea').insertAdjacentHTML('beforeend', html);
            } catch (e) { console.error(e); }
        }
        status.innerText = "完了しました";
        this.disabled = false;
    };
    </script>
</body>
</html>
