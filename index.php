<?php
/**
 * 🧾 レシート解析システム - 逐次アップロード版 (Nginx 413 回避)
 */

// --- 1. 設定と環境構成 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://cv-receipt.cognitiveservices.azure.com/"; 
$apiKey   = "acFa9r1gRfWfvNsBjsLFsyec437ihmUsWXpA1WKVYD4z5yrPBrrMJQQJ99CBACNns7RXJ3w3AAAFACOGcllL"; 
$logFile  = 'ocr.log';

// --- 2. Azure SQL 接続設定 ---
$serverName = "tcp:receipt-server.database.windows.net,1433"; 
$connectionOptions = array(
    "Database" => "db_receipt",
    "Uid" => "jn240329",
    "PWD" => "15828415312dY",
    "CharacterSet" => "UTF-8"
);

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die("<pre>" . print_r(sqlsrv_errors(), true) . "</pre>");
}

// --- 3. アクション処理 ---
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
            header('Content-Disposition: attachment; filename="ocr.log"');
            readfile($logFile); exit;
        }
    }
    if ($action == 'clear_view') {
        header("Location: " . strtok($_SERVER["PHP_SELF"], '?'));
        exit;
    }
}

// --- 4. OCR 解析 & DB保存 (Ajax用) ---
$results = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $batchId = $_POST['batch_id'] ?? uniqid('BT_');
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];
        $imgData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) continue;

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $logTotal = 0;
        $stopFlag = false;
        $usedBufferIndex = -1;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);
            if (preg_match('/合計|合計額/u', $pureText) && preg_match('/[¥￥]([\d,]+)/u', $text, $totalMatch)) {
                $logTotal = (int)str_replace(',', '', $totalMatch[1]);
                $stopFlag = true;
            }
            if (preg_match('/内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) { $stopFlag = true; continue; }
            if ($stopFlag) continue;

            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $nameInLine);
                $finalName = "";
                if (mb_strlen($cleanNameInLine) >= 2 && !preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $finalName = $cleanNameInLine;
                } else {
                    for ($j = $i - 1; $j > $usedBufferIndex; $j--) {
                        $prev = trim($lines[$j]['text']);
                        if (mb_strlen($prev) >= 2 && !preg_match('/領|収|証|合|計|%|店|電話|¥|￥|No/u', $prev)) {
                            $finalName = str_replace(['＊', '*', ' ', '√', '軽', '轻'], '', $prev);
                            $usedBufferIndex = $j; break;
                        }
                    }
                }
                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計|内訳/u', $finalName)) {
                    $currentItems[] = ['name' => $finalName, 'price' => $price];
                }
            }
        }

        foreach ($currentItems as $it) {
            $sql = "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 0)";
            sqlsrv_query($conn, $sql, [$batchId, $fileName, $it['name'], $it['price']]);
        }
        if ($logTotal > 0) {
            $sql = "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 1)";
            sqlsrv_query($conn, $sql, [$batchId, $fileName, '合計(OCR読み取り)', $logTotal]);
        }
        
        // 解析結果の一部をJSONで返す（逐次表示用）
        echo json_encode(['file' => $fileName, 'items' => $currentItems, 'total' => $logTotal]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>レシート解析システム</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f9; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; }
        .card { border-left: 4px solid #2ecc71; background: #fafafa; padding: 10px; margin-bottom: 10px; border-radius: 4px; font-size: 13px; }
        .row { display: flex; justify-content: space-between; border-bottom: 1px dashed #eee; padding: 4px 0; }
        .btn-main { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
        #resultsArea { margin-top: 20px; }
        .nav-bar { margin-top: 20px; display: flex; gap: 10px; justify-content: center; }
        .nav-link { font-size: 12px; text-decoration: none; color: #666; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 レシート解析</h2>
        <input type="file" id="fileInput" multiple style="width:100%; margin-bottom:20px;">
        <button id="submitBtn" class="btn-main">解析を開始</button>
        <div id="status" style="display:none; text-align:center; margin-top:10px; color:#1890ff;"></div>
        
        <div id="resultsArea"></div>

        <div class="nav-bar">
            <a href="?action=csv" class="nav-link">📥 CSV保存</a>
            <a href="?action=download_log" class="nav-link">📝 ログ</a>
            <a href="?action=clear_view" class="nav-link">🔄 クリア</a>
        </div>
    </div>

    <script>
    document.getElementById('submitBtn').onclick = async function() {
        const fileInput = document.getElementById('fileInput');
        const resultsArea = document.getElementById('resultsArea');
        const status = document.getElementById('status');
        const files = fileInput.files;
        if (!files.length) return;

        this.disabled = true;
        status.style.display = "block";
        resultsArea.innerHTML = "<h3>解析結果:</h3>";
        
        const batchId = "BT_" + Date.now();

        for (let i = 0; i < files.length; i++) {
            status.innerText = `処理中 (${i + 1} / ${files.length}): ${files[i].name}`;
            
            // 1. 圧縮
            const compressed = await compressImg(files[i]);
            
            // 2. 1枚ずつ送信 (逐次送信)
            const formData = new FormData();
            formData.append('receipts[]', compressed, files[i].name);
            formData.append('batch_id', batchId);

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                if (!response.ok) throw new Error("Server Error: " + response.status);
                const resData = await response.json();
                
                // 3. 画面に1枚ずつ結果を表示
                renderResult(resData);
            } catch (err) {
                resultsArea.innerHTML += `<div style="color:red;">❌ ${files[i].name}: 解析失敗 (${err.message})</div>`;
            }
        }

        status.innerText = "✅ すべての処理が完了しました";
        this.disabled = false;
    };

    function renderResult(data) {
        const resultsArea = document.getElementById('resultsArea');
        let html = `<div class="card"><strong>📄 ${data.file}</strong>`;
        data.items.forEach(it => {
            html += `<div class="row"><span>${it.name}</span><span>¥${it.price.toLocaleString()}</span></div>`;
        });
        if (data.total > 0) {
            html += `<div class="row" style="color:red; font-weight:bold;"><span>合計(OCR)</span><span>¥${data.total.toLocaleString()}</span></div>`;
        }
        html += `</div>`;
        resultsArea.innerHTML += html;
    }

    function compressImg(file) {
        return new Promise(resolve => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = (e) => {
                const img = new Image();
                img.src = e.target.result;
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    let w = img.width, h = img.height;
                    const maxSide = 1200;
                    if (w > maxSide || h > maxSide) {
                        if (w > h) { h = h * (maxSide/w); w = maxSide; }
                        else { w = w * (maxSide/h); h = maxSide; }
                    }
                    canvas.width = w; canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    canvas.toBlob(blob => resolve(new File([blob], file.name, {type:'image/jpeg'})), 'image/jpeg', 0.8);
                };
            };
        });
    }
    </script>
</body>
</html>
