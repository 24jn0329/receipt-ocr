<?php
/**
 * 🧾 小票解析系统 - 页面重置版
 * 修改说明：
 * 1. 允许显示并保存带有 "◎" 符号的商品名。
 * 2. 移除数据库删除功能，改为“清空页面显示”。
 * 3. 增强日志功能：按照指定格式 [STORE/TOTAL/ITEM] 写入 ocr.log
 */

// --- 1. 配置与環境設置 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://cv-receipt.cognitiveservices.azure.com/"; 
$apiKey   = "acFa9r1gRfWfvNsBjsLFsyec437ihmUsWXpA1WKVYD4z5yrPBrrMJQQJ99CBACNns7RXJ3w3AAAFACOGcllL"; 
$logFile = 'ocr.log';

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

// --- 3. 动作处理 ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额', '日期']);
        $sql = "SELECT r.file_name, r.processed_at, i.item_name, i.price FROM receipts r JOIN receipt i ON r.id = i.receipt_id";
        $stmt = sqlsrv_query($conn, $sql);
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($output, [$row['file_name'], $row['item_name'], $row['price'], $row['processed_at']->format('Y-m-d H:i:s')]);
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

// --- 4. OCR 核心解析逻辑 ---
$processedIds = []; 
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
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
        $stopFlag = false;
        
        // 日志用の変数
        $logStore = "Unknown";
        $logTotal = 0;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            
            // 店舗名の推測 (最初の数行から)
            if ($i < 5 && preg_match('/FamilyMart|セブン|ローソン|LAWSON/i', $text, $storeMatch)) {
                $logStore = $storeMatch[0];
            }

            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);

            if (preg_match('/合計|合计/u', $pureText) && preg_match('/[¥￥]([\d,]+)/u', $text, $totalMatch)) {
                $logTotal = (float)str_replace(',', '', $totalMatch[1]);
            }

            if (preg_match('/内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $nameInLine);

                if (mb_strlen($cleanNameInLine) < 2 || preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $foundName = "";
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        $cleanPrev = str_replace(['＊', '*', ' ', '√', '軽', '轻'], '', $prev);
                        if (mb_strlen($cleanPrev) >= 2 && !preg_match('/領|収|証|合|计|計|%|店|电话|電話|¥|￥/u', $cleanPrev)) {
                            $foundName = $cleanPrev; break;
                        }
                    }
                    $finalName = $foundName;
                } else {
                    $finalName = $cleanNameInLine;
                }

                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計|内訳/u', $finalName)) {
                    $isDuplicate = false;
                    foreach ($currentItems as $existing) {
                        if ($existing['name'] === $finalName && $existing['price'] === $price) {
                            $isDuplicate = true; break;
                        }
                    }
                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
                    }
                }
            }
        }

        // --- 指定フォーマットでのログ書き出し ---
        $logContent = "\n===== OCR RESULT =====\n";
        $logContent .= "TIME: " . date('Y-m-d\TH:i:s.v') . "\n";
        $logContent .= "STORE: $logStore\n";
        $logContent .= "TOTAL: " . number_format($logTotal, 1, '.', '') . "\n";
        foreach ($currentItems as $it) {
            $logContent .= "{$it['name']}," . number_format($it['price'], 1, '.', '') . "\n";
        }
        file_put_contents($logFile, $logContent, FILE_APPEND);
        // -------------------------------------

        if (!empty($currentItems)) {
            $sqlR = "INSERT INTO receipts (file_name) OUTPUT INSERTED.id VALUES (?)";
            $stmtR = sqlsrv_query($conn, $sqlR, array($fileName));
            if ($stmtR && sqlsrv_fetch($stmtR)) {
                $newId = sqlsrv_get_field($stmtR, 0);
                $processedIds[] = $newId; 
                foreach ($currentItems as $it) {
                    $sqlI = "INSERT INTO receipt (receipt_id, item_name, price) VALUES (?, ?, ?)";
                    sqlsrv_query($conn, $sqlI, array($newId, $it['name'], $it['price']));
                }
            }
        }
    }
}

// --- 5. 表示用データの読み取り ---
$results = [];
$totalAllAmount = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($processedIds)) {
    $idList = implode(',', $processedIds);
    $sqlMain = "SELECT id, file_name FROM receipts WHERE id IN ($idList)";
    $resMain = sqlsrv_query($conn, $sqlMain);
    if ($resMain) {
        while ($row = sqlsrv_fetch_array($resMain, SQLSRV_FETCH_ASSOC)) {
            $items = [];
            $sqlSub = "SELECT item_name as name, price FROM receipt WHERE receipt_id = ? ORDER BY id ASC";
            $resSub = sqlsrv_query($conn, $sqlSub, array($row['id']));
            while ($it = sqlsrv_fetch_array($resSub, SQLSRV_FETCH_ASSOC)) {
                $items[] = $it;
                $totalAllAmount += $it['price'];
            }
            $results[] = ['file' => $row['file_name'], 'items' => $items];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Azure SQL 小票解析</title>
    <style>
        body { font-family: 'PingFang SC', 'Microsoft YaHei', sans-serif; background: #f4f7f9; padding: 20px; color: #333; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); }
        .card { border-left: 4px solid #2ecc71; background: #fafafa; padding: 15px; margin-bottom: 15px; border-radius: 6px; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .grand-total { margin-top: 25px; padding: 20px; background: #fff5f5; border: 1px solid #ffccc7; border-radius: 10px; text-align: center; }
        .amount-big { font-size: 32px; font-weight: bold; color: #ff4d4f; }
        .btn-main { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; }
        .btn-main:disabled { background: #ccc; }
        .nav-bar { margin-top: 25px; display: flex; justify-content: space-around; border-top: 1px solid #eee; padding-top: 15px; flex-wrap: wrap; gap: 10px; }
        .nav-link { font-size: 12px; color: #666; text-decoration: none; padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; background: #fff; }
        .nav-link:hover { background: #f9f9f9; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 小票解析 (仅显示本次)</h2>
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:20px; width: 100%;">
            <button type="submit" id="submitBtn" class="btn-main">开始解析并存入DB</button>
            <div id="status" style="display:none; text-align:center; margin-top:10px; color:#1890ff;">准备中...</div>
        </form>

        <?php if ($results): ?>
            <div style="margin-top:30px;">
                <h3 style="font-size: 16px; color: #1890ff;">✅ 本次解析结果：</h3>
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small style="color:#aaa;">📄 <?= htmlspecialchars($res['file']) ?></small>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <div class="grand-total">
                    <div>本次解析总金額</div>
                    <div class="amount-big">¥<?= number_format($totalAllAmount) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="nav-bar">
            <a href="?action=csv" class="nav-link">📥 导出 CSV</a>
            <a href="?action=download_log" class="nav-link">📝 下载日志</a>
            <a href="?action=clear_view" class="nav-link" style="color:#1890ff;">🔄 清空页面</a>
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
            status.innerText = `正在压缩处理 (${i+1}/${files.length})...`;
            const compressed = await compressImg(files[i]);
            formData.append('receipts[]', compressed, files[i].name);
        }

        status.innerText = "正在发送至 Azure 识别...";
        fetch('', { method: 'POST', body: formData })
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            document.body.innerHTML = doc.body.innerHTML;
        })
        .catch(err => {
            alert("上传失败，请检查日志。");
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
