<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Turns an HTML document into JPG images with the headless Edge / Chrome browser that is already on the
 * computer (no extra software), so ledgers and invoices can be sent on WhatsApp as pictures that open in
 * the chat and look exactly like the screen. Long pages are cut into parts of about one A4 page.
 * Browser: auto-detected, or HTML_TO_IMAGE_BROWSER in .env.
 */
class HtmlToImage
{
    /** Width of the rendered page in px (a phone shows it full width) */
    const WIDTH = 1000;

    /** Tallest page the browser renders; longer documents are not converted (caller sends a PDF) */
    const MAX_HEIGHT = 15000;

    /** Height of one image part, about an A4 page at this width */
    const PART_HEIGHT = 1400;

    /**
     * Path of Edge / Chrome, or null when none is installed
     *
     * @return string|null
     */
    public static function browser()
    {
        $candidates = array_filter([
            config('constants.html_to_image_browser'),
            'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
            'C:\Program Files\Google\Chrome\Application\chrome.exe',
            'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/microsoft-edge',
        ]);
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  string  $html  full document or a fragment (wrapped in a white page)
     * @param  string  $name  file name prefix of the images
     * @return array JPG paths, top to bottom; empty when no browser / the page is too long / it failed
     */
    public static function convert($html, $name = 'document')
    {
        $browser = self::browser();
        if (empty($browser) || ! function_exists('imagecreatefrompng')) {
            return [];
        }

        $dir = config('constants.mpdf_temp_path').'/img_'.uniqid();
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $html_file = $dir.'/page.html';
        $png_file = $dir.'/page.png';

        if (stripos($html, '<html') === false) {
            $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
                .'<style>html, body { margin: 0; background: #fff; } body { padding: 24px; font-family: "Segoe UI", Roboto, Arial, sans-serif; }</style>'
                .'</head><body>'.$html.'</body></html>';
        }
        file_put_contents($html_file, $html);

        try {
            $process = new Process([
                $browser,
                '--headless=new',
                '--disable-gpu',
                '--hide-scrollbars',
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-extensions',
                '--force-device-scale-factor=1',
                '--user-data-dir='.$dir.'/profile',
                '--window-size='.self::WIDTH.','.self::MAX_HEIGHT,
                '--screenshot='.$png_file,
                'file:///'.str_replace('\\', '/', $html_file),
            ]);
            $process->setTimeout(90);
            $process->run();

            if (! is_file($png_file)) {
                \Log::warning('HTML to image failed: '.trim($process->getErrorOutput()));

                return self::cleanup([], $dir);
            }

            $images = self::split($png_file, $dir, $name);

            return self::cleanup($images, $dir, false);
        } catch (\Exception $e) {
            \Log::warning('HTML to image failed: '.$e->getMessage());

            return self::cleanup([], $dir);
        }
    }

    /**
     * Crops the white space below the content and cuts it into parts at blank lines between rows
     */
    private static function split($png_file, $dir, $name)
    {
        $img = imagecreatefrompng($png_file);
        $width = imagesx($img);
        $height = imagesy($img);

        $bottom = self::contentBottom($img, $width, $height);
        if ($bottom >= $height - 5) {
            //Content reaches the end of the page: longer than the browser window, it would be cut
            imagedestroy($img);

            return [];
        }
        $bottom = min($height, $bottom + 24);

        $images = [];
        $top = 0;
        $part = 1;
        while ($top < $bottom) {
            $end = min($bottom, $top + self::PART_HEIGHT);
            if ($end < $bottom) {
                $end = self::blankLineAbove($img, $width, $end, $top + (int) (self::PART_HEIGHT * 0.6));
            }

            $piece = imagecreatetruecolor($width, $end - $top);
            imagecopy($piece, $img, 0, 0, 0, $top, $width, $end - $top);
            $file = $dir.'/'.$name.'_'.$part.'.jpg';
            imagejpeg($piece, $file, 88);
            imagedestroy($piece);

            $images[] = $file;
            $top = $end;
            $part++;
        }
        imagedestroy($img);

        return $images;
    }

    /** Lowest row that is not plain white */
    private static function contentBottom($img, $width, $height)
    {
        for ($y = $height - 1; $y > 0; $y -= 2) {
            if (self::inkInRow($img, $width, $y) > 0) {
                return $y;
            }
        }

        return 0;
    }

    /**
     * A row near $y to cut at: almost no dark pixels (only the table's vertical borders), so no text is cut
     */
    private static function blankLineAbove($img, $width, $y, $min_y)
    {
        for ($row = $y; $row > $min_y; $row--) {
            if (self::inkInRow($img, $width, $row) <= $width * 0.03) {
                return $row;
            }
        }

        return $y;
    }

    /** Number of dark-ish pixels in a row (sampled every 2 px) */
    private static function inkInRow($img, $width, $y)
    {
        $ink = 0;
        for ($x = 0; $x < $width; $x += 2) {
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            if ($r + $g + $b < 600) {
                $ink += 2;
            }
        }

        return $ink;
    }

    /** Removes the working files, keeps the images (unless $all) */
    private static function cleanup($images, $dir, $all = true)
    {
        foreach (['page.html', 'page.png'] as $file) {
            if (is_file($dir.'/'.$file)) {
                @unlink($dir.'/'.$file);
            }
        }
        self::removeDir($dir.'/profile');
        if ($all) {
            self::removeDir($dir);
        }

        return $images;
    }

    /**
     * Deletes images made by convert() and their folder
     */
    public static function delete($images)
    {
        foreach ($images as $image) {
            if (is_file($image)) {
                @unlink($image);
            }
        }
        if (! empty($images)) {
            @rmdir(dirname($images[0]));
        }
    }

    private static function removeDir($dir)
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
