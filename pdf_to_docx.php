<?php

declare(strict_types=1);

function processPdfToDocxSpecial(
    string $sourcePath,
    string $outputPath,
    string $workDir,
    string $originalName,
    bool $stripMeta = false
): bool {
    if (!is_file($sourcePath)) {
        logError('PDF -> DOCX: исходный PDF не найден.');
        return false;
    }

    if (!class_exists('ZipArchive')) {
        logError('PDF -> DOCX: php-zip не установлен.');
        return false;
    }

    $jobDir = rtrim($workDir, '/\\') . '/' . uniqid('pdfdocx_', true);
    ensureDir($jobDir);
    $layoutOutput = $jobDir . '/layout_output.docx';

    if (convertPdfToDocxViaLayoutPython($sourcePath, $layoutOutput, $jobDir)) {
        $ok = @rename($layoutOutput, $outputPath);
        cleanupDir($jobDir);
        return $ok && is_file($outputPath) && filesize($outputPath) > 0;
    }

    try {
        $text = extractPdfTextForDocx($sourcePath, $jobDir);
        $text = normalizePdfExtractedText($text);

        if ($text === '') {
            $text = extractPdfTextForDocxViaOcr($sourcePath, $jobDir);
            $text = normalizePdfExtractedText($text);
        }

        if ($text === '') {
            logError('PDF -> DOCX: не удалось извлечь текст из PDF ни напрямую, ни через OCR.');
            cleanupDir($jobDir);
            return false;
        }

        $title = (string)pathinfo($originalName, PATHINFO_FILENAME);
        $ok = buildDocxFromPlainText($text, $outputPath, $title, $stripMeta);

        cleanupDir($jobDir);
        return $ok && is_file($outputPath) && filesize($outputPath) > 0;
    } catch (Throwable $e) {
        logError('PDF -> DOCX: ' . $e->getMessage());
        cleanupDir($jobDir);
        return false;
    }
}

function convertPdfToDocxViaLayoutPython(string $sourcePath, string $outputPath, string $jobDir): bool
{
    $python = resolvePython3Binary();
    if (!$python) {
        logError('PDF -> DOCX layout: python3 не найден.');
        return false;
    }

    $scriptPath = __DIR__ . '/pdf_to_docx_layout.py';
    if (!is_file($scriptPath)) {
        logError('PDF -> DOCX layout: helper pdf_to_docx_layout.py не найден.');
        return false;
    }

    $cmd = escapeshellcmd($python)
        . ' ' . escapeshellarg($scriptPath)
        . ' ' . escapeshellarg($sourcePath)
        . ' ' . escapeshellarg($outputPath)
        . ' 2>&1';

    $out = [];
    $code = 1;
    exec($cmd, $out, $code);

    if ($code !== 0) {
        logError('PDF -> DOCX layout error: ' . implode(' | ', array_slice($out, -20)));
        return false;
    }

    return is_file($outputPath) && filesize($outputPath) > 0;
}

