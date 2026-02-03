<?php
/**
 * 🧾 レシート解析システム - 完全統合版 (重複読み取り防止強化)
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
            fputcsv($output, [
                $row['id'],
                $row['upload_batch_id'],
                $row['file_name'],
                $row['item_name'],
                $row['price'],
                $row['is_total'],
                $row['created_at']->format('Y-m-d H:i:s')
            ]);
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

// --- 4. OCR 解析 & DB保存ロジック ---
$results = [];
$totalAllAmount = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $batchId = uniqid('BT_'); 

    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        if ($key > 0) sleep(1); 

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
        $logStore = "不明な店舗";
        $logTotal = 0;
        $stopFlag = false;
        $usedBufferIndex = -1; // 使用済みの商品名インデックスを追跡

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            if ($i < 5 && preg_match('/FamilyMart|セブン|ローソン|LAWSON/i', $text, $storeMatch)) {
                $logStore = $storeMatch[0];
            }

            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);

            // 合計金額の判定
            if (preg_match('/合計|合計額/u', $pureText) && preg_match('/[¥￥]([\d,]+)/u', $text, $totalMatch)) {
                $logTotal = (int)str_replace(',', '', $totalMatch[1]);
                $stopFlag = true; // 合計が見つかったら以降の商品解析を停止
            }

            // 解析停止ワード
            if (preg_match('/内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                $stopFlag = true;
                continue;
            }
            if ($stopFlag) continue;

            // 商品と金額の抽出 (重複防止強化版)
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $nameInLine);

                $finalName = "";

                // 1. 同じ行に名前があるかチェック
                if (mb_strlen($cleanNameInLine) >= 2 && !preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $finalName = $cleanNameInLine;
                } 
                // 2. 行に名前がない場合、前の行から探索
                else {
                    for ($j = $i - 1; $j > $usedBufferIndex; $j--) {
                        $prev = trim($lines[$j]['text']);
                        $cleanPrev = str_replace(['＊', '*', ' ', '√', '軽', '轻', '◎'], '', $prev);
                        if (mb_strlen($cleanPrev) >= 2 && !preg_match('/領|収|証|合|計|%|店|電話|¥|￥|No/u', $cleanPrev)) {
                            $finalName = str_replace(['＊', '*', ' ', '√', '軽', '轻'], '', $prev); // ◎を含めて取得
                            $usedBufferIndex = $j; // この行は使用済みとする
                            break;
                        }
                    }
                }

                // 最終チェックと追加
                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計|内訳/u', $finalName)) {
                    // 同一ファイル内での完全一致重複を避ける（OCRの二重読み対策）
                    $isDuplicate = false;
                    foreach ($currentItems as $item) {
                        if ($item['name'] === $finalName && $item['price'] === $price) {
                            $isDuplicate = true; break;
                        }
                    }
                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
                    }
                }
            }
        }

        // --- DB保存実行 ---
        foreach ($currentItems as $it) {
            $sql = "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 0)";
            sqlsrv_query($conn, $sql, [$batchId, $fileName, $it['name'], $it['price']]);
            $totalAllAmount += $it['price'];
        }
        if ($logTotal > 0) {
            $sql = "INSERT INTO Receipts (upload_batch_id, file_name, item_name, price, is_total) VALUES (?, ?, ?, ?, 1)";
            sqlsrv_query($conn, $sql, [$batchId, $fileName, '合計(OCR読み取り)', $logTotal]);
        }

        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $logTotal];

        // ログ書き出し
        $logContent = sprintf("\n[%s] STORE:%s, FILE:%s, TOTAL:%d\n", date('Y-m-d H:i:s'), $logStore, $fileName, $logTotal);
        foreach ($currentItems as $it) { $logContent .= "- {$it['name']}: {$it['price']}\n"; }
        file_put_contents($logFile, $logContent, FILE_APPEND);
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>レシート解析システム (Azure SQL 統合版)</title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background: #f4f7f9; padding: 20px; color: #333; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); }
        .card { border-left: 4px solid #2ecc71; background: #fafafa; padding: 15px; margin-bottom: 15px; border-radius: 6px; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .grand-total { margin-top: 25px; padding: 20px; background: #fff5f5; border: 1px solid #ffccc7; border-radius: 10px; text-align: center; }
        .amount-big { font-size: 32px; font-weight: bold; color: #ff4d4f; }
        .btn-main { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; }
        .nav-bar { margin-top: 25px; display: flex; justify-content: space-around; border-top: 1px solid #eee; padding-top: 15px; flex-wrap: wrap; gap: 10px; }
        .nav-link { font-size: 12px; color: #666; text-decoration: none; padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; background: #fff; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 レシート解析システム</h2>
        
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:20px; width: 100%;">
            <button type="submit" id="submitBtn" class="btn-main">解析を開始してDBに保存</button>
            <div id="status" style="display:none; text-align:center; margin-top:10px; color:#1890ff;">準備中...</div>
        </form>

        <?php if (!empty($results)): ?>
            <div style="margin-top:30px;">
                <h3 style="font-size: 16px; color: #1890ff;">✅ 今回の解析結果:</h3>
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small style="color:#aaa;">📄 ファイル: <?= htmlspecialchars($res['file']) ?></small>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if($res['total'] > 0): ?>
                            <div class="row" style="color:#ff4d4f; font-weight:bold; border-top:1px solid #ddd;">
                                <span>(OCR読取合計)</span>
                                <span>¥<?= number_format($res['total']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <div class="grand-total">
                    <div>今回の商品合計</div>
                    <div class="amount-big">¥<?= number_format($totalAllAmount) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="nav-bar">
            <a href="?action=csv" class="nav-link">📥 CSVを保存</a>
            <a href="?action=download_log" class="nav-link">📝 ログを保存</a>
            <a href="?action=clear_view" class="nav-link" style="color:#1890ff;">🔄 表示クリア</a>
        </div>
    </div>

    <script>
    document.getElementById('uploadForm').onsubmit = async function(e) {
        e.preventDefault();
        const btn = document.getElementById('submitBtn');
        const status = document.getElementById('status');
        const files = document.getElementById('fileInput').files;
        if (!files.length) return;

        btn.disabled = true;
        status.style.display = "block";

        const formData = new FormData();
        for (let i = 0; i < files.length; i++) {
            status.innerText = `画像を圧縮中 (${i+1}/${files.length})...`;
            const compressed = await compressImg(files[i]);
            formData.append('receipts[]', compressed, files[i].name);
        }

        status.innerText = "Azure OCR で解析中...";
        fetch('', { method: 'POST', body: formData })
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            document.body.innerHTML = doc.body.innerHTML;
        })
        .catch(err => {
            alert("エラーが発生しました。");
            btn.disabled = false;
        });
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
                    let w = img.width, h = img.height;
                    if (w > 1200) { h = h * (1200/w); w = 1200; }
                    canvas.width = w; canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    canvas.toBlob(blob => resolve(new File([blob], file.name, {type:'image/jpeg'})), 'image/jpeg', 0.85);
                };
            };
        });
    }
    </script>
</body>
</html>
