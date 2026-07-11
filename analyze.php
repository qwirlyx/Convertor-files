<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
@ini_set('memory_limit', '1024M');
@ini_set('max_execution_time', '0');
@set_time_limit(0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/convert.php';

try {
    $action = (string)($_POST['action'] ?? 'upload');

    switch ($action) {
        case 'upload':
            handleUpload();
            break;
        case 'preview':
            handlePreview();
            break;
        case 'delete_preview':
            deletePreviewToken((string)($_POST['preview_token'] ?? ''));
            respond(['ok' => true]);
            break;
        case 'cleanup_uploads':
            cleanupUploadTokens($_POST['upload_tokens'] ?? []);
            respond(['ok' => true]);
            break;
        default:
            throw new Exception('Неизвестное действие');
    }
} catch (Throwable $e) {
    http_response_code(500);
    respond(['ok' => false, 'error' => $e->getMessage()]);
}

function handleUpload(): void
{
    $uploadDir = __DIR__ . '/temp_uploads';
    ensureDir($uploadDir);
    cleanupOldFiles($uploadDir, 3600);
    cleanupTempPreviewHourly(900);

    $rawFiles = $_FILES['files'] ?? $_FILES['file'] ?? null;
    if (!$rawFiles) {
        throw new Exception('Файл не передан');
    }

    $items = normalizeIncomingFiles($rawFiles);
    if (empty($items)) {
        throw new Exception('Файл не передан');
    }

    $result = [];

    foreach ($items as $item) {
        if (($item['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $originalName = (string)($item['name'] ?? 'file');
        $tmpPath = (string)($item['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            continue;
        }

        $token = bin2hex(random_bytes(16));
        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $storedPath = __DIR__ . '/temp_uploads/' . $token . ($ext !== '' ? '.' . $ext : '.bin');

        if (!@move_uploaded_file($tmpPath, $storedPath)) {
            if (!@copy($tmpPath, $storedPath)) {
                throw new Exception('Не удалось сохранить файл на сервере');
            }
        }

        $type = detectFileType($originalName);
        $meta = [];
        if ($type === 'video' || $type === 'audio') {
            $meta = ffprobeMedia($storedPath);
        } elseif ($type === 'image') {
            $img = @getimagesize($storedPath);
            if ($img) {
                $meta = [
                    'width' => (int)($img[0] ?? 0),
                    'height' => (int)($img[1] ?? 0),
                ];
            }
        }

        $size = is_file($storedPath) ? (int)filesize($storedPath) : 0;

        writeUploadMeta($token, [
            'token' => $token,
            'name' => $originalName,
            'type' => $type,
            'ext' => $ext,
            'size' => $size,
            'path' => $storedPath,
            'created_at' => time(),
        ]);

        $result[] = [
            'token' => $token,
            'name' => $originalName,
            'type' => $type,
            'ext' => $ext,
            'size' => $size,
            'meta' => $meta,
        ];
    }

    if (empty($result)) {
        throw new Exception('Не удалось загрузить файлы на сервер');
    }

    respond(['ok' => true, 'files' => $result]);
}

function handlePreview(): void
{
    $previewDir = __DIR__ . '/temp_preview';
    $workDir = __DIR__ . '/work_tmp';

    ensureDir($previewDir);
    ensureDir($workDir);
    cleanupTempPreviewHourly(1800);

    $uploadToken = sanitizeHexToken((string)($_POST['upload_token'] ?? ''));
    if ($uploadToken === '') {
        throw new Exception('Не найден upload token');
    }

    $deleteToken = sanitizeHexToken((string)($_POST['delete_preview_token'] ?? ''));
    if ($deleteToken !== '') {
        deletePreviewToken($deleteToken);
    }

    $meta = readUploadMeta($uploadToken);
    $sourcePath = $meta['path'] ?? findStoredPathByToken(__DIR__ . '/temp_uploads', $uploadToken);
    $originalName = (string)($meta['name'] ?? 'file');
    $detectedType = (string)($meta['type'] ?? detectFileType($originalName));
    $sourceExt = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

    if (!$sourcePath || !is_file($sourcePath)) {
        throw new Exception('Исходный файл на сервере не найден');
    }

    $targetFormat = strtolower(trim((string)($_POST['convert_format'] ?? 'pdf')));
    $targetFormat = chooseTargetFormatForType($detectedType, $targetFormat);
    $quality = max(10, min(100, (int)($_POST['quality'] ?? 100)));
    $resizeW = max(0, (int)($_POST['resize_w'] ?? 0));
    $resizeH = max(0, (int)($_POST['resize_h'] ?? 0));
    $stripMeta = filter_var($_POST['strip_meta'] ?? '0', FILTER_VALIDATE_BOOL);
    $noCompress = filter_var($_POST['no_compress'] ?? '0', FILTER_VALIDATE_BOOL);
    
    if ($noCompress) {
        $quality = 100;
    }
    
    $metaPotentialBytes = estimateMetadataSavingsBytes($sourcePath, $detectedType);
    $metaSavedBytes = $stripMeta ? $metaPotentialBytes : 0;

    if ($detectedType === 'video' && $targetFormat === 'webm') {
        $ffmpeg = resolveFfmpegBinary();
        if (!$ffmpeg || !ffmpegSupportsEncoder($ffmpeg, 'libvpx-vp9') || !ffmpegSupportsEncoder($ffmpeg, 'libopus')) {
            throw new Exception('На сервере нет поддержки WEBM (нужны libvpx-vp9 и libopus в ffmpeg).');
        }
    }

    $sourceNormalizedExt = normalizeExtension($sourceExt);
    $targetNormalizedExt = normalizeExtension($targetFormat);
    $isNoOpConversion = (
        $quality === 100
        && $resizeW === 0
        && $resizeH === 0
        && !$stripMeta
        && $sourceNormalizedExt === $targetNormalizedExt
    );

    if ($isNoOpConversion) {
        respond([
            'ok' => true,
            'preview' => [
                'token' => '',
                'name' => $originalName,
                'size' => (int)filesize($sourcePath),
                'format' => $targetFormat,
                'type' => $detectedType,
                'estimated' => false,
                'meta_saved_bytes' => 0,
                'meta_potential_bytes' => $metaPotentialBytes,
                'strip_meta' => false,
            ],
        ]);
    }

$outputExt = normalizeExtension($targetFormat);
    $token = bin2hex(random_bytes(16));
    $outputName = buildOutputName($originalName, $targetFormat);
    $outputPath = $previewDir . '/' . $token . '.' . $outputExt;

    switch ($detectedType) {
        case 'image':
            $ok = processImage($sourcePath, $outputPath, $targetFormat, $quality, $resizeW, $resizeH, $stripMeta, $sourceExt);
            if (!$ok || !is_file($outputPath) || filesize($outputPath) <= 0) {
                throw new Exception('Не удалось подготовить точный предпросчёт');
            }
            respond([
                'ok' => true,
                'preview' => [
                    'token' => $token,
                    'name' => $outputName,
                    'size' => (int)filesize($outputPath),
                    'format' => $targetFormat,
                    'type' => $detectedType,
                    'estimated' => false,
                    'meta_saved_bytes' => $metaSavedBytes,
                    'meta_potential_bytes' => $metaPotentialBytes,
                    'strip_meta' => $stripMeta,
                ],
            ]);
            break;

        case 'video':
            $sourceSize = (int)filesize($sourcePath);
            $mediaInfo = getMediaInfo($sourcePath);
            $duration = isset($mediaInfo['duration']) ? (float)$mediaInfo['duration'] : 0.0;
        
            // Для GIF всегда делаем точный серверный предпросчёт,
            // потому что формульная оценка врёт слишком сильно.
            $mustBuildExactPreview = ($targetFormat === 'gif' || $noCompress);
        
            // Для коротких обычных видео тоже можно делать точный предпросчёт,
            // чтобы размер не "врал" пользователю.
            if (!$mustBuildExactPreview && $duration > 0 && $duration <= 20 && $sourceSize <= 25 * 1024 * 1024) {
                $mustBuildExactPreview = true;
            }
        
            if ($mustBuildExactPreview) {
                $ok = processVideo(
                    $sourcePath,
                    $outputPath,
                    $targetFormat,
                    $quality,
                    $resizeW,
                    $resizeH,
                    $stripMeta,
                    $noCompress
                );
        
                if ($ok && is_file($outputPath) && filesize($outputPath) > 0) {
                    respond([
                        'ok' => true,
                        'preview' => [
                            'token' => $token,
                            'name' => $outputName,
                            'size' => (int)filesize($outputPath),
                            'format' => $targetFormat,
                            'type' => $detectedType,
                            'estimated' => false,
                            'meta_saved_bytes' => $metaSavedBytes,
                            'meta_potential_bytes' => $metaPotentialBytes,
                            'strip_meta' => $stripMeta,
                        ],
                    ]);
                }
            }
        
            // Если точный предпросчёт не нужен или не удался — fallback на расчёт
            $estimatedSize = estimateVideoOutputBytes($sourcePath, $targetFormat, $quality, $resizeW, $resizeH);
            if ($estimatedSize <= 0 && is_file($sourcePath)) {
                $estimatedSize = (int)round((int)filesize($sourcePath) * 0.45);
            }
            if ($estimatedSize <= 0) {
                throw new Exception('Не удалось оценить видео (проверьте ffprobe и файл)');
            }
        
            if ($stripMeta && $metaSavedBytes > 0) {
                $estimatedSize = max(1, $estimatedSize - $metaSavedBytes);
            }
        
            respond([
                'ok' => true,
                'preview' => [
                    'token' => '',
                    'name' => $outputName,
                    'size' => $estimatedSize,
                    'format' => $targetFormat,
                    'type' => $detectedType,
                    'estimated' => true,
                    'meta_saved_bytes' => $metaSavedBytes,
                    'meta_potential_bytes' => $metaPotentialBytes,
                    'strip_meta' => $stripMeta,
                ],
            ]);

        case 'audio':
            $ok = processAudio($sourcePath, $outputPath, $targetFormat, $quality, $stripMeta, $noCompress);
            if (!$ok || !is_file($outputPath) || filesize($outputPath) <= 0) {
                throw new Exception('Не удалось подготовить точный предпросчёт аудио');
            }
        
            respond([
                'ok' => true,
                'preview' => [
                    'token' => $token,
                    'name' => $outputName,
                    'size' => (int)filesize($outputPath),
                    'format' => $targetFormat,
                    'type' => $detectedType,
                    'estimated' => false,
                    'meta_saved_bytes' => $metaSavedBytes,
                    'meta_potential_bytes' => $metaPotentialBytes,
                    'strip_meta' => $stripMeta,
                ],
            ]);
            break;

        case 'document':
            $ok = processDocument($sourcePath, $outputPath, $targetFormat, $workDir, $originalName, $quality, $stripMeta);
            if (!$ok || !is_file($outputPath) || filesize($outputPath) <= 0) {
                throw new Exception('Не удалось подготовить точный предпросчёт');
            }
            respond([
                'ok' => true,
                'preview' => [
                    'token' => $token,
                    'name' => $outputName,
                    'size' => (int)filesize($outputPath),
                    'format' => $targetFormat,
                    'type' => $detectedType,
                    'estimated' => false,
                    'meta_saved_bytes' => $metaSavedBytes,
                    'meta_potential_bytes' => $metaPotentialBytes,
                    'strip_meta' => $stripMeta,
                ],
            ]);
            break;

        default:
            throw new Exception('Неизвестный тип файла');
    }
}

function cleanupUploadTokens($tokens): void
{
    foreach (normalizePostArray($tokens) as $token) {
        $token = sanitizeHexToken((string)$token);
        if ($token === '') {
            continue;
        }
        cleanupStoredPath(__DIR__ . '/temp_uploads/' . $token);
    }
}

function deletePreviewToken(string $token): void
{
    $token = sanitizeHexToken($token);
    if ($token === '') {
        return;
    }
    cleanupStoredPath(__DIR__ . '/temp_preview/' . $token);
}

function estimateMetadataSavingsBytes(string $sourcePath, string $detectedType): int
{
    switch ($detectedType) {
        case 'video':
        case 'audio':
            return estimateMediaMetadataBytes($sourcePath);

        case 'image':
            return estimateImageMetadataBytes($sourcePath);

        case 'document':
            return estimateDocumentMetadataBytes($sourcePath);

        default:
            return 0;
    }
}

function estimateMediaMetadataBytes(string $sourcePath): int
{
    $ffprobe = resolveFfprobeBinary();
    if (!$ffprobe || !is_file($sourcePath)) {
        return 0;
    }

    $cmd = escapeshellcmd($ffprobe)
        . ' -hide_banner -v quiet -print_format json -show_format -show_streams -show_chapters '
        . escapeshellarg($sourcePath)
        . ' 2>&1';

    $out = [];
    $code = 1;
    exec($cmd, $out, $code);

    if ($code !== 0) {
        return 0;
    }

    $json = implode("\n", $out);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return 0;
    }

    $bytes = 0;

    $measureTags = static function ($tags): int {
        if (!is_array($tags)) return 0;
        $sum = 0;
        foreach ($tags as $k => $v) {
            $sum += strlen((string)$k) + strlen((string)$v) + 8;
        }
        return $sum;
    };

    $bytes += $measureTags($data['format']['tags'] ?? []);
    foreach (($data['streams'] ?? []) as $stream) {
        $bytes += $measureTags($stream['tags'] ?? []);
    }

    $chapters = $data['chapters'] ?? [];
    if (is_array($chapters) && !empty($chapters)) {
        $bytes += count($chapters) * 160;
    }

    if ($bytes > 0) {
        $bytes += 1024;
    }

    return max(0, min(262144, $bytes));
}

function estimateImageMetadataBytes(string $sourcePath): int
{
    if (!is_file($sourcePath)) {
        return 0;
    }

    $ext = strtolower((string)pathinfo($sourcePath, PATHINFO_EXTENSION));
    $blob = @file_get_contents($sourcePath);
    if ($blob === false || $blob === '') {
        return 0;
    }

    if (in_array($ext, ['jpg', 'jpeg'], true)) {
        return estimateJpegMetadataBytes($blob);
    }

    if ($ext === 'png') {
        return estimatePngMetadataBytes($blob);
    }

    if ($ext === 'webp') {
        return estimateWebpMetadataBytes($blob);
    }

    if ($ext === 'gif') {
        return 0;
    }

    return 0;
}

function estimateJpegMetadataBytes(string $blob): int
{
    $len = strlen($blob);
    if ($len < 4 || ord($blob[0]) !== 0xFF || ord($blob[1]) !== 0xD8) {
        return 0;
    }

    $i = 2;
    $sum = 0;

    while ($i + 4 < $len) {
        if (ord($blob[$i]) !== 0xFF) {
            break;
        }

        $marker = ord($blob[$i + 1]);
        $i += 2;

        if ($marker === 0xDA || $marker === 0xD9) {
            break;
        }

        if ($i + 2 > $len) {
            break;
        }

        $segLen = (ord($blob[$i]) << 8) + ord($blob[$i + 1]);
        if ($segLen < 2 || ($i + $segLen) > $len) {
            break;
        }

        if (in_array($marker, [0xE1, 0xE2, 0xED, 0xFE], true)) {
            $sum += $segLen + 2;
        }

        $i += $segLen;
    }

    return max(0, min(262144, $sum));
}

function estimatePngMetadataBytes(string $blob): int
{
    $len = strlen($blob);
    if ($len < 8 || substr($blob, 0, 8) !== "\x89PNG\x0D\x0A\x1A\x0A") {
        return 0;
    }

    $i = 8;
    $sum = 0;
    $metaChunks = ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'iCCP', 'tIME'];

    while ($i + 8 <= $len) {
        $chunkLen = unpack('N', substr($blob, $i, 4))[1];
        $chunkType = substr($blob, $i + 4, 4);

        if (in_array($chunkType, $metaChunks, true)) {
            $sum += $chunkLen + 12;
        }

        $i += 12 + $chunkLen;
        if ($chunkType === 'IEND') {
            break;
        }
    }

    return max(0, min(262144, $sum));
}

function estimateWebpMetadataBytes(string $blob): int
{
    $len = strlen($blob);
    if ($len < 12 || substr($blob, 0, 4) !== 'RIFF' || substr($blob, 8, 4) !== 'WEBP') {
        return 0;
    }

    $i = 12;
    $sum = 0;
    $metaChunks = ['EXIF', 'XMP ', 'ICCP'];

    while ($i + 8 <= $len) {
        $chunkType = substr($blob, $i, 4);
        $chunkLenData = substr($blob, $i + 4, 4);
        if (strlen($chunkLenData) < 4) {
            break;
        }

        $chunkLen = unpack('V', $chunkLenData)[1];
        if (in_array($chunkType, $metaChunks, true)) {
            $sum += $chunkLen + 8;
        }

        $i += 8 + $chunkLen + ($chunkLen % 2);
    }

    return max(0, min(262144, $sum));
}

function estimateDocumentMetadataBytes(string $sourcePath): int
{
    if (!is_file($sourcePath)) {
        return 0;
    }

    $ext = strtolower((string)pathinfo($sourcePath, PATHINFO_EXTENSION));

    if (in_array($ext, ['docx', 'xlsx', 'pptx'], true) && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($sourcePath) === true) {
            $sum = 0;
            foreach (['docProps/core.xml', 'docProps/app.xml', 'docProps/custom.xml'] as $name) {
                $stat = $zip->statName($name);
                if (is_array($stat) && isset($stat['size'])) {
                    $sum += (int)$stat['size'];
                }
            }
            $zip->close();
            return max(0, min(262144, $sum));
        }
    }

    return 0;
}

function ffprobeMedia(string $sourcePath): array
{
    $ffprobe = resolveFfprobeBinary();
    if (!$ffprobe) {
        return [];
    }

    $cmd = escapeshellcmd($ffprobe)
        . ' -hide_banner -v quiet -print_format json -show_format -show_streams '
        . escapeshellarg($sourcePath)
        . ' 2>&1';

    $out = [];
    $code = 1;
    exec($cmd, $out, $code);
    if ($code !== 0) {
        return [];
    }

    $json = implode("\n", $out);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $format = $data['format'] ?? [];
    $streams = $data['streams'] ?? [];

    $videoStream = null;
    $audioStream = null;

    foreach ($streams as $stream) {
        if (($stream['codec_type'] ?? '') === 'video' && $videoStream === null) {
            $videoStream = $stream;
        }
        if (($stream['codec_type'] ?? '') === 'audio' && $audioStream === null) {
            $audioStream = $stream;
        }
    }

    return [
        'duration' => isset($format['duration']) ? (float)$format['duration'] : 0,
        'size' => isset($format['size']) ? (int)$format['size'] : 0,
        'bitrate' => isset($format['bit_rate']) ? (int)$format['bit_rate'] : 0,
        'video_bitrate' => isset($videoStream['bit_rate']) ? (int)$videoStream['bit_rate'] : 0,
        'audio_bitrate' => isset($audioStream['bit_rate']) ? (int)$audioStream['bit_rate'] : 0,
        'width' => isset($videoStream['width']) ? (int)$videoStream['width'] : 0,
        'height' => isset($videoStream['height']) ? (int)$videoStream['height'] : 0,
        'video_codec' => (string)($videoStream['codec_name'] ?? ''),
        'audio_codec' => (string)($audioStream['codec_name'] ?? ''),
    ];
}

function respond(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