function extractPdfTextForDocx(string $sourcePath, string $jobDir): string
{
    $txtPath = $jobDir . '/extracted.txt';

    $pdftotext = resolvePdfToTextBinary();
    if ($pdftotext) {
        $cmd = escapeshellcmd($pdftotext)
            . ' -enc UTF-8 -layout '
            . escapeshellarg($sourcePath)
            . ' ' . escapeshellarg($txtPath)
            . ' 2>&1';

        $out = [];
        $code = 1;
        exec($cmd, $out, $code);

        if ($code === 0 && is_file($txtPath)) {
            $text = (string)@file_get_contents($txtPath);
            if (trim($text) !== '') {
                return $text;
            }
        }
    }

    $mutool = resolveMutoolBinary();
    if ($mutool) {
        @unlink($txtPath);

        $cmd = escapeshellcmd($mutool)
            . ' draw -F txt -o ' . escapeshellarg($txtPath)
            . ' ' . escapeshellarg($sourcePath)
            . ' 2>&1';

        $out = [];
        $code = 1;
        exec($cmd, $out, $code);

        if ($code === 0 && is_file($txtPath)) {
            $text = (string)@file_get_contents($txtPath);
            if (trim($text) !== '') {
                return $text;
            }
        }
    }

    $python = resolvePython3Binary();
    if ($python) {
        @unlink($txtPath);

        $scriptPath = $jobDir . '/extract_pdf_text.py';
        $script = <<<'PY'
import sys

src = sys.argv[1]
out = sys.argv[2]
text = ""

errors = []

try:
    from pypdf import PdfReader
    reader = PdfReader(src)
    text = "\n\f\n".join((page.extract_text() or "") for page in reader.pages)
except Exception as e:
    errors.append(f"pypdf: {e}")

if not text:
    try:
        import PyPDF2
        reader = PyPDF2.PdfReader(src)
        text = "\n\f\n".join((page.extract_text() or "") for page in reader.pages)
    except Exception as e:
        errors.append(f"PyPDF2: {e}")

with open(out, "w", encoding="utf-8") as f:
    f.write(text)

if text.strip():
    sys.exit(0)

sys.stderr.write(" | ".join(errors))
sys.exit(1)
PY;
        @file_put_contents($scriptPath, $script);

        $cmd = escapeshellcmd($python)
            . ' ' . escapeshellarg($scriptPath)
            . ' ' . escapeshellarg($sourcePath)
            . ' ' . escapeshellarg($txtPath)
            . ' 2>&1';

        $out = [];
        $code = 1;
        exec($cmd, $out, $code);

        if ($code === 0 && is_file($txtPath)) {
            $text = (string)@file_get_contents($txtPath);
            if (trim($text) !== '') {
                return $text;
            }
        }
    }

    return '';
}

function extractPdfTextForDocxViaOcr(string $sourcePath, string $jobDir): string
{
    $pdftoppm = resolvePdfToPpmBinary();
    $tesseract = resolveTesseractBinary();

    if (!$pdftoppm || !$tesseract) {
        logError(
            'PDF -> DOCX OCR: отсутствуют зависимости. pdftoppm='
            . ($pdftoppm ?: 'not-found')
            . ', tesseract='
            . ($tesseract ?: 'not-found')
        );
        return '';
    }

    $imagesDir = $jobDir . '/ocr_pages';
    ensureDir($imagesDir);

    $prefix = $imagesDir . '/page';

    $cmd = escapeshellcmd($pdftoppm)
        . ' -r 200 -png '
        . escapeshellarg($sourcePath)
        . ' ' . escapeshellarg($prefix)
        . ' 2>&1';

    $out = [];
    $code = 1;
    exec($cmd, $out, $code);

    if ($code !== 0) {
        logError('PDF -> DOCX OCR: pdftoppm не смог подготовить изображения страниц.');
        return '';
    }

    $pages = glob($imagesDir . '/page-*.png') ?: [];
    natsort($pages);
    $pages = array_values($pages);

    if (empty($pages)) {
        return '';
    }

    $allText = [];
    $lang = detectTesseractLang();

    foreach ($pages as $idx => $imgPath) {
        $outBase = $imagesDir . '/ocr_' . ($idx + 1);

        $cmd = escapeshellcmd($tesseract)
            . ' ' . escapeshellarg($imgPath)
            . ' ' . escapeshellarg($outBase)
            . ' -l ' . escapeshellarg($lang)
            . ' --psm 6 txt 2>&1';

        $ocrOut = [];
        $ocrCode = 1;
        exec($cmd, $ocrOut, $ocrCode);

        if ($ocrCode !== 0) {
            logError('PDF -> DOCX OCR: tesseract error: ' . implode(' | ', array_slice($ocrOut, -10)));
        }

        $txtPath = $outBase . '.txt';
        if ($ocrCode === 0 && is_file($txtPath)) {
            $pageText = (string)@file_get_contents($txtPath);
            if (trim($pageText) !== '') {
                $allText[] = trim($pageText);
            }
        }
    }

    return trim(implode("\n\n\f\n\n", $allText));
}

