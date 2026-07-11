<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
@ini_set('memory_limit', '1024M');
@ini_set('max_execution_time', '0');
@ini_set('max_input_time', '0');
@ini_set('default_socket_timeout', '0');
@set_time_limit(0);
@ignore_user_abort(true);

$GLOBALS['REQUEST_ERRORS'] = [];
require_once __DIR__ . '/pdf_to_docx.php';

if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    main();
}

function main(): void
{
    $baseDir   = __DIR__;
    $tempDir    = $baseDir . '/temp_converted';
    $logDir     = $baseDir . '/logs';
    $workDir    = $baseDir . '/work_tmp';
    $uploadDir  = $baseDir . '/temp_uploads';
    $previewDir = $baseDir . '/temp_preview';

    ensureDir($tempDir);
    ensureDir($logDir);
    ensureDir($workDir);
    ensureDir($uploadDir);
    ensureDir($previewDir);

    // Периодическая уборка «забытых» файлов (если клиент не вызвал cleanup или обрыв сети)
    $tempTtl = 3600;
    cleanupOldFiles($tempDir, $tempTtl);
    cleanupOldFiles($workDir, $tempTtl);
    cleanupOldFiles($uploadDir, $tempTtl);
    // temp_preview: полная очистка не чаще одного раза в 30 минут
    cleanupTempPreviewHourly(900);

    try {
        $targetFormat       = strtolower(trim((string)($_POST['convert_format'] ?? 'pdf')));
        $quality            = max(10, min(100, (int)($_POST['quality'] ?? 100)));
        $resizeW            = max(0, (int)($_POST['resize_w'] ?? 0));
        $resizeH            = max(0, (int)($_POST['resize_h'] ?? 0));
        $stripMeta = in_array(strtolower((string)($_POST['strip_meta'] ?? '0')), ['1', 'true', 'yes', 'on'], true);
        $noCompress = in_array(strtolower((string)($_POST['no_compress'] ?? '0')), ['1', 'true', 'yes', 'on'], true);
        
        if ($noCompress) {
            $quality = 100;
        }
        
        $currentPreviewToken = sanitizeHexToken((string)($_POST['current_preview_token'] ?? ''));
        $cleanupPreviewTokensList = $_POST['cleanup_preview_tokens'] ?? [];

        $uploadTokens = normalizePostArray($_POST['upload_tokens'] ?? []);
        $sourceEntries = [];

        if (!empty($uploadTokens)) {
            $sourceEntries = collectSourceEntriesFromTokens($uploadTokens);
        } elseif (!empty($_FILES['files']['name'][0])) {
            $sourceEntries = collectSourceEntriesFromUpload($_FILES['files']);
        } else {
            throw new Exception('Файлы не выбраны.');
        }

        if (empty($sourceEntries)) {
            throw new Exception('Не удалось найти загруженные файлы на сервере.');
        }

        if ($currentPreviewToken !== '' && count($sourceEntries) === 1) {
            $previewPath = findPreviewPathByToken($currentPreviewToken);
            if ($previewPath && is_file($previewPath) && filesize($previewPath) > 0) {
                $entry = $sourceEntries[0];
                $effectiveFormat = chooseTargetFormatForType($entry['type'], $targetFormat);
                $outputName = buildOutputName($entry['original_name'], $effectiveFormat);

                sendResult(
                    [['path' => $previewPath, 'name' => $outputName]],
                    $tempDir,
                    mergeAllCleanupPaths($sourceEntries, $currentPreviewToken, $cleanupPreviewTokensList)
                );
            }
        }

        $processedFiles = [];

        foreach ($sourceEntries as $entry) {
            $sourcePath   = (string)$entry['source_path'];
            $originalName = (string)$entry['original_name'];
            $sourceExt    = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
            $detectedType = (string)$entry['type'];


            $sourceNormalizedExt = normalizeExtension($sourceExt);
            $effectiveFormat   = chooseTargetFormatForType($detectedType, $targetFormat);
            $outputExt         = normalizeExtension($effectiveFormat);
            $outputName        = buildOutputName($originalName, $effectiveFormat);
            $tempInternalName  = uniqid('tmp_', true) . '.' . $outputExt;
            $outputPath        = $tempDir . '/' . $tempInternalName;


            $isNoOpConversion = (
                $quality === 100
                && $resizeW === 0
                && $resizeH === 0
                && !$stripMeta
                && $sourceNormalizedExt === $outputExt
            );

            if ($isNoOpConversion) {
                $ok = @copy($sourcePath, $outputPath);
            } else {
                $ok = false;

            switch ($detectedType) {
                case 'image':
                    $ok = processImage(
                        $sourcePath,
                        $outputPath,
                        $effectiveFormat,
                        $quality,
                        $resizeW,
                        $resizeH,
                        $stripMeta,
                        $sourceExt
                    );
                    break;

                case 'video':
                    $ok = processVideo(
                        $sourcePath,
                        $outputPath,
                        $effectiveFormat,
                        $quality,
                        $resizeW,
                        $resizeH,
                        $stripMeta,
                        $noCompress
                    );
                    break;

                case 'audio':
                    $ok = processAudio(
                        $sourcePath,
                        $outputPath,
                        $effectiveFormat,
                        $quality,
                        $stripMeta,
                        $noCompress
                    );
                    break;

                case 'document':
                    $ok = processDocument(
                        $sourcePath,
                        $outputPath,
                        $effectiveFormat,
                        $workDir,
                        $originalName,
                        $quality,
                        $stripMeta,
                        $noCompress
                    );
                    break;

                default:
                    logError("Неизвестный тип файла: {$originalName}");
                    $ok = false;
                    break;
            }

            }

            if ($ok && is_file($outputPath) && filesize($outputPath) > 0) {
                $processedFiles[] = [
                    'path' => $outputPath,
                    'name' => $outputName,
                ];
            } else {
                logError("Не удалось обработать файл: {$originalName}");
                if (is_file($outputPath)) {
                    @unlink($outputPath);
                }
            }
        }

        if (empty($processedFiles)) {
            $errors = $GLOBALS['REQUEST_ERRORS'] ?? [];
            $errors = array_values(array_unique(array_filter($errors)));

            if (!empty($errors)) {
                throw new Exception('Не удалось обработать ни один файл. Причина: ' . implode(' | ', array_slice($errors, -5)));
            }

            throw new Exception('Не удалось обработать ни один файл. Подробности смотри в logs/converter_errors.log');
        }

        sendResult($processedFiles, $tempDir, mergeAllCleanupPaths($sourceEntries, $currentPreviewToken, $cleanupPreviewTokensList));
    } catch (Throwable $e) {
        logError($e->getMessage() . "\n" . $e->getTraceAsString());

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo $e->getMessage();
        exit;
    }
}

function processImage(
    string $sourcePath,
    string $outputPath,
    string $targetFormat,
    int $quality,
    int $resizeW,
    int $resizeH,
    bool $stripMeta,
    string $sourceExt
): bool {
    $targetFormat = strtolower($targetFormat);

    $supportedImageTargets = ['jpeg', 'jpg', 'png', 'webp', 'gif', 'bmp', 'avif', 'pdf'];
    if (!in_array($targetFormat, $supportedImageTargets, true)) {
        return false;
    }

    if (($targetFormat === 'pdf' || $targetFormat === 'avif') && class_exists('Imagick')) {
        return processImageWithImagick($sourcePath, $outputPath, $targetFormat, $quality, $resizeW, $resizeH, $stripMeta);
    }

    if ($targetFormat === 'pdf') {
        logError('Для конвертации изображения в PDF нужен php-imagick.');
        return false;
    }

    if ($targetFormat === 'avif' && !function_exists('imageavif')) {
        logError('На сервере нет поддержки AVIF в GD.');
        return false;
    }

    return processImageWithGD($sourcePath, $outputPath, $targetFormat, $quality, $resizeW, $resizeH);
}

