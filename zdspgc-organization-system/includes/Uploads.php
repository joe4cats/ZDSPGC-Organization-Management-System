<?php
/**
 * Uploads.php — secure file handling for the whole system.
 *
 * Layout (inside the project so shared hosting works out of the box):
 *   uploads/profiles/       profile pictures       (web accessible, images only)
 *   uploads/organizations/  organization logos     (web accessible, images only)
 *   uploads/events/         event banners          (web accessible, images only)
 *   uploads/documents/      organization documents (private, served by download.php)
 *   uploads/reports/        post-activity files    (private, served by download.php)
 *
 * Every file is validated twice (extension + real MIME type from fileinfo),
 * given a randomised name and stored outside any script-executable path.
 */

declare(strict_types=1);

final class Uploads
{
    /** Folders that may be reached directly by an <img> tag (images only). */
    public const IMAGE_DIRS = ['profiles', 'organizations', 'events'];

    /** Folders whose contents are only served through download.php. */
    public const PRIVATE_DIRS = ['documents', 'reports'];

    public static function imageDir(string $name): string
    {
        return in_array($name, self::IMAGE_DIRS, true) ? $name : 'profiles';
    }

    public static function privateDir(string $name): string
    {
        return in_array($name, self::PRIVATE_DIRS, true) ? $name : 'documents';
    }

