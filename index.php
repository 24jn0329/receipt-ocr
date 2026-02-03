<?php
/**
 * 🧾 Smart Receipt AI - 完整集成美化版
 * 功能：Azure OCR, Azure SQL, 逐张异步上传 (绕过 Nginx 413 限制), 实时总计
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

// --- 4. AJAX POST 処理 (逐次アップロード用) ---
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

        if ($httpCode !== 200) {
            header('HTTP/1.1 500 Internal Server Error');
            echo json_encode(['error' => 'OCR API Error']); exit;
        }

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
    <title>Smart Receipt AI - 解析システム</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --danger: #f72585;
            --bg: #f8f9fd;
            --card-bg: #ffffff;
            --text-main: #2b2d42;
            --text-muted: #8d99ae;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background: var(--bg); 
            padding: 20px; 
            color: var(--text-main);
            line-height: 1.6;
        }

        .box { 
            max-width: 650px; 
            margin: 40px auto; 
            background: var(--card-bg); 
            padding: 40px; 
            border-radius: 24px; 
            box-shadow: 0 20px 60px rgba(0,0,0,0.05);
        }

        h2 { 
            text-align: center; 
            font-weight: 700; 
            font-size: 26px;
            margin-bottom: 30px;
            letter-spacing: -0.5px;
            color: var(--primary);
        }

        /* アップロードエリア */
        .upload-section {
            background: #f1f4ff;
            border: 2px dashed #adc1ff;
            border-radius: 16px;
            padding: 40px 20px;
            text-align: center;
            transition: all 0.3s ease;
            margin-bottom: 25px;
        }

        .upload-section:hover { border-color: var(--primary); background: #ebf0ff; }

        input[type="file"] { margin: 15px 0; font-size: 14px; width: 100%; max-width: 250px; }

        /* ボタン */
        .btn-main { 
            width: 100%; 
            padding: 18px; 
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white; 
            border: none; 
            border-radius: 14px; 
            font-size: 16px; 
            font-weight: 600; 
            cursor: pointer; 
            transition: all 0.2s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
        }

        .btn-main:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(67, 97, 238, 0.3); }
        .btn-main:disabled { background: #cbd5e0; transform: none; box-shadow: none; cursor: not-allowed; }

        /* ステータス表示 */
        #status {
            text-align: center;
            margin-top: 20px;
            padding: 15px;
            border-radius: 12px;
            background: #e7f0ff;
            color: var(--primary);
            font-size: 14px;
            font-weight: 600;
        }

        /* 解析結果カード */
        .card { 
            background: #fff;
            border: 1px solid #edf2f7;
            padding: 20px; 
            margin-bottom: 15px; 
            border-radius: 18px; 
            animation: slideIn 0.4s ease forwards;
        }

        @keyframes slideIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .file-info { font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px; }

        .row { 
            display: flex; 
            justify-content: space-between; 
            padding: 10px 0; 
            border-bottom: 1px solid #f8f9fa;
        }

        .row span:first-child { color: #4a5568; font-weight: 500; font-size: 14px; }
        .row span:last-child { font-family: 'Monaco', 'Courier New', monospace; font-weight: 700; color: var(--text-main); }

        /* 合計金額ボックス */
        .grand-total-box { 
            margin-top: 35px; 
            padding: 30px; 
            background: var(--text-main);
            color: white;
            border-radius: 22px; 
            text-align: center;
            box-shadow: 0 20px 40px rgba(43, 45, 66, 0.2);
        }

        .grand-total-box div:first-child { font-size: 14px; opacity: 0.7; margin-bottom: 8px; }
        .amount-big { font-size: 40px; font-weight: 800; color: var(--success); }

        /* ナビゲーション */
        .nav-bar { 
            margin-top: 45px; 
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }

        .nav-link { 
            font-size: 12px; 
            color: var(--text-muted); 
            text-decoration: none; 
            padding: 12px; 
            border: 1px solid #e2e8f0; 
            border-radius: 12px; 
            text-align: center;
            transition: all 0.2s;
            background: white;
        }

        .nav-link:hover { background: #f8f9fa; border-color: var(--text-muted); color: var(--text-main); }
        .nav-link i { display: block; font-size: 20px; margin-bottom: 6px; }

        #resultsArea h3 { font-size: 18px; margin: 30px 0 15px; color: var(--primary); display: flex; align-items: center; gap: 8px; }
    </style>
</head>
<body>
    <div class="box">
        <h2><i class="fa-solid fa-receipt"></i> Smart Receipt AI</h2>
        
        <div class="upload-section">
            <i class="fa-solid fa-cloud-arrow-up" style="font-size: 48px; color: var(--primary); margin-bottom: 15px; display: block;"></i>
            <p style="font-weight:600; margin-bottom:5px;">画像をアップロード</p>
            <input type="file" id="fileInput" multiple accept="image/*">
            <p style="font-size: 11px; color: var(--text-muted);">複数枚のレシートをまとめてスキャンできます</p>
        </div>

        <button id="submitBtn" class="btn-main">
            <i class="fa-solid fa-wand-magic-sparkles"></i>
            解析を開始してDBに保存
        </button>
        
        <div id="status" style="display:none;">
            <i class="fa-solid fa-spinner fa-spin"></i> <span id="statusText">接続中...</span>
        </div>
        
        <div id="resultsArea"></div>

        <div id="grandTotalContainer" class="grand-total-box" style="display:none;">
            <div><i class="fa-solid fa-chart-line"></i> 今回の解析商品 合計</div>
            <div class="amount-big">¥<span id="allFileSum">0</span></div>
        </div>

        <div class="nav-bar">
            <a href="?action=csv" class="nav-link"><i class="fa-solid fa-file-csv"></i>CSV保存</a>
            <a href="?action=download_log" class="nav-link"><i class="fa-solid fa-terminal"></i>ログ表示</a>
            <a href="?action=clear_view" class="nav-link" style="color: var(--danger);"><i class="fa-solid fa-eraser"></i>クリア</a>
        </div>
    </div>

    <script>
    let runningTotal = 0;

    document.getElementById('submitBtn').onclick = async function() {
        const fileInput = document.getElementById('fileInput');
        const resultsArea = document.getElementById('resultsArea');
        const status = document.getElementById('status');
        const totalContainer = document.getElementById('grandTotalContainer');
        const totalDisplay = document.getElementById('allFileSum');
        const files = fileInput.files;
        
        if (!files.length) {
            alert("ファイルを選択してください。");
            return;
        }

        this.disabled = true;
        status.style.display = "block";
        resultsArea.innerHTML = "<h3><i class='fa-solid fa-circle-check'></i> 今回の解析結果:</h3>";
        totalContainer.style.display = "block";
        runningTotal = 0;
        totalDisplay.innerText = "0";
        
        const batchId = "BT_" + Date.now();

        for (let i = 0; i < files.length; i++) {
            status.innerHTML = `<i class='fa-solid fa-spinner fa-spin'></i> 処理中 (${i + 1}/${files.length}): ${files[i].name}`;
            
            try {
                // 1. 画像圧縮 (Nginx 413対策)
                const compressed = await compressImg(files[i]);
                
                // 2. 逐次送信 (1枚ずつ)
                const formData = new FormData();
                formData.append('receipts[]', compressed, files[i].name);
                formData.append('batch_id', batchId);

                const response = await fetch('', { method: 'POST', body: formData });
                if (!response.ok) throw new Error("Upload Error");
                
                const resData = await response.json();
                
                // 3. 合計加算
                runningTotal += resData.sum;
                totalDisplay.innerText = runningTotal.toLocaleString();

                // 4. カード表示
                renderResult(resData);
            } catch (err) {
                resultsArea.innerHTML += `<div style="color:var(--danger); font-size:12px; margin-bottom:15px; padding:10px; background:#fff0f6; border-radius:10px;">❌ ${files[i].name}: 解析失敗</div>`;
            }
        }

        status.innerHTML = "<i class='fa-solid fa-check-double'></i> すべての解析が完了しました";
        this.disabled = false;
    };

    function renderResult(data) {
        const resultsArea = document.getElementById('resultsArea');
        let html = `<div class="card"><span class="file-info">File: ${data.file}</span>`;
        data.items.forEach(it => {
            html += `<div class="row"><span>${it.name}</span><span>¥${it.price.toLocaleString()}</span></div>`;
        });
        if (data.total > 0) {
            html += `<div class="row" style="color:var(--danger); font-weight:bold; border-top:1px solid #eee; margin-top:5px;"><span>(OCR読取合計)</span><span>¥${data.total.toLocaleString()}</span></div>`;
        }
        html += `</div>`;
        resultsArea.insertAdjacentHTML('beforeend', html);
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