function processImageWithGD(
    string $sourcePath,
    string $outputPath,
    string $targetFormat,
    int $quality,
    int $resizeW,
    int $resizeH
): bool {
    if (!extension_loaded('gd')) {
        logError('php-gd не установлен.');
        return false;
    }

    $blob = @file_get_contents($sourcePath);
    if ($blob === false) {
        return false;
    }

    $src = @imagecreatefromstring($blob);
    if (!$src) {
        return false;
    }

    $width  = imagesx($src);
    $height = imagesy($src);

    [$newW, $newH] = calculateSize($width, $height, $resizeW, $resizeH);

    if ($newW !== $width || $newH !== $height) {
        $dst = imagecreatetruecolor($newW, $newH);

        if (in_array($targetFormat, ['png', 'webp', 'gif', 'avif'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        } else {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($src);
        $src = $dst;
    }

    $ok = false;

    switch ($targetFormat) {
        case 'jpeg':
        case 'jpg':
            $ok = imagejpeg($src, $outputPath, $quality);
            break;

        case 'png':
            $pngCompression = (int)round((100 - $quality) * 9 / 100);
            $pngCompression = max(0, min(9, $pngCompression));
            $ok = imagepng($src, $outputPath, $pngCompression);
            break;

        case 'webp':
            if (function_exists('imagewebp')) {
                $ok = imagewebp($src, $outputPath, $quality);
            }
            break;

        case 'gif':
            $ok = imagegif($src, $outputPath);
            break;

        case 'bmp':
            if (function_exists('imagebmp')) {
                $ok = imagebmp($src, $outputPath);
            }
            break;

        case 'avif':
            if (function_exists('imageavif')) {
                $ok = imageavif($src, $outputPath, $quality);
            }
            break;
    }

    imagedestroy($src);
    return (bool)$ok;
}

function processImageWithImagick(
    string $sourcePath,
    string $outputPath,
    string $targetFormat,
    int $quality,
    int $resizeW,
    int $resizeH,
    bool $stripMeta
): bool {
    if (!class_exists('Imagick')) {
        return false;
    }

    try {
        $im = new Imagick();
        $im->readImage($sourcePath);

        if ($stripMeta) {
            $im->stripImage();
        }

        if ($resizeW > 0 || $resizeH > 0) {
            $w = $resizeW > 0 ? $resizeW : 0;
            $h = $resizeH > 0 ? $resizeH : 0;
            $im->thumbnailImage($w, $h, true, true);
        }

        if ($targetFormat === 'pdf') {
            $im->setImageFormat('pdf');
            $im->setImageCompression(Imagick::COMPRESSION_JPEG);
            $im->setImageCompressionQuality($quality);
        
            // Чем ниже quality, тем сильнее уменьшаем DPI встроенного изображения
            if ($quality >= 90) {
                $dpi = 220;
            } elseif ($quality >= 75) {
                $dpi = 180;
            } elseif ($quality >= 55) {
                $dpi = 150;
            } elseif ($quality >= 35) {
                $dpi = 120;
            } else {
                $dpi = 96;
            }
        
            $im->setImageResolution($dpi, $dpi);
        } else {
            if (in_array($targetFormat, ['jpeg', 'jpg', 'webp', 'avif'], true)) {
                $im->setImageCompressionQuality($quality);
            }
        
            $im->setImageFormat($targetFormat);
        }
        
        $ok = $im->writeImages($outputPath, true);

        $im->clear();
        $im->destroy();

        return (bool)$ok;
    } catch (Throwable $e) {
        logError('Imagick: ' . $e->getMessage());
        return false;
    }
}

function processVideo(
    string $sourcePath,
    string $outputPath,
    string $targetFormat,
    int $quality,
    int $resizeW,
    int $resizeH,
    bool $stripMeta = false,
    bool $noCompress = false
): bool {
    $ffmpeg = resolveFfmpegBinary();
    if (!$ffmpeg) {
        logError('Не найден работающий ffmpeg.');
        return false;
    }

    $allowed = ['mp4', 'webm', 'mkv', 'mov', 'avi', 'gif'];
    if (!in_array($targetFormat, $allowed, true)) {
        logError("Недопустимый формат для видео: {$targetFormat}");
        return false;
    }

    $ff = ffmpegQuietCli($ffmpeg);
    $metaFlags = $stripMeta ? ' -map_metadata -1 -map_chapters -1' : '';
    
    if ($targetFormat === 'gif') {
        $meta = getMediaInfo($sourcePath);
        $srcW = (int)($meta['width'] ?? 0);
        $srcH = (int)($meta['height'] ?? 0);
    
        if ($noCompress) {
            // Максимально качественный GIF, но без безумного раздувания
            $fps = 10;
            $maxWidth = 640;
        } elseif ($quality >= 85) {
            $fps = 12;
            $maxWidth = 720;
        } elseif ($quality >= 65) {
            $fps = 10;
            $maxWidth = 640;
        } elseif ($quality >= 45) {
            $fps = 8;
            $maxWidth = 480;
        } elseif ($quality >= 25) {
            $fps = 6;
            $maxWidth = 400;
        } else {
            $fps = 5;
            $maxWidth = 320;
        }
    
        if ($resizeW > 0 || $resizeH > 0) {
            $w = $resizeW > 0 ? $resizeW : -1;
            $h = $resizeH > 0 ? $resizeH : -1;
        } else {
            if ($srcW > 0 && $srcW > $maxWidth) {
                $w = $maxWidth;
                $h = -1;
            } else {
                $w = $srcW > 0 ? $srcW : $maxWidth;
                $h = -1;
            }
        }
    
        if ($w > 0 && $w % 2 !== 0) {
            $w--;
        }
    
        $scale = "scale={$w}:{$h}:flags=lanczos";
        if ($noCompress) {
            $paletteColors = 256;
            $ditherMode = 'sierra2_4a';   // качественный дизеринг без бандинга
        } else {
            $paletteColors = 256;
            $ditherMode = 'sierra2_4a';
        }
        
        $filter = "fps={$fps},{$scale},split[s0][s1];[s0]palettegen=max_colors={$paletteColors}:stats_mode=full[p];[s1][p]paletteuse=dither={$ditherMode}:diff_mode=rectangle";
    
        $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
            . $metaFlags
            . ' -filter_complex ' . escapeshellarg($filter)
            . ' -an -loop 0 '
            . escapeshellarg($outputPath) . ' 2>&1';
    
        $out = [];
        $code = 1;
        exec($cmd, $out, $code);
    
        if ($code !== 0) {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
            $tail = array_slice($out, -40);
            logError('ffmpeg gif error (code ' . $code . "):\n" . implode("\n", $tail));
            return false;
        }
    
        return is_file($outputPath) && filesize($outputPath) > 0;
    }
    
    $rates = computeVideoTranscodeBitrates($sourcePath, $targetFormat, $quality, $resizeW, $resizeH);
    $targetVideoBitrate = $rates['video_kbps'];
    $targetAudioBitrate = $rates['audio_kbps'];
    $sourceMeta = getMediaInfo($sourcePath);
    $srcW = (int)($sourceMeta['width'] ?? 0);
    $srcH = (int)($sourceMeta['height'] ?? 0);
    
    $scale = '';
    if ($resizeW > 0 || $resizeH > 0) {
        $w = $resizeW > 0 ? makeEven($resizeW) : -2;
        $h = $resizeH > 0 ? makeEven($resizeH) : -2;
        $scale = ' -vf ' . escapeshellarg("scale={$w}:{$h}");
    } elseif ($srcW > 0 && $srcH > 0 && (($srcW % 2 !== 0) || ($srcH % 2 !== 0))) {
        $scale = ' -vf ' . escapeshellarg('scale=trunc(iw/2)*2:trunc(ih/2)*2');
    }
    
    switch ($targetFormat) {
        case 'webm':
            if (!ffmpegSupportsEncoder($ffmpeg, 'libvpx-vp9') || !ffmpegSupportsEncoder($ffmpeg, 'libopus')) {
                logError('Сборка ffmpeg на сервере не поддерживает libvpx-vp9/libopus для WEBM.');
                return false;
            }
            break;

        case 'mp4':
        case 'mkv':
        case 'mov':
            if (!ffmpegSupportsEncoder($ffmpeg, 'libx264')) {
                logError('Сборка ffmpeg на сервере не поддерживает libx264 для H.264 конвертации.');
                return false;
            }
            break;

        case 'avi':
            if (!ffmpegSupportsEncoder($ffmpeg, 'libmp3lame')) {
                logError('Сборка ffmpeg на сервере не поддерживает libmp3lame для AVI-конвертации.');
                return false;
            }
            break;
    }

    switch ($targetFormat) {
        case 'mp4':
            $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
                . $metaFlags
                . $scale
                . ' -c:v libx264 -preset medium -b:v ' . (int)$targetVideoBitrate . 'k'
                . ' -maxrate ' . (int)round($targetVideoBitrate * 1.15) . 'k'
                . ' -bufsize ' . (int)round($targetVideoBitrate * 2) . 'k'
                . ' -movflags +faststart'
                . ' -c:a aac -b:a ' . (int)$targetAudioBitrate . 'k '
                . escapeshellarg($outputPath) . ' 2>&1';
            break;

        case 'webm':
            $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
                . $metaFlags
                . $scale
                . ' -c:v libvpx-vp9 -deadline good -cpu-used 4 -row-mt 1 -tile-columns 2 -frame-parallel 1 -threads 4 -b:v ' . (int)$targetVideoBitrate . 'k'
                . ' -c:a libopus -b:a ' . (int)$targetAudioBitrate . 'k '
                . escapeshellarg($outputPath) . ' 2>&1';
            break;

        case 'mkv':
            $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
                . $metaFlags
                . $scale
                . ' -c:v libx264 -preset medium -b:v ' . (int)$targetVideoBitrate . 'k'
                . ' -maxrate ' . (int)round($targetVideoBitrate * 1.15) . 'k'
                . ' -bufsize ' . (int)round($targetVideoBitrate * 2) . 'k'
                . ' -c:a aac -b:a ' . (int)$targetAudioBitrate . 'k '
                . escapeshellarg($outputPath) . ' 2>&1';
            break;

        case 'mov':
            $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
                . $metaFlags
                . $scale
                . ' -c:v libx264 -preset medium -b:v ' . (int)$targetVideoBitrate . 'k'
                . ' -maxrate ' . (int)round($targetVideoBitrate * 1.15) . 'k'
                . ' -bufsize ' . (int)round($targetVideoBitrate * 2) . 'k'
                . ' -c:a aac -b:a ' . (int)$targetAudioBitrate . 'k '
                . escapeshellarg($outputPath) . ' 2>&1';
            break;

        case 'avi':
            $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
                . $metaFlags
                . $scale
                . ' -c:v mpeg4 -b:v ' . (int)$targetVideoBitrate . 'k'
                . ' -c:a libmp3lame -b:a ' . (int)$targetAudioBitrate . 'k '
                . escapeshellarg($outputPath) . ' 2>&1';
            break;

        default:
            return false;
    }

    $out = [];
    $code = 1;
    exec($cmd, $out, $code);

    if ($code !== 0) {
        if (is_file($outputPath)) {
            @unlink($outputPath);
        }
        $tail = array_slice($out, -40);
        logError('ffmpeg video error (code ' . $code . "):\n" . implode("\n", $tail));
        return false;
    }

    return is_file($outputPath) && filesize($outputPath) > 0;
}

function processAudio(
    string $sourcePath,
    string $outputPath,
    string $targetFormat,
    int $quality,
    bool $stripMeta = false,
    bool $noCompress = false
): bool {
    $ffmpeg = resolveFfmpegBinary();
    if (!$ffmpeg) {
        logError('Не найден работающий ffmpeg.');
        return false;
    }

    $allowed = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];
    if (!in_array($targetFormat, $allowed, true)) {
        return false;
    }

    $meta = getMediaInfo($sourcePath);
    $sourceAudioBitrate = (int)($meta['audio_bitrate_kbps'] ?? 0);
    
    if ($noCompress) {
        $bitrate = $sourceAudioBitrate > 0 ? $sourceAudioBitrate : 320;
    } else {
        $bitrate = mapQualityToAudioBitrateKbps($quality, $sourceAudioBitrate);
    }
    $ff = ffmpegQuietCli($ffmpeg);
    $metaFlags = $stripMeta ? ' -map_metadata -1 -map_chapters -1' : '';

    switch ($targetFormat) {
        case 'mp3':
            $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
                . $metaFlags
                . ' -vn -c:a libmp3lame -b:a ' . (int)$bitrate . 'k '
                . escapeshellarg($outputPath) . ' 2>&1';
            break;

        case 'wav':
            $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
                . $metaFlags
                . ' -vn -c:a pcm_s16le '
                . escapeshellarg($outputPath) . ' 2>&1';
            break;

        case 'ogg':
            $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
                . $metaFlags
                . ' -vn -c:a libvorbis -b:a ' . (int)$bitrate . 'k '
                . escapeshellarg($outputPath) . ' 2>&1';
            break;

        case 'm4a':
            $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
                . $metaFlags
                . ' -vn -c:a aac -b:a ' . (int)$bitrate . 'k '
                . escapeshellarg($outputPath) . ' 2>&1';
            break;

        case 'aac':
            $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
                . $metaFlags
                . ' -vn -c:a aac -b:a ' . (int)$bitrate . 'k '
                . escapeshellarg($outputPath) . ' 2>&1';
            break;

        case 'flac':
            $cmd = $ff . ' -y -i ' . escapeshellarg($sourcePath)
                . $metaFlags
                . ' -vn -c:a flac '
                . escapeshellarg($outputPath) . ' 2>&1';
            break;

        default:
            return false;
    }

    $out = [];
    $code = 1;
    exec($cmd, $out, $code);

    if ($code !== 0) {
        if (is_file($outputPath)) {
            @unlink($outputPath);
        }
        $tail = array_slice($out, -40);
        logError('ffmpeg audio error (code ' . $code . "):\n" . implode("\n", $tail));
        return false;
    }

    return is_file($outputPath) && filesize($outputPath) > 0;
}

function getMediaInfo(string $sourcePath): array
{
    $ffprobe = resolveFfprobeBinary();
    if (!$ffprobe) {
        logError('Не найден работающий ffprobe.');
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

    $video = null;
    $audio = null;

    foreach (($data['streams'] ?? []) as $stream) {
        if (($stream['codec_type'] ?? '') === 'video' && $video === null) {
            $video = $stream;
        }
        if (($stream['codec_type'] ?? '') === 'audio' && $audio === null) {
            $audio = $stream;
        }
    }

    return [
        'duration' => isset($data['format']['duration']) ? (float)$data['format']['duration'] : 0,
        'width' => isset($video['width']) ? (int)$video['width'] : 0,
        'height' => isset($video['height']) ? (int)$video['height'] : 0,
        'video_bitrate_kbps' => isset($video['bit_rate']) ? (int)round($video['bit_rate'] / 1000) : 0,
        'audio_bitrate_kbps' => isset($audio['bit_rate']) ? (int)round($audio['bit_rate'] / 1000) : 0,
        'bitrate' => isset($data['format']['bit_rate']) ? (int)$data['format']['bit_rate'] : 0,
    ];
}

function mapQualityToVideoBitrate(int $quality): int
{
    if ($quality >= 95) return 9000;
    if ($quality >= 90) return 7000;
    if ($quality >= 80) return 4500;
    if ($quality >= 70) return 3000;
    if ($quality >= 60) return 2200;
    if ($quality >= 50) return 1600;
    if ($quality >= 40) return 1100;
    if ($quality >= 30) return 800;
    return 500;
}

function mapQualityToVideoBitrateFromSource(int $quality, int $sourceVideoBitrate): int
{
    $quality = max(10, min(100, $quality));

    if ($sourceVideoBitrate <= 0) {
        return mapQualityToVideoBitrate($quality);
    }

    // 100 = максимально близко к исходнику, но не выше него
    // 10 = сильное сжатие
    $minFactor = 0.18;
    $maxFactor = 0.97;
    $factor = $minFactor + (($quality - 10) / 90) * ($maxFactor - $minFactor);

    $target = (int)round($sourceVideoBitrate * $factor);

    // Никогда не даём целевому битрейту превышать исходный
    return max(250, min($sourceVideoBitrate, $target));
}

function mapQualityToVideoAudioBitrateKbps(int $quality, string $targetFormat, int $sourceAudioBitrate = 0): int
{
    $targetFormat = strtolower($targetFormat);

    if ($targetFormat === 'webm') {
        if ($quality >= 85) $target = 128;
        elseif ($quality >= 55) $target = 96;
        else $target = 64;
    } else {
        if ($quality >= 85) $target = 192;
        elseif ($quality >= 60) $target = 128;
        else $target = 96;
    }

    if ($sourceAudioBitrate > 0) {
        return min($sourceAudioBitrate, $target);
    }

    return $target;
}

function mapQualityToAudioBitrateKbps(int $quality, int $sourceAudioBitrate = 0): int
{
    if ($quality >= 95) $target = 320;
    elseif ($quality >= 85) $target = 256;
    elseif ($quality >= 70) $target = 192;
    elseif ($quality >= 55) $target = 160;
    elseif ($quality >= 40) $target = 128;
    else $target = 96;

    if ($sourceAudioBitrate > 0) {
        return min($sourceAudioBitrate, $target);
    }

    return $target;
}

function compressPdfByQuality(string $sourcePdf, string $outputPdf, int $quality, bool $stripMeta = true): bool
{
    $gs = resolveGhostscriptBinary();
    if (!$gs || !is_file($sourcePdf)) {
        logError('Не найден работающий Ghostscript (gs).');
        return false;
    }

    $preset = mapQualityToPdfPreset($quality);
    $dpi = mapQualityToPdfDpi($quality);

    $cmd = escapeshellcmd($gs)
        . ' -sDEVICE=pdfwrite'
        . ' -dCompatibilityLevel=1.4'
        . ' -dNOPAUSE'
        . ' -dBATCH'
        . ' -dQUIET'
        . ' -dPDFSETTINGS=' . escapeshellarg($preset)
        . ' -dDownsampleColorImages=true'
        . ' -dDownsampleGrayImages=true'
        . ' -dDownsampleMonoImages=true'
        . ' -dColorImageDownsampleType=/Bicubic'
        . ' -dGrayImageDownsampleType=/Bicubic'
        . ' -dMonoImageDownsampleType=/Subsample'
        . ' -dColorImageResolution=' . (int)$dpi
        . ' -dGrayImageResolution=' . (int)$dpi
        . ' -dMonoImageResolution=' . (int)max(150, $dpi)
        . ($stripMeta ? ' -dDetectDuplicateImages=true -dCompressFonts=true -dSubsetFonts=true' : '')
        . ' -sOutputFile=' . escapeshellarg($outputPdf)
        . ' ' . escapeshellarg($sourcePdf)
        . ' 2>&1';

    $out = [];
    $code = 1;
    exec($cmd, $out, $code);

    if ($code !== 0) {
        logError("ghostscript pdf error:\n" . implode("\n", $out));
        return false;
    }

    return is_file($outputPdf) && filesize($outputPdf) > 0;
}

function mapQualityToPdfPreset(int $quality): string
{
    if ($quality <= 25) return '/screen';
    if ($quality <= 45) return '/ebook';
    if ($quality <= 70) return '/printer';
    if ($quality <= 85) return '/prepress';
    return '/default';
}

function mapQualityToPdfDpi(int $quality): int
{
    if ($quality <= 25) return 72;
    if ($quality <= 45) return 96;
    if ($quality <= 60) return 120;
    if ($quality <= 75) return 150;
    if ($quality <= 90) return 200;
    return 300;
}

function calculateVideoResize(int $origW, int $origH, int $newW, int $newH): array
{
    if ($origW <= 0 || $origH <= 0) {
        return ['width' => 0, 'height' => 0];
    }

    if ($newW > 0 && $newH <= 0) {
        $newH = (int)round($newW * ($origH / $origW));
    } elseif ($newW <= 0 && $newH > 0) {
        $newW = (int)round($newH * ($origW / $origH));
    } elseif ($newW > 0 && $newH > 0) {
        $ratio = min($newW / $origW, $newH / $origH);
        $newW = (int)round($origW * $ratio);
        $newH = (int)round($origH * $ratio);
    } else {
        $newW = $origW;
        $newH = $origH;
    }

    return [
        'width' => makeEven(max(2, $newW)),
        'height' => makeEven(max(2, $newH)),
    ];
}

function makeEven(int $value): int
{
    return ($value % 2 === 0) ? $value : ($value - 1);
}

function processDocument(
    string $sourcePath,
    string $outputPath,
    string $targetFormat,
    string $workDir,
    string $originalName,
    int $quality,
    bool $stripMeta,
    bool $noCompress = false
): bool {
    $sourceExt = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

    if ($sourceExt === 'pdf' && $targetFormat === 'docx') {
        return processPdfToDocxSpecial(
            $sourcePath,
            $outputPath,
            $workDir,
            $originalName,
            $stripMeta
        );
    }

    $soffice = resolveSofficeBinary();

    if (!$soffice) {
        logError('Не найден работающий LibreOffice (soffice/libreoffice).');
        return false;
    }

    $allowed = ['pdf', 'docx', 'xlsx', 'pptx', 'txt'];
    if (!in_array($targetFormat, $allowed, true)) {
        logError("Формат {$targetFormat} не разрешён для документов.");
        return false;
    }

    $jobDir = $workDir . '/' . uniqid('doc_', true);
    ensureDir($jobDir);

    $loProfile = $jobDir . '/lo_profile';
    ensureDir($loProfile);
    $absProfile = realpath($loProfile);
    if ($absProfile === false) {
        logError('Не удалось создать каталог профиля LibreOffice.');
        cleanupDir($jobDir);
        return false;
    }

    $userInst = 'file://' . str_replace('\\', '/', $absProfile);

    $safeInputName = cleanFileName((string)pathinfo($originalName, PATHINFO_FILENAME));
    if (strlen($safeInputName) > 120) {
        $safeInputName = substr($safeInputName, 0, 120);
    }
    if ($safeInputName === '') {
        $safeInputName = 'document';
    }

    $inputCopy = $jobDir . '/' . $safeInputName . '.' . $sourceExt;

    if (!copy($sourcePath, $inputCopy)) {
        logError('Не удалось скопировать исходный файл во временную папку.');
        cleanupDir($jobDir);
        return false;
    }

    $convertFilters = $targetFormat === 'pdf'
        ? ['pdf', 'pdf:writer_pdf_Export']
        : [$targetFormat];

    $prevHome = getenv('HOME');
    $prevLc = getenv('LC_ALL');
    $prevLang = getenv('LANG');
    $prevTmp = getenv('TMPDIR');

    putenv('HOME=' . $jobDir);
    putenv('TMPDIR=' . $jobDir);
    putenv('LC_ALL=C.UTF-8');
    putenv('LANG=C.UTF-8');

    $lastOut = [];
    $lastCode = 1;
    $okConvert = false;

    foreach ($convertFilters as $attempt => $filter) {
        if ($attempt > 0) {
            $outExt = normalizeExtension($targetFormat);
            foreach (glob($jobDir . '/*.' . $outExt) ?: [] as $orphan) {
                if (is_file($orphan)) {
                    @unlink($orphan);
                }
            }
        }

        $cmd = escapeshellcmd($soffice)
            . ' --headless --invisible --norestore --nolockcheck'
            . ' -env:UserInstallation=' . escapeshellarg($userInst)
            . ' --convert-to ' . escapeshellarg($filter)
            . ' --outdir ' . escapeshellarg($jobDir)
            . ' ' . escapeshellarg($inputCopy)
            . ' 2>&1';

        $out = [];
        $code = 1;
        exec($cmd, $out, $code);

        $lastOut = $out;
        $lastCode = $code;

        if ($code === 0) {
            $okConvert = true;
            break;
        }

        logConverterTrace('soffice попытка "' . $filter . '" код=' . $code . ' вывод: ' . implode(' | ', array_slice($out, 0, 8)) . "\nCMD: " . $cmd);
    }

    if ($prevHome !== false && $prevHome !== '') {
        putenv('HOME=' . $prevHome);
    } else {
        @putenv('HOME');
    }

    if ($prevLc !== false && $prevLc !== '') {
        putenv('LC_ALL=' . $prevLc);
    } else {
        @putenv('LC_ALL');
    }

    if ($prevLang !== false && $prevLang !== '') {
        putenv('LANG=' . $prevLang);
    } else {
        @putenv('LANG');
    }

    if ($prevTmp !== false && $prevTmp !== '') {
        putenv('TMPDIR=' . $prevTmp);
    } else {
        @putenv('TMPDIR');
    }

    if (!$okConvert) {
        $hint = implode("\n", array_slice($lastOut, -12));
        logConverterTrace("document: {$originalName} → {$targetFormat}, quality={$quality}\nsoffice: {$soffice}\nфинальный вывод:\n" . $hint);
        logError(
            'LibreOffice не смог конвертировать документ (код ' . $lastCode
            . '). Частая причина на хостинге — нет прав на временный каталог или профиль; подробности в logs/converter_errors.log.'
        );
        cleanupDir($jobDir);
        return false;
    }

    $generated = $jobDir . '/' . $safeInputName . '.' . normalizeExtension($targetFormat);

    if (!is_file($generated)) {
        $files = glob($jobDir . '/*.' . normalizeExtension($targetFormat));
        if (!empty($files)) {
            $generated = $files[0];
        }
    }

    if (!is_file($generated)) {
        logError('LibreOffice завершился без ошибки, но выходной файл не найден.');
        cleanupDir($jobDir);
        return false;
    }

    if ($targetFormat === 'pdf' && !$noCompress && ($quality < 95 || $stripMeta)) {
        $compressedPdf = $jobDir . '/' . $safeInputName . '_compressed.pdf';
    
        if (compressPdfByQuality($generated, $compressedPdf, $quality, $stripMeta)) {
            if (is_file($compressedPdf) && filesize($compressedPdf) > 0) {
                @unlink($generated);
                $generated = $compressedPdf;
            }
        } else {
            logError('Ghostscript не смог дополнительно сжать PDF. Оставлен PDF после LibreOffice.');
        }
    }
    
    if ($stripMeta) {
    $genExt = strtolower((string)pathinfo($generated, PATHINFO_EXTENSION));
    if (in_array($genExt, ['docx', 'xlsx', 'pptx'], true)) {
        stripOfficeOpenXmlMetadata($generated);
    }
}
    
    $ok = rename($generated, $outputPath);
    cleanupDir($jobDir);

    return $ok && is_file($outputPath) && filesize($outputPath) > 0;
}

function stripOfficeOpenXmlMetadata(string $filePath): bool
{
    $ext = strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
        return false;
    }

    if (!class_exists('ZipArchive')) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return false;
    }

    $coreXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
 xmlns:dc="http://purl.org/dc/elements/1.1/"
 xmlns:dcterms="http://purl.org/dc/terms/"
 xmlns:dcmitype="http://purl.org/dc/dcmitype/"
 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"></cp:coreProperties>
XML;

    $appXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"></Properties>
XML;

    $changed = false;

    if ($zip->locateName('docProps/core.xml') !== false) {
        $zip->addFromString('docProps/core.xml', $coreXml);
        $changed = true;
    }

    if ($zip->locateName('docProps/app.xml') !== false) {
        $zip->addFromString('docProps/app.xml', $appXml);
        $changed = true;
    }

    if ($zip->locateName('docProps/custom.xml') !== false) {
        $customXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties"
 xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"></Properties>
XML;
        $zip->addFromString('docProps/custom.xml', $customXml);
        $changed = true;
    }

    $zip->close();
    return $changed;
}

function sendResult(array $processedFiles, string $tempDir, array $extraCleanupPaths = []): void
{
    if (count($processedFiles) === 1) {
        $file = $processedFiles[0];
        $ext = (string)pathinfo($file['name'], PATHINFO_EXTENSION);
        $mime = mimeByExtension($ext);
        $downloadName = (string)$file['name'];
        $fallbackName = cleanFileName((string)pathinfo($downloadName, PATHINFO_FILENAME)) . '.' . normalizeExtension($ext);

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('X-Download-Filename: ' . rawurlencode($downloadName));
        $disposition = 'attachment; filename="' . addcslashes($fallbackName, '"\\') . '"; filename*=UTF-8' . "''" . rawurlencode($downloadName);
        header('Content-Disposition: ' . $disposition);
        header('Content-Length: ' . filesize($file['path']));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: public');
        header('Expires: 0');

        readfile($file['path']);
        @unlink($file['path']);

        foreach ($extraCleanupPaths as $path) {
            cleanupStoredPath($path);
        }

        exit;
    }

    if (!extension_loaded('zip')) {
        throw new Exception('php-zip не установлен, а файлов несколько.');
    }

    $zipPath = $tempDir . '/archive_' . uniqid('', true) . '.zip';
    $zipName = 'converted_' . date('Y-m-d_H-i-s') . '.zip';
    $zipFallbackName = 'converted_files.zip';

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Не удалось создать ZIP-архив.');
    }

    foreach ($processedFiles as $file) {
        $zip->addFile($file['path'], basename((string)$file['name']));
    }

    $zip->close();

    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('X-Download-Filename: ' . rawurlencode($zipName));
    $zipDisposition = 'attachment; filename="' . addcslashes($zipFallbackName, '"\\') . '"; filename*=UTF-8' . "''" . rawurlencode($zipName);
    header('Content-Disposition: ' . $zipDisposition);
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    readfile($zipPath);

    @unlink($zipPath);

    foreach ($processedFiles as $file) {
        @unlink($file['path']);
    }

    foreach ($extraCleanupPaths as $path) {
        cleanupStoredPath($path);
    }

    exit;
}

