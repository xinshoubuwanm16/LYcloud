<?php

namespace App\Captcha;

/**
 * 图形验证码（GD 库，零外部依赖）
 *
 * 特性：
 *   - 4 位字符（去除易混淆的 0/o/1/l/i），校验不区分大小写
 *   - 仅存 sha256 哈希到 Session，不落明文
 *   - 一次性：校验通过（或过期）后立即销毁
 *   - 5 分钟有效期
 *   - TTF 字体优先（自动探测系统常见字体路径），无 TTF 时退化为内置位图字体放大
 */
class Captcha
{
    private const SESSION_KEY = '_captcha_v1';
    private const TTL = 300; // 5 分钟
    private const LENGTH = 4;

    /** 去除易混淆字符（0/o/1/l/i）的字符集 */
    private const CHARSET = '23456789abcdefghjkmnpqrstuvwxyz';

    /** 常见 TTF 字体路径（按顺序探测，兼容 Debian/Ubuntu/CentOS/宝塔/Windows） */
    private const FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
        'C:\\Windows\\Fonts\\arialbd.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
    ];

    /** 生成验证码：写 Session（哈希+过期时间），返回明文（仅此一次返回） */
    public static function issue(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::CHARSET[random_int(0, strlen(self::CHARSET) - 1)];
        }
        $_SESSION[self::SESSION_KEY] = [
            'hash'    => hash('sha256', strtolower($code)),
            'expires' => time() + self::TTL,
        ];
        return $code;
    }

    /**
     * 校验（不区分大小写、一次性、过期即焚）
     */
    public static function verify(string $input): bool
    {
        $st = $_SESSION[self::SESSION_KEY] ?? null;
        // 无论成功失败，只要参与校验即销毁（一次性，防重放）
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_array($st) || !isset($st['hash'], $st['expires'])) {
            return false;
        }
        if (time() > (int)$st['expires']) {
            return false; // 已过期
        }
        $input = strtolower(trim($input));
        if ($input === '' || !preg_match('/^[' . self::CHARSET . ']{' . self::LENGTH . '}$/', $input)) {
            return false;
        }
        return hash_equals($st['hash'], hash('sha256', $input));
    }

    /** 渲染 PNG 图片（二进制字符串） */
    public static function renderImage(string $code): string
    {
        $w = 150; $h = 46;

        $img = imagecreatetruecolor($w, $h);

        // 深色底（贴合站点主题）+ 轻微随机明度
        $bgR = 13 + random_int(0, 10); $bgG = 21 + random_int(0, 12); $bgB = 38 + random_int(0, 16);
        $bg  = imagecolorallocate($img, $bgR, $bgG, $bgB);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);

        // 干扰弧线 ×5（随机位置/半径/颜色）
        for ($i = 0; $i < 5; $i++) {
            $c = imagecolorallocate($img, random_int(60, 110), random_int(60, 110), random_int(90, 150));
            imagearc($img, random_int(0, $w), random_int(0, $h), random_int(30, 120), random_int(20, 90), random_int(0, 180), random_int(181, 360), $c);
        }
        // 噪点 ×140
        for ($i = 0; $i < 140; $i++) {
            $c = imagecolorallocate($img, random_int(70, 160), random_int(70, 160), random_int(90, 190));
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }

        $font = self::detectFont();
        $chars = str_split($code);
        $slot = (int)floor($w / count($chars));

        if ($font !== '') {
            foreach ($chars as $i => $ch) {
                $fg = imagecolorallocate($img, random_int(120, 255), random_int(150, 255), random_int(180, 255));
                $size  = random_int(19, 23);
                $angle = random_int(-22, 22);
                $x = (int)($i * $slot + $slot * 0.18);
                $y = (int)($h / 2 + $size / 2 + random_int(-4, 4));
                @imagettftext($img, (float)$size, (float)$angle, $x, $y, $fg, $font, strtoupper($ch));
            }
        } else {
            // 退化方案：内置位图字体 ×2 放大（无旋转）
            foreach ($chars as $i => $ch) {
                $fg = imagecolorallocate($img, random_int(130, 255), random_int(160, 255), random_int(190, 255));
                $gx = $i * $slot + (int)($slot / 2) - 10;
                $gy = (int)($h / 2) - 7 + random_int(-4, 4);
                $tmp = imagecreatetruecolor(30, 26);
                $tbg = imagecolorallocate($tmp, $bgR, $bgG, $bgB);
                imagefilledrectangle($tmp, 0, 0, 30, 26, $tbg);
                imagestring($tmp, 5, 3, 5, strtoupper($ch), $fg);
                imagecopyresized($img, $tmp, $gx, $gy, 0, 0, 30, 26, 30, 26);
                imagedestroy($tmp);
            }
        }

        // 前景干扰短线 ×3（画在字符之上）
        for ($i = 0; $i < 3; $i++) {
            $c = imagecolorallocate($img, random_int(120, 200), random_int(120, 200), random_int(140, 220));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }

        ob_start();
        imagepng($img);
        $png = (string)ob_get_clean();
        imagedestroy($img);
        return $png;
    }

    /** 探测可用 TTF 字体 */
    private static function detectFont(): string
    {
        foreach (self::FONT_CANDIDATES as $f) {
            if (is_file($f) && is_readable($f)) {
                return $f;
            }
        }
        return '';
    }

    /** 输出图片响应头（no-store，防缓存回放） */
    public static function noStoreHeaders(): void
    {
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
