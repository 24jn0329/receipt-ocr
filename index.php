<?php
/**
 * 🧾 小票解析系统 - 最终修复稳定版
 * 修改项：
 * 1. 修复 AJAX 请求导致的 404 路径问题。
 * 2. 优化多图上传时的 Azure API 频率限制处理。
 * 3. 保持 "◎" 等特殊符号的兼容性。
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
    die("数据库连接失败，请检查配置。");
}

// --- 3. 动作处理 ---
$action = $_GET['action'] ?? '';

// CSV 导出
if ($action == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
    echo "\xEF\xBB\xBF"; 
    $output = fopen('php://output', 'w');
    fputcsv($output, ['文件名', '项目', '金额', '日期']);
    $sql = "SELECT r.file_name, r.processed_at, i.item_name, i.price FROM receipts r JOIN receipt_items i ON r.id = i.receipt_id";
    $stmt = sqlsrv_query($conn, $sql);
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        fputcsv($output, [$row['file_name'], $row['item_name'], $row['price'], $row['processed_at']->format('Y-m-d H:i:s')]);
    }
    fclose($output); exit;
}

// 日志下载
if ($action == 'download_log') {
    if (file_exists($logFile)) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="ocr.log"');
        readfile($logFile); exit;
    }
}

// 清空显示 (不删数据库)
if ($action == 'clear_view') {
    header("Location: " . strtok($_SERVER["PHP_SELF"], '?')); 
    exit;
}

// --- 4. OCR 核心解析逻辑 (处理 POST 上传) ---
$processedIds = []; 
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
        // 针对 Azure Free Tier 的频率限制：后续请求增加延迟
        if ($key > 0) usleep(1500000); 

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
            file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] ERROR: $fileName HTTP $httpCode\n", FILE_APPEND);
            continue;
        }

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $stopFlag = false;
        $logStore = "Unknown";
        $logTotal = 0;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            
            // 商店名识别
            if ($i < 5 && preg_match('/FamilyMart|セブン|ローソン|LAWSON/i', $text, $storeMatch)) {
                $logStore = $storeMatch[0];
            }

            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);
            
            // 总额识别
            if (preg_match('/合計|合计/u', $pureText) && preg_match('/[¥￥]([\d,]+)/u', $text, $totalMatch)) {
                $logTotal = (float)str_replace(',', '', $totalMatch[1]);
            }

            // 停止词处理
            if (preg_match('/内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 商品名与价格提取
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

                if (!empty($finalName) && !preg_match('/Family|新宿|電話|注册|領収|対象|消費税|合計/u', $finalName)) {
                    $currentItems[] = ['name' => $finalName, 'price' => $price];
                }
            }
        }

        // 写入 OCR 日志
        $logEntry = "\n===== OCR RESULT [" . date('H:i:s') . "] =====\nSTORE: $logStore\nTOTAL: $logTotal\n";
        foreach ($currentItems as $it) { $logEntry .= "{$it['name']},{$it['price']}\n"; }
        file_put_contents($logFile, $logEntry, FILE_APPEND);

        // 写入数据库
        if (!empty($currentItems)) {
            $sqlR = "INSERT INTO receipts (file_name) OUTPUT INSERTED.id VALUES (?)";
            $stmtR = sqlsrv_query($conn, $sqlR, array($fileName));
            if ($stmtR && sqlsrv_fetch($stmtR)) {
                $newId = sqlsrv_get_field($stmtR, 0);
                $processedIds[] = $newId; 
                foreach ($currentItems as $it) {
                    $sqlI = "INSERT INTO receipt_items (receipt_id, item_name, price) VALUES (?, ?, ?)";
                    sqlsrv_query($conn, $sqlI, array($newId, $it['name'], $it['price']));
                }
            }
        }
    }
}

// --- 5. 构建结果 HTML (用于 AJAX 返回) ---
$resultsHtml = "";
$totalAllAmount = 0;
if (!empty($processedIds)) {
    $idList = implode(',', $processedIds);
    $sqlMain = "SELECT id, file_name FROM receipts WHERE id IN ($idList)";
    $resMain = sqlsrv_query($conn, $sqlMain);
    
    $resultsHtml .= '<h3 style="font-size: 16px; color: #1890ff;">✅ 本次解析结果：</h3>';
    while ($row = sqlsrv_fetch_array($resMain, SQLSRV_FETCH_ASSOC)) {
        $resultsHtml .= '<div class="card"><small style="color:#aaa;">📄 '.htmlspecialchars($row['file_name']).'</small>';
        $sqlSub = "SELECT item_name as name, price FROM receipt_items WHERE receipt_id = ? ORDER BY id ASC";
        $resSub = sqlsrv_query($conn, $sqlSub, array($row['id']));
        while ($it = sqlsrv_fetch_array($resSub, SQLSRV_FETCH_ASSOC)) {
            $resultsHtml .= '<div class="row"><span>'.htmlspecialchars($it['name']).'</span><span>¥'.number_format($it['price']).'</span></div>';
            $totalAllAmount += $it['price'];
        }
        $resultsHtml .= '</div>';
    }
    $resultsHtml .= '<div class="grand-total"><div>本次解析总金額</div><div class="amount-big">¥'.number_format($totalAllAmount).'</div></div>';
}

// 如果是 AJAX 请求，直接返回结果并结束执行
if (isset($_GET['ajax'])) {
    echo $resultsHtml;
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📜 小票解析系统</title>
    <style>
        body { font-family: 'PingFang SC', sans-serif; background: #f0f2f5; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .btn-main { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; transition: 0.3s; }
        .btn-main:disabled { background: #bfbfbf; }
        .card { border-left: 5px solid #52c41a; background: #f9f9f9; padding: 15px; margin-top: 15px; border-radius: 8px; }
        .row { display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 8px 0; font-size: 14px; }
        .grand-total { margin-top: 20px; padding: 15px; background: #fff1f0; border: 1px solid #ffa39e; border-radius: 8px; text-align: center; }
        .amount-big { font-size: 28px; font-weight: bold; color: #cf1322; }
        .nav-bar { margin-top: 30px; display: flex; gap: 10px; justify-content: center; border-top: 1px solid #eee; padding-top: 20px; }
        .nav-link { text-decoration: none; font-size: 13px; color: #595959; padding: 5px 12px; border: 1px solid #d9d9d9; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center; margin-bottom: 25px;">📜 小票解析系统</h2>
        
        <form id="uploadForm">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:20px; width: 100%;">
            <button type="submit" id="submitBtn" class="btn-main">开始上传并解析</button>
            <div id="status" style="display:none; text-align:center; margin-top:15px; color:#1890ff;"></div>
        </form>

        <div id="resultContainer">
            </div>

        <div class="nav-bar">
            <a href="?action=csv" class="nav-link">📥 导出CSV</a>
            <a href="?action=download_log" class="nav-link">📝 下载日志</a>
            <a href="?action=clear_view" class="nav-link">🔄 清空显示</a>
        </div>
    </div>

    <script>
    document.getElementById('uploadForm').onsubmit = async function(e) {
        e.preventDefault();
        const btn = document.getElementById('submitBtn');
        const status = document.getElementById('status');
        const container = document.getElementById('resultContainer');
        const files = document.getElementById('fileInput').files;

        if (!files.length) return;

        btn.disabled = true;
        status.style.display = "block";
        container.innerHTML = "";

        const formData = new FormData();
        for (let i = 0; i < files.length; i++) {
            status.innerText = `处理图片中 (${i+1}/${files.length})...`;
            const compressed = await compressImg(files[i]);
            formData.append('receipts[]', compressed, files[i].name);
        }

        status.innerText = "正在联机解析，请稍候...";

        try {
            // 使用当前 PHP 文件的绝对路径拼接 ajax 参数，防止 404
            const scriptName = window.location.pathname.split('/').pop() || 'index.php';
            const response = await fetch(scriptName + '?ajax=1', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) throw new Error('服务器响应异常: ' + response.status);

            const html = await response.text();
            container.innerHTML = html;
            status.innerText = "解析成功！";
        } catch (err) {
            status.innerText = "发生错误：" + err.message;
            console.error(err);
        } finally {
            btn.disabled = false;
        }
    };

    // 图片前端压缩逻辑
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