function detectFileType(string $fileName): string
{
    $ext = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));

    $image = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'avif'];
    $video = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'];
    $audio = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];
    $document = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf', 'odt', 'ods', 'odp', 'html', 'htm', 'csv'];

    if (in_array($ext, $image, true)) return 'image';
    if (in_array($ext, $video, true)) return 'video';
    if (in_array($ext, $audio, true)) return 'audio';
    if (in_array($ext, $document, true)) return 'document';

    return 'unknown';
}

function chooseTargetFormatForType(string $detectedType, string $targetFormat): string
{
    $targetFormat = strtolower($targetFormat);

    $map = [
        'image' => ['allowed' => ['jpeg', 'jpg', 'png', 'webp', 'gif', 'bmp', 'avif', 'pdf'], 'default' => 'webp'],
        'video' => ['allowed' => ['mp4', 'webm', 'mkv', 'mov', 'avi', 'gif'], 'default' => 'mp4'],
        'audio' => ['allowed' => ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'], 'default' => 'mp3'],
        'document' => ['allowed' => ['pdf', 'docx', 'xlsx', 'pptx', 'txt'], 'default' => 'pdf'],
    ];

    if (!isset($map[$detectedType])) {
        return $targetFormat;
    }

    return in_array($targetFormat, $map[$detectedType]['allowed'], true)
        ? $targetFormat
        : $map[$detectedType]['default'];
}

function normalizeExtension(string $format): string
{
    $format = strtolower($format);
    return $format === 'jpeg' ? 'jpg' : $format;
}

function calculateSize(int $origW, int $origH, int $newW, int $newH): array
{
    if ($newW <= 0 && $newH <= 0) {
        return [$origW, $origH];
    }

    $ratio = $origW / max(1, $origH);

    if ($newW > 0 && $newH <= 0) {
        $newH = (int)round($newW / $ratio);
    } elseif ($newW <= 0 && $newH > 0) {
        $newW = (int)round($newH * $ratio);
    } else {
        $calcRatio = min($newW / max(1, $origW), $newH / max(1, $origH));
        $newW = (int)round($origW * $calcRatio);
        $newH = (int)round($origH * $calcRatio);
    }

    return [max(1, $newW), max(1, $newH)];
}

function resolveExecutableByVersion(array $candidates, string $versionArg = '--version'): ?string
{
    foreach ($candidates as $candidate) {
        $out = [];
        $code = 1;
        $cmd = escapeshellcmd($candidate) . ' ' . $versionArg . ' 2>&1';
        exec($cmd, $out, $code);

        $output = trim(implode("\n", $out));
        if ($code === 0 && $output !== '') {
            return $candidate;
        }
    }

    return null;
}

/** Кэш путей на время одного HTTP-запроса — не дергать ffmpeg -version десятки раз подряд */
function resolveFfmpegBinary(): ?string
{
    static $resolved = false;
    static $cached = null;
    if ($resolved) {
        return $cached;
    }
    $resolved = true;
    $cached = resolveExecutableByVersion(['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg'], '-version');

    return $cached;
}

function resolveFfprobeBinary(): ?string
{
    static $resolved = false;
    static $cached = null;
    if ($resolved) {
        return $cached;
    }
    $resolved = true;
    $cached = resolveExecutableByVersion(['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', 'ffprobe'], '-version');

    return $cached;
}

function resolveGhostscriptBinary(): ?string
{
    static $resolved = false;
    static $cached = null;
    if ($resolved) {
        return $cached;
    }
    $resolved = true;
    $cached = resolveExecutableByVersion(['/usr/bin/gs', '/usr/local/bin/gs', 'gs'], '--version');

    return $cached;
}

function resolveSofficeBinary(): ?string
{
    static $resolved = false;
    static $cached = null;
    if ($resolved) {
        return $cached;
    }
    $resolved = true;
    $cached = resolveExecutableByVersion([
        '/usr/lib/libreoffice/program/soffice',
        '/usr/bin/libreoffice',
        '/usr/bin/soffice',
        'libreoffice',
        'soffice',
    ], '--version');

    return $cached;
}

/**
 * Общий расчёт целевых битрейтов (как в processVideo) для оценки размера без перекодирования.
 *
 * @return array{video_kbps: int, audio_kbps: int, duration: float}
 */
function computeVideoTranscodeBitrates(
    string $sourcePath,
    string $targetFormat,
    int $quality,
    int $resizeW,
    int $resizeH
): array {
    $meta = getMediaInfo($sourcePath);
    $sourceVideoBitrate = (int)($meta['video_bitrate_kbps'] ?? 0);

    $sourceAudioBitrate = (int)($meta['audio_bitrate_kbps'] ?? 0);
    $targetAudioBitrate = mapQualityToVideoAudioBitrateKbps($quality, $targetFormat, $sourceAudioBitrate);
    $targetVideoBitrate = mapQualityToVideoBitrateFromSource($quality, $sourceVideoBitrate);

    if (!empty($meta['width']) && !empty($meta['height']) && ($resizeW > 0 || $resizeH > 0)) {
        $resizeInfo = calculateVideoResize(
            (int)$meta['width'],
            (int)$meta['height'],
            $resizeW,
            $resizeH
        );

        $pixelRatio = ($meta['width'] * $meta['height']) > 0
            ? (($resizeInfo['width'] * $resizeInfo['height']) / ($meta['width'] * $meta['height']))
            : 1;

        $targetVideoBitrate = max(250, (int)round($targetVideoBitrate * max(0.35, $pixelRatio)));
    }

    return [
        'video_kbps' => $targetVideoBitrate,
        'audio_kbps' => $targetAudioBitrate,
        'duration' => isset($meta['duration']) ? (float)$meta['duration'] : 0.0,
    ];
}

function estimateVideoOutputBytes(
    string $sourcePath,
    string $targetFormat,
    int $quality,
    int $resizeW,
    int $resizeH
): int {
    $targetFormat = strtolower($targetFormat);

    if ($targetFormat === 'gif') {
        $meta = getMediaInfo($sourcePath);
        $duration = isset($meta['duration']) ? (float)$meta['duration'] : 0.0;
        if ($duration <= 0) {
            return 0;
        }

        $srcW = (int)($meta['width'] ?? 0);
        $srcH = (int)($meta['height'] ?? 0);
        $sourceVideoBitrate = (int)($meta['video_bitrate_kbps'] ?? 0);

        if ($quality >= 85) {
            $fps = 12;
            $maxWidth = 720;
        } elseif ($quality >= 65) {
            $fps = 10;
            $maxWidth = 640;
        } elseif ($quality >= 45) {
            $fps = 8;
            $maxWidth = 480;
        } elseif ($quality >= 25) {
            $fps = 6;
            $maxWidth = 400;
        } else {
            $fps = 5;
            $maxWidth = 320;
        }

        if ($resizeW > 0 || $resizeH > 0) {
            $w = $resizeW > 0 ? $resizeW : -1;
            $h = $resizeH > 0 ? $resizeH : -1;
        } else {
            if ($srcW > 0 && $srcW > $maxWidth) {
                $w = $maxWidth;
                $h = -1;
            } else {
                $w = $srcW > 0 ? $srcW : $maxWidth;
                $h = -1;
            }
        }

        if ($w > 0 && $w % 2 !== 0) {
            $w--;
        }

        $targetW = $srcW;
        $targetH = $srcH;

        if ($srcW > 0 && $srcH > 0) {
            if ($w > 0 && $h <= 0) {
                $targetW = $w;
                $targetH = (int)round($w * ($srcH / $srcW));
            } elseif ($w <= 0 && $h > 0) {
                $targetH = $h;
                $targetW = (int)round($h * ($srcW / $srcH));
            } elseif ($w > 0 && $h > 0) {
                $ratio = min($w / $srcW, $h / $srcH);
                $targetW = (int)round($srcW * $ratio);
                $targetH = (int)round($srcH * $ratio);
            }
        }

        $pixelRatio = ($srcW > 0 && $srcH > 0 && $targetW > 0 && $targetH > 0)
            ? (($targetW * $targetH) / ($srcW * $srcH))
            : 1.0;

        $baseVideoBitrate = mapQualityToVideoBitrateFromSource($quality, $sourceVideoBitrate);
        $gifBitrate = max(
            180,
            (int)round($baseVideoBitrate * 0.70 * max(0.20, $pixelRatio) * ($fps / 12))
        );

        return (int)round(($gifBitrate * 1000 / 8) * $duration);
    }

    $b = computeVideoTranscodeBitrates($sourcePath, $targetFormat, $quality, $resizeW, $resizeH);
    $duration = $b['duration'];
    if ($duration <= 0) {
        return 0;
    }

    $totalKbps = $b['video_kbps'] + $b['audio_kbps'];

    return (int)round(($totalKbps * 1000 / 8) * $duration);
}

function estimateAudioOutputBytes(string $sourcePath, string $targetFormat, int $quality): int
{
    $meta = getMediaInfo($sourcePath);
    $duration = isset($meta['duration']) ? (float)$meta['duration'] : 0.0;
    if ($duration <= 0) {
        return 0;
    }

    $targetFormat = strtolower($targetFormat);
    if ($targetFormat === 'wav') {
        return (int)round(44100 * 2 * 2 * $duration);
    }
    if ($targetFormat === 'flac') {
        return (int)round(44100 * 2 * 2 * $duration * 0.55);
    }

    $sourceAudioBitrate = (int)($meta['audio_bitrate_kbps'] ?? 0);
    $bitrate = mapQualityToAudioBitrateKbps($quality, $sourceAudioBitrate);

    return (int)round(($bitrate * 1000 / 8) * $duration);
}

/**
 * Префикс ffmpeg: тихий режим + лимит потоков (ниже пик CPU на shared-хостинге).
 * На выделенном сервере при необходимости замени -threads 2 на -threads 0 (авто).
 * exec() блокируется до exit дочернего процесса — «висячих» ffmpeg после запроса не остаётся.
 */
function ffmpegSupportsEncoder(string $ffmpeg, string $encoder): bool
{
    static $cache = [];
    $key = $ffmpeg . '|' . $encoder;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $out = [];
    $code = 1;
    $cmd = escapeshellcmd($ffmpeg) . ' -hide_banner -encoders 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0) {
        return $cache[$key] = false;
    }

    $pattern = '/\b' . preg_quote($encoder, '/') . '\b/';
    return $cache[$key] = (bool)preg_grep($pattern, $out);
}

