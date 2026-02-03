<?php
/**
 * 🧾 Smart Receipt AI - 高精度・安定版
 * 修正点：読み取り順序の固定化、ノイズ除去の強化
 */

// --- 1. 設定と環境構成 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://cv-receipt.cognitiveservices.azure.com/";
$apiKey   = "acFa9r1gRfWfvNsBjsLFsyec437ihmUsWXpA1WKVYD4z5yrPBrrMJQQJ99CBACNns7RXJ3w3AAAFACOGcllL";

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
    die(json_encode(['error' => 'Database connection failed']));
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

        // 修正ポイント：readingOrder=basic を追加して読み取り順序を安定させる
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read&readingOrder=basic";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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
        $usedBufferIndex = -1;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            // ノイズ除去の正規表現を強化
            $pureText = preg_replace('/[\s　＊\*√軽轻\(\)（）]|(8|10)%/u', '', $text);

            // 合計金額の判定
            if (preg_match('/合計/u', $pureText) && preg_match('/[¥￥\d,]{3,}/u', $text)) {
                if (preg_match('/([0-9,]{3,})/', str_replace(['¥','￥'], '', $text), $totalMatch)) {
                    $logTotal = (int)str_replace(',', '', $totalMatch[1]);
                    continue; // 合計行自体は商品として登録しない
                }
            }

            // 消費税等の除外ワード
            if (preg_match('/内消費税|対象|支払|残高|再発行|お釣|預り/u', $pureText)) continue;

            // 商品と金額の抽出 (金額パターン: ¥123 または 123)
            if (preg_match('/[¥￥]?([0-9,]{2,})$/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 品名：同じ行の金額より前の部分
                $nameInLine = trim(preg_replace('/[¥￥\d,]+$/u', '', $text));
                $cleanName = preg_replace('/[\.．…＊\*]|^\d+\s/u', '', $nameInLine);

                $finalName = "";
                if (mb_strlen($cleanName) >= 2) {
                    $finalName = $cleanName;
                } elseif ($i > 0 && $i - 1 > $usedBufferIndex) {
                    // 1つ上の行が品名である可能性を探索
                    $prevLine = trim($lines[$i-1]['text']);
                    if (!preg_match('/[¥￥\d]/u', $prevLine) && mb_strlen($prevLine) >= 2) {
                        $finalName = $prevLine;
                        $usedBufferIndex = $i - 1;
                    }
                }

                // 除外リストに該当しない場合のみ追加
                if (!empty($finalName) && !preg_match('/[店番号]|TEL|登録|領収|合計/u', $finalName)) {
                    $currentItems[] = ['name' => $finalName, 'price' => $price];
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
    <title>Smart Receipt AI</title>
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
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f8f9fa; }
        .grand-total-box { margin-top: 35px; padding: 30px; background: var(--text-main); color: white; border-radius: 22px; text-align: center; }
        .amount-big { font-size: 40px; font-weight: 800; color: var(--success); }
        .nav-bar { margin-top: 45px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .nav-link { font-size: 12px; color: var(--text-muted); text-decoration: none; padding: 12px; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center; }
    </style>
</head>
<body>
    <div class="box">
        <h2><i class="fa-solid fa-receipt"></i> Smart Receipt AI</h2>
        <div class="upload-section">
            <i class="fa-solid fa-cloud-arrow-up" style="font-size: 48px; color: var(--primary); margin-bottom: 15px; display: block;"></i>
            <input type="file" id="fileInput" multiple accept="image/*">
            <p style="font-size: 11px; color: var(--text-muted);">安定性が向上したOCRエンジンで解析します</p>
        </div>
        <button id="submitBtn" class="btn-main"><i class="fa-solid fa-wand-magic-sparkles"></i> 解析を開始</button>
        <div id="status" style="display:none;"></div>
        <div id="resultsArea"></div>
        <div id="grandTotalContainer" class="grand-total-box" style="display:none;">
            <div>今回の解析商品 合計</div>
            <div class="amount-big">¥<span id="allFileSum">0</span></div>
        </div>
        <div class="nav-bar">
            <a href="?action=csv" class="nav-link"><i class="fa-solid fa-file-csv"></i> CSV保存</a>
            <a href="?action=clear_view" class="nav-link" style="color:var(--danger);"><i class="fa-solid fa-eraser"></i> クリア</a>
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
        resultsArea.innerHTML = "";
        runningTotal = 0;

        for (let i = 0; i < files.length; i++) {
            status.innerHTML = `解析中 (${i + 1}/${files.length}): ${files[i].name}`;
            try {
                const compressed = await compressImg(files[i]);
                const formData = new FormData();
                formData.append('receipts[]', compressed, files[i].name);
                formData.append('batch_id', "BT_" + Date.now());

                const response = await fetch('', { method: 'POST', body: formData });
                const resData = await response.json();
                runningTotal += resData.sum;
                totalDisplay.innerText = runningTotal.toLocaleString();
                document.getElementById('grandTotalContainer').style.display = "block";
                
                let html = `<div class="card"><strong>${resData.file}</strong>`;
                resData.items.forEach(it => {
                    html += `<div class="row"><span>${it.name}</span><span>¥${it.price.toLocaleString()}</span></div>`;
                });
                html += `</div>`;
                resultsArea.insertAdjacentHTML('beforeend', html);
            } catch (err) {
                console.error(err);
            }
        }
        status.innerHTML = "完了しました";
        this.disabled = false;
    };

    function compressImg(file) {
        return new Promise(resolve => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = (e) => {
                const img = new Image();
                img.src = e.target.result;
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    const maxSide = 1500; // 精度維持のため少し大きめに設定
                    let w = img.width, h = img.height;
                    if (w > maxSide || h > maxSide) {
                        if (w > h) { h = h * (maxSide/w); w = maxSide; }
                        else { w = w * (maxSide/h); h = maxSide; }
                    }
                    canvas.width = w; canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    canvas.toBlob(blob => resolve(new File([blob], file.name, {type:'image/jpeg'})), 'image/jpeg', 0.9);
                };
            };
        });
    }
    </script>
</body>
</html>