    /**
     * Validates + stores an image (profile picture, logo, banner).
     *
     * @param array<string,mixed> $file
     * @return array{ok:bool,message:string,path:string,url:string}
     */
    public static function image(array $file, string $dir = 'profiles', int $maxMb = PROFILE_MAX_MB): array
    {
        $dir    = self::imageDir($dir);
        $result = self::store($file, $dir, ALLOWED_IMAGES, $maxMb * 1024 * 1024);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => $result['message'], 'path' => '', 'url' => ''];
        }
        return [
            'ok'      => true,
            'message' => 'Uploaded successfully.',
            'path'    => $result['path'],
            'url'     => Helpers::url($result['path']),
        ];
    }

    /**
     * Validates + stores a document (PDF, Office file, image, CSV).
     *
     * @param array<string,mixed> $file
     * @return array{ok:bool,message:string,path:string,name:string,size:int,mime:string,original:string}
     */
    public static function document(array $file, string $dir = 'documents'): array
    {
        $empty  = ['ok' => false, 'path' => '', 'name' => '', 'size' => 0, 'mime' => '', 'original' => ''];
        $dir    = self::privateDir($dir);
        $result = self::store($file, $dir, ALLOWED_UPLOADS, UPLOAD_MAX_BYTES);
        if (!$result['ok']) {
            return ['message' => $result['message']] + $empty;
        }
        return [
            'ok'       => true,
            'message'  => 'Uploaded successfully.',
            'path'     => $result['path'],
            'name'     => basename($result['path']),
            'size'     => $result['size'],
            'mime'     => $result['mime'],
            'original' => $result['original'],
        ];
    }

    /**
     * Shared validation + move step.
     *
     * @param array<string,mixed>             $file
     * @param array<string,array<int,string>> $allowlist
     * @return array{ok:bool,message:string,path:string,size:int,mime:string,original:string}
     */
    private static function store(array $file, string $dir, array $allowlist, int $maxBytes): array
    {
        $check = Security::validateUpload($file, $allowlist, $maxBytes);
        if (!$check['ok']) {
            return ['ok' => false, 'message' => $check['message'], 'path' => '', 'size' => 0, 'mime' => '', 'original' => ''];
        }

        $moved = Security::storeUpload($file, self::absoluteDir($dir), $check['ext']);
        if (!$moved['ok']) {
            return ['ok' => false, 'message' => $moved['message'], 'path' => '', 'size' => 0, 'mime' => '', 'original' => ''];
        }

        return [
            'ok'       => true,
            'message'  => 'OK',
            'path'     => 'uploads/' . $dir . '/' . $moved['name'],
            'size'     => (int) ($file['size'] ?? 0),
            'mime'     => $check['mime'],
            'original' => $check['original'],
        ];
    }

    public static function absoluteDir(string $dir): string
    {
        return rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $dir;
    }

    /**
     * Absolute path of a stored relative path ("uploads/documents/x.pdf").
     * Returns null when the path tries to escape uploads/ or does not exist.
     */
    public static function absolutePath(?string $relative): ?string
    {
        $relative = self::normalise($relative);
        if ($relative === null) {
            return null;
        }
        $absolute = APP_ROOT . '/' . $relative;
        return is_file($absolute) ? $absolute : null;
    }

    /** Validates a stored relative upload path. */
    public static function normalise(?string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
        if ($relative === '' || !str_starts_with($relative, 'uploads/')) {
            return null;
        }
        if (str_contains($relative, '..') || preg_match('#^uploads/[A-Za-z0-9._/-]+$#', $relative) !== 1) {
            return null;
        }
        return $relative;
    }

    /** Public URL for an upload (only meaningful for the image folders). */
    public static function url(?string $relative): ?string
    {
        $relative = self::normalise($relative);
        return $relative === null ? null : Helpers::url($relative);
    }

    /** Deletes a stored file. Returns true when the file is gone. */
    public static function delete(?string $relative): bool
    {
        $absolute = self::absolutePath($relative);
        return $absolute !== null && @unlink($absolute) === true;
    }

    /** Creates every upload folder plus its hardening .htaccess files. */
    public static function ensureDirs(): void
    {
        foreach (array_merge(self::IMAGE_DIRS, self::PRIVATE_DIRS) as $dir) {
            $path = self::absoluteDir($dir);
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }

        $imageGuard = "# Images only — never execute scripts in this folder.\n"
            . "Options -Indexes\n"
            . "<FilesMatch \"\\.(?i:php|phtml|phar|cgi|pl|py|sh|html|htm)$\">\n  Require all denied\n</FilesMatch>\n";
        foreach (self::IMAGE_DIRS as $dir) {
            $file = self::absoluteDir($dir) . DIRECTORY_SEPARATOR . '.htaccess';
            if (!is_file($file)) {
                @file_put_contents($file, $imageGuard);
            }
        }

        $privateGuard = "# Private documents — served only through download.php.\nRequire all denied\n";
        foreach (self::PRIVATE_DIRS as $dir) {
            $file = self::absoluteDir($dir) . DIRECTORY_SEPARATOR . '.htaccess';
            if (!is_file($file)) {
                @file_put_contents($file, $privateGuard);
            }
        }
    }

    /**
     * Writes the small demo PDF files referenced by the seeded document rows so
     * the demo data never points at a missing file.
     *
     * @return array<int,string> file names written
     */
    public static function createDemoFiles(): array
    {
        self::ensureDirs();
        $dir  = self::absoluteDir('documents');
        $docs = [
            'demo_constitution.pdf'    => 'Constitution and By-Laws',
            'demo_officer_list.pdf'    => 'List of Officers',
            'demo_member_list.pdf'     => 'List of Members',
            'demo_activity_report.pdf' => 'Activity and Attendance Report',
            'demo_accreditation.pdf'   => 'Certificate of Accreditation',
        ];
        $written = [];
        foreach ($docs as $name => $title) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                @file_put_contents($path, self::miniPdf($title, 'ZDSPGC Organization Management System — demo document'));
            }
            $written[] = $name;
        }
        return $written;
    }

    /** Builds a tiny but valid one-page PDF (no external library needed). */
    private static function miniPdf(string $title, string $subtitle): string
    {
        $esc     = static fn (string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        $content = "BT /F1 18 Tf 60 760 Td (" . $esc($title) . ") Tj ET\n"
                 . "BT /F1 11 Tf 60 730 Td (" . $esc($subtitle) . ") Tj ET\n"
                 . "BT /F1 10 Tf 60 700 Td (This file is part of the ZDSPGC Organization Management System demo data.) Tj ET\n"
                 . "BT /F1 10 Tf 60 685 Td (Replace it with the signed institutional copy before production use.) Tj ET\n";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>\nendobj\n",
            "4 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n",
            "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];

        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf      .= $object;
        }
        $xrefPos = strlen($pdf);
        $pdf    .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefPos . "\n%%EOF\n";

        return $pdf;
    }
}