function ffmpegQuietCli(string $ffmpeg): string
{
    return escapeshellcmd($ffmpeg) . ' -hide_banner -nostats -loglevel error -threads 2';
}

function findBinary(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (strpos($candidate, '/') !== false) {
            if (@is_executable($candidate)) {
                return $candidate;
            }
            continue;
        }

        $result = trim((string)@shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
        if ($result !== '' && @is_executable($result)) {
            return $result;
        }
    }

    return null;
}

function mimeByExtension(string $ext): string
{
    $ext = strtolower($ext);

    $map = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'bmp'  => 'image/bmp',
        'avif' => 'image/avif',
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt'  => 'text/plain; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'odt'  => 'application/vnd.oasis.opendocument.text',
        'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp'  => 'application/vnd.oasis.opendocument.presentation',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mkv'  => 'video/x-matroska',
        'mov'  => 'video/quicktime',
        'avi'  => 'video/x-msvideo',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'm4a'  => 'audio/mp4',
        'aac'  => 'audio/aac',
        'flac' => 'audio/flac',
        'zip'  => 'application/zip',
    ];

    return $map[$ext] ?? 'application/octet-stream';
}

function cleanFileName(string $str): string
{
    $tr = [
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh','З'=>'Z','И'=>'I',
        'Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T',
        'У'=>'U','Ф'=>'F','Х'=>'H','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch','Ъ'=>'','Ы'=>'Y','Ь'=>'',
        'Э'=>'E','Ю'=>'Yu','Я'=>'Ya','а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e',
        'ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p',
        'р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch',
        'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'
    ];

    $str = strtr($str, $tr);
    $str = preg_replace('/[^A-Za-z0-9_\-]/', '_', $str);
    $str = preg_replace('/_+/', '_', (string)$str);
    $str = trim((string)$str, '_-');

    return $str !== '' ? $str : 'file';
}