function resolvePdfToPpmBinary(): ?string
{
    return '/usr/bin/pdftoppm';
}

function resolveTesseractBinary(): ?string
{
    return '/usr/bin/tesseract';
}

function detectTesseractLang(): string
{
    $tesseract = resolveTesseractBinary();
    if (!$tesseract) {
        return 'eng';
    }

    $cmd = escapeshellcmd($tesseract) . ' --list-langs 2>&1';
    $out = [];
    $code = 1;
    exec($cmd, $out, $code);

    if ($code !== 0) {
        return 'eng';
    }

    $langs = array_map('trim', $out);
    $hasRus = in_array('rus', $langs, true);
    $hasEng = in_array('eng', $langs, true);

    if ($hasRus && $hasEng) {
        return 'rus+eng';
    }
    if ($hasRus) {
        return 'rus';
    }
    if ($hasEng) {
        return 'eng';
    }

    return 'eng';
}

function normalizePdfExtractedText(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    if ($text !== '' && !preg_match('//u', $text)) {
        $converted = @iconv('Windows-1251', 'UTF-8//IGNORE', $text);
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }
    }

    $text = preg_replace("/\t/u", '    ', $text) ?? $text;
    $text = preg_replace("/\n{4,}/u", "\n\n\n", $text) ?? $text;

    return trim($text);
}

function buildDocxFromPlainText(string $text, string $outputPath, string $title = '', bool $stripMeta = false): bool
{
    $zip = new ZipArchive();
    if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    $bodyXml = buildWordBodyXmlFromText($text);
    $safeTitle = $stripMeta ? '' : trim($title);
    $createdIso = gmdate('Y-m-d\TH:i:s\Z');

    $contentTypes = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;

    $rels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;

    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $bodyXml
        . '<w:sectPr>'
        . '<w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/>'
        . '</w:sectPr>'
        . '</w:body></w:document>';

    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
        . ' xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . docxXmlEscape($safeTitle) . '</dc:title>'
        . '<dc:creator>' . ($stripMeta ? '' : 'Universal Converter') . '</dc:creator>'
        . '<cp:lastModifiedBy>' . ($stripMeta ? '' : 'Universal Converter') . '</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $createdIso . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $createdIso . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"
 xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Universal Converter</Application>
</Properties>
XML;

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);

    $zip->close();
    return is_file($outputPath) && filesize($outputPath) > 0;
}

function buildWordBodyXmlFromText(string $text): string
{
    $pages = preg_split('/\f/u', $text) ?: [$text];
    $xml = '';

    foreach ($pages as $pageIndex => $pageText) {
        $lines = preg_split('/\n/u', str_replace("\r", '', (string)$pageText)) ?: [''];
        $pageHasContent = false;

        foreach ($lines as $line) {
            $line = rtrim((string)$line);
            if ($line === '') {
                $xml .= '<w:p/>';
                continue;
            }

            $pageHasContent = true;
            $xml .= '<w:p><w:r><w:t xml:space="preserve">' . docxXmlEscape($line) . '</w:t></w:r></w:p>';
        }

        if (!$pageHasContent) {
            $xml .= '<w:p/>';
        }

        if ($pageIndex < count($pages) - 1) {
            $xml .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        }
    }

    return $xml !== '' ? $xml : '<w:p/>';
}

function docxXmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function resolvePdfToTextBinary(): ?string
{
    return '/usr/bin/pdftotext';
}

function resolveMutoolBinary(): ?string
{
    static $resolved = false;
    static $cached = null;
    if ($resolved) {
        return $cached;
    }
    $resolved = true;
    $cached = findBinary(['/usr/bin/mutool', '/usr/local/bin/mutool', 'mutool']);

    return $cached;
}

function resolvePython3Binary(): ?string
{
    return '/usr/bin/python3';
}
