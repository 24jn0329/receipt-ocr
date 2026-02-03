<?php
/**
 * 🧾 Smart Receipt AI - 修正版 (合計金額の取得を強化)
 */

@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://cv-receipt.cognitiveservices.azure.com/"; 
$apiKey   = "acFa9r1gRfWfvNsBjsLFsyec437ihmUsWXpA1WKVYD4z5yrPBrrMJQQJ99CBACNns7RXJ3w3AAAFACOGcllL"; 

// --- Azure SQL 接続 ---
$serverName = "tcp:receipt-server.database.windows.net,1433"; 
$connectionOptions = array("Database" => "db_receipt", "Uid" => "jn240329", "PWD" => "15828415312dY", "CharacterSet" => "UTF-8");
$conn = sqlsrv_connect($serverName, $connectionOptions);

// --- アクション処理 ---
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'clear_view') { header("Location: " . strtok($_SERVER["PHP_SELF"], '?')); exit; }
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'バッチID', 'ファイル名', '項目名', '金額', '合計フラグ', '日時']);
        $sql = "SELECT id, upload_batch_id, file_name, item_name, price, is_total, created_at FROM Receipts ORDER BY id DESC";
        $stmt = sqlsrv_query($conn, $sql);
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($output, [$row['id'],$row['upload_batch_id'],$row['file_name'],$row['item_name'],$row['price'],$row['is_total'],$row['created_at']->format('Y-m-d H:i:s')]);
        }
        fclose($output); exit;
    }
}

// --- AJAX POST 処理 ---
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
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $logTotal = 0;
        
        // 🌟 金額正規表現の強化 (¥, ￥, Y, T, 7, V, & に対応)
        $pricePattern = '/[¥￥YTV7&]?([\d,]{2,})/';

        for ($i = 0; $i < count($lines); $i++) {
            $text = mb_convert_kana($lines[$i]['text'], "askv", "UTF-8");
            $text = str_replace([' ', '　'], '', $text);

            // 1. 合計金額の検出 (最優先)
            if (preg_match('/合計|合計額|小計/u', $text) && preg_match($pricePattern, $text, $totalMatch)) {
                $val = (int)str_replace(',', '', $totalMatch[1]);
                if ($val > $logTotal) $logTotal = $val; // 一番大きい数値を合計として扱う
                continue; 
            }

            // 2. 除外キーワード (消費税などは商品として登録しない)
            if (preg_match('/消費税|対象|支払|残高|再発行|お釣|ポイント/u', $text)) continue;

            // 3. 通常商品の検出
            if (preg_match($pricePattern, $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥YTV7&]?[\d,]+$/u', '', $text));
                
                $finalName = "";
                if (mb_strlen($nameInLine) >= 2 && !preg_match('/^[\d,]+$/', $nameInLine)) {
                    $finalName = $nameInLine;
                } else if ($i > 0) {
                    $prev = mb_convert_kana($lines[$i-1]['text'], "askv", "UTF-8");
                    if (mb_strlen($prev) >= 2 && !preg_match('/領|収|店|電話|No/u', $prev)) {
                        $finalName = $prev;
                    }
                }

                if (!empty($finalName) && !preg_match('/Family|新宿|登録|合計|対象/u', $finalName)) {
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
            sqlsrv_query($conn, $sql, [$batchId, $fileName, '合計(OCR読取)', $logTotal]);
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
    <title>Smart Receipt AI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #4361ee; --bg: #f8f9fd; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); padding: 20px; }
        .box { max-width: 600px; margin: auto; background: #fff; padding: 30px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .upload-section { background: #f1f4ff; border: 2px dashed #adc1ff; border-radius: 12px; padding: 30px; text-align: center; margin-bottom: 20px; }
        .btn-main { width: 100%; padding: 16px; background: var(--primary); color: white; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; }
        .card { background: #fff; border: 1px solid #edf2f7; padding: 15px; margin-bottom: 10px; border-radius: 12px; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f8f9fa; font-size: 14px; }
        .total-row { color: #f72585; font-weight: bold; border-top: 2px solid #eee; margin-top: 5px; }
        .grand-total-box { margin-top: 20px; padding: 20px; background: #2b2d42; color: #4cc9f0; border-radius: 15px; text-align: center; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;"><i class="fa-solid fa-receipt"></i> Smart Receipt AI</h2>
        <div class="upload-section">
            <input type="file" id="fileInput" multiple accept="image/*">
        </div>
        <button id="submitBtn" class="btn-main">スキャン開始</button>
        <div id="status" style="display:none; text-align:center; margin-top:15px; color:var(--primary);"></div>
        <div id="resultsArea"></div>
        <div id="grandTotalContainer" class="grand-total-box" style="display:none;">
            <div style="color:white; font-size:12px;">読み取り合計 (OCR)</div>
            <div style="font-size:32px; font-weight:800;">¥<span id="allFileSum">0</span></div>
        </div>
        <div style="margin-top:20px; display:flex; gap:10px;">
            <a href="?action=csv" style="flex:1; text-align:center; padding:10px; background:#fff; border:1px solid #ddd; border-radius:10px; text-decoration:none; color:#666;">CSV保存</a>
            <a href="?action=clear_view" style="flex:1; text-align:center; padding:10px; background:#fff; border:1px solid #ddd; border-radius:10px; text-decoration:none; color:#f72585;">クリア</a>
        </div>
    </div>

    <script>
    let runningTotal = 0;
    document.getElementById('submitBtn').onclick = async function() {
        const files = document.getElementById('fileInput').files;
        if (!files.length) return;
        this.disabled = true;
        const status = document.getElementById('status');
        const resultsArea = document.getElementById('resultsArea');
        status.style.display = "block";
        resultsArea.innerHTML = "";
        runningTotal = 0;
        
        for (let i = 0; i < files.length; i++) {
            status.innerText = `解析中 (${i+1}/${files.length})`;
            const compressed = await compressImg(files[i]);
            const formData = new FormData();
            formData.append('receipts[]', compressed, files[i].name);
            
            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const resData = await response.json();
                renderResult(resData);
                // 🌟 ここで合計値を加算
                if(resData.total > 0) {
                    runningTotal += resData.total;
                } else {
                    runningTotal += resData.sum;
                }
                document.getElementById('allFileSum').innerText = runningTotal.toLocaleString();
                document.getElementById('grandTotalContainer').style.display = "block";
            } catch (e) { console.error(e); }
        }
        status.innerText = "完了";
        this.disabled = false;
    };

    function renderResult(data) {
        let html = `<div class="card"><div style="font-size:10px; color:#aaa;">${data.file}</div>`;
        data.items.forEach(it => {
            html += `<div class="row"><span>${it.name}</span><span>¥${it.price.toLocaleString()}</span></div>`;
        });
        if (data.total > 0) {
            html += `<div class="row total-row"><span>合計(OCR)</span><span>¥${data.total.toLocaleString()}</span></div>`;
        }
        html += `</div>`;
        document.getElementById('resultsArea').insertAdjacentHTML('beforeend', html);
    }

    async function compressImg(file) {
        return new Promise(resolve => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = (e) => {
                const img = new Image();
                img.src = e.target.result;
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    const targetWidth = 1200;
                    const scale = targetWidth / img.width;
                    canvas.width = targetWidth;
                    canvas.height = img.height * scale;
                    const ctx = canvas.getContext('2d');
                    ctx.filter = 'contrast(1.1)';
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob(blob => resolve(new File([blob], file.name, {type:'image/jpeg'})), 'image/jpeg', 0.85);
                };
            };
        });
    }
    </script>
</body>
</html>