function normalizePostArray($value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map('strval', $value), static fn($v) => $v !== ''));
    }

    $value = trim((string)$value);
    return $value === '' ? [] : [$value];
}

function normalizeIncomingFiles(array $files): array
{
    $items = [];

    if (isset($files['name']) && is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $items[] = [
                'name'     => (string)($files['name'][$i] ?? ''),
                'tmp_name' => (string)($files['tmp_name'][$i] ?? ''),
                'error'    => (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size'     => (int)($files['size'][$i] ?? 0),
            ];
        }
        return $items;
    }

    if (!empty($files)) {
        $items[] = [
            'name'     => (string)($files['name'] ?? ''),
            'tmp_name' => (string)($files['tmp_name'] ?? ''),
            'error'    => (int)($files['error'] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int)($files['size'] ?? 0),
        ];
    }

    return $items;
}

function collectSourceEntriesFromUpload(array $files): array
{
    $entries = [];

    foreach (normalizeIncomingFiles($files) as $item) {
        if (($item['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $entries[] = [
            'source_path'   => (string)$item['tmp_name'],
            'original_name' => (string)$item['name'],
            'type'          => detectFileType((string)$item['name']),
            'upload_token'  => '',
        ];
    }

    return $entries;
}

function collectSourceEntriesFromTokens(array $tokens): array
{
    $entries = [];

    foreach ($tokens as $token) {
        $token = sanitizeHexToken((string)$token);
        if ($token === '') {
            continue;
        }

        $meta = readUploadMeta($token);
        $path = $meta['path'] ?? findStoredPathByToken(__DIR__ . '/temp_uploads', $token);

        if (!$meta || !$path || !is_file($path)) {
            continue;
        }

        $entries[] = [
            'source_path'   => $path,
            'original_name' => (string)($meta['name'] ?? ('file_' . $token)),
            'type'          => (string)($meta['type'] ?? detectFileType((string)($meta['name'] ?? ''))),
            'upload_token'  => $token,
        ];
    }

    return $entries;
}

function buildCleanupPathsForEntries(array $entries): array
{
    $paths = [];

    foreach ($entries as $entry) {
        if (!empty($entry['upload_token'])) {
            $paths[] = __DIR__ . '/temp_uploads/' . $entry['upload_token'];
        }
    }

    return array_values(array_unique($paths));
}

/** Загрузки + превью по токену (если конвертация заново, файл в temp_preview иначе остаётся навсегда). */
function mergeCleanupPathsWithPreview(array $entries, string $previewToken): array
{
    $paths = buildCleanupPathsForEntries($entries);
    $pt = sanitizeHexToken($previewToken);
    if ($pt !== '') {
        $paths[] = __DIR__ . '/temp_preview/' . $pt;
    }

    return array_values(array_unique(array_filter($paths)));
}

/**
 * То же + все токены предпросмотра за сессию (каждая смена качества/формата даёт новый файл в temp_preview).
 *
 * @param mixed $cleanupPreviewTokensList
 */
function mergeAllCleanupPaths(array $entries, string $currentPreviewToken, $cleanupPreviewTokensList): array
{
    $paths = mergeCleanupPathsWithPreview($entries, $currentPreviewToken);

    foreach (normalizePostArray($cleanupPreviewTokensList) as $t) {
        $t = sanitizeHexToken((string)$t);
        if ($t !== '') {
            $paths[] = __DIR__ . '/temp_preview/' . $t;
        }
    }

    return array_values(array_unique(array_filter($paths)));
}

function buildOutputName(string $originalName, string $effectiveFormat): string
{
    $base = (string)pathinfo($originalName, PATHINFO_FILENAME);
    return $base . '_new.' . normalizeExtension($effectiveFormat);
}

function sanitizeHexToken(string $token): string
{
    return preg_replace('/[^a-f0-9]/i', '', $token) ?? '';
}

function uploadMetaPath(string $token): string
{
    return __DIR__ . '/temp_uploads/' . $token . '.json';
}

function readUploadMeta(string $token): array
{
    $token = sanitizeHexToken($token);
    if ($token === '') {
        return [];
    }

    $path = uploadMetaPath($token);
    if (!is_file($path)) {
        return [];
    }

    $json = @file_get_contents($path);
    $data = json_decode((string)$json, true);

    return is_array($data) ? $data : [];
}

function writeUploadMeta(string $token, array $meta): void
{
    @file_put_contents(uploadMetaPath($token), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function findStoredPathByToken(string $dir, string $token): ?string
{
    $token = sanitizeHexToken($token);
    if ($token === '' || !is_dir($dir)) {
        return null;
    }

    $matches = glob($dir . '/' . $token . '.*') ?: [];
    foreach ($matches as $match) {
        if (is_file($match) && substr($match, -5) !== '.json') {
            return $match;
        }
    }

    return null;
}

function findPreviewPathByToken(string $token): ?string
{
    return findStoredPathByToken(__DIR__ . '/temp_preview', $token);
}

function cleanupStoredPath(string $basePath): void
{
    if ($basePath === '') {
        return;
    }

    if (is_file($basePath)) {
        @unlink($basePath);
    }

    foreach (glob($basePath . '.*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function cleanupOldFiles(string $dir, int $maxAgeSeconds): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file) && (time() - filemtime($file) > $maxAgeSeconds)) {
            @unlink($file);
        } elseif (is_dir($file) && (time() - filemtime($file) > $maxAgeSeconds)) {
            cleanupDir($file);
        }
    }
}

/**
 * Полная очистка temp_preview раз в 30 минут.
 * Stamp-файл хранится прямо в temp_preview, чтобы легко проверять состояние на сервере.
 */
function cleanupTempPreviewHourly(int $intervalSeconds = 1800): void
{
    $previewDir = __DIR__ . '/temp_preview';
    if (!is_dir($previewDir)) {
        return;
    }

    $stampPath = $previewDir . '/.temp_preview_cleanup_stamp';
    $now = time();
    if (is_file($stampPath) && ($now - (int)@filemtime($stampPath)) < $intervalSeconds) {
        return;
    }

    $cutoffTs = $now - $intervalSeconds;
    // Avoid GLOB_DOTS (may not exist on older PHP builds).
    // We also intentionally iterate hidden/dot files using scandir().
    $paths = scandir($previewDir);
    if ($paths === false) {
        return;
    }
    foreach ($paths as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $previewDir . '/' . $name;
        if ($path === $stampPath) {
            continue; // stamp file must be preserved and updated below
        }
        if (is_dir($path)) {
            // For subfolders we keep it simple: remove the whole folder only if it looks old.
            // (Currently temp_preview is expected to contain mostly files.)
            if ((int)@filemtime($path) < $cutoffTs) {
                cleanupDir($path);
            }
            continue;
        }
        if (is_file($path)) {
            if ((int)@filemtime($path) < $cutoffTs) {
                if (!@unlink($path)) {
                    logConverterTrace('cleanupTempPreviewHourly: failed to delete file: ' . $path);
                }
            }
        }
    }

    @file_put_contents($stampPath, (string)$now . PHP_EOL, LOCK_EX);
    @touch($stampPath, $now);
}

function cleanupDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            cleanupDir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

/**
 * Диагностика LibreOffice только при сбоях — пишется в converter_trace.log,
 * чтобы converter_errors.log оставался для реальных ошибок.
 */
function logConverterTrace(string $message): void
{
    $logDir = __DIR__ . '/logs';
    ensureDir($logDir);

    $line = '[' . date('Y-m-d H:i:s') . '] [doc] ' . $message . PHP_EOL;
    @file_put_contents($logDir . '/converter_trace.log', $line, FILE_APPEND);
}

function logError(string $message): void
{
    $logDir = __DIR__ . '/logs';
    ensureDir($logDir);

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logDir . '/converter_errors.log', $line, FILE_APPEND);

    if (!isset($GLOBALS['REQUEST_ERRORS']) || !is_array($GLOBALS['REQUEST_ERRORS'])) {
        $GLOBALS['REQUEST_ERRORS'] = [];
    }

    $clean = trim((string)preg_replace('/\s+/', ' ', $message));
    if ($clean !== '') {
        $GLOBALS['REQUEST_ERRORS'][] = $clean;
    }
}