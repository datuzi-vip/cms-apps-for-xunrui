<?php namespace Phpcmf\Model;

use Phpcmf\Model;
use Phpcmf\Service;

/**
 * 微信内容安全检测（msgSecCheck / imgSecCheck）
 *
 * 文档：
 * https://developers.weixin.qq.com/miniprogram/dev/api-backend/open-api/sec-check/security.msgSecCheck.html
 * https://developers.weixin.qq.com/miniprogram/dev/api-backend/open-api/sec-check/security.imgSecCheck.html
 */
class Seccheck extends Model
{

    /** 违规提示（微信要求仅提示含违规信息，不展开细则） */
    public const RISK_TIP = '所发布内容含违规信息';

    /** 文本单次检测上限（微信约定） */
    private const MAX_CONTENT_LEN = 2500;

    /** 微信 imgSecCheck 尺寸上限 */
    private const IMG_MAX_W = 750;
    private const IMG_MAX_H = 1334;

    /** 微信 imgSecCheck 送检文件大小上限（字节） */
    private const IMG_MAX_BYTES = 1048576;

    /** 兼容站点上传上限：本地原图最大允许 5M，送检前再压到 IMG_MAX_BYTES */
    public const IMG_ACCEPT_MAX_BYTES = 5242880;

    /** access_token 本地缓存文件 */
    private const TOKEN_CACHE = 'wxseccheck_xcx_access_token';

    /** 读取插件配置 */
    public function get_config(): array
    {
        $config = Service::M('app')->get_config('wxseccheck');
        if (!is_array($config)) {
            $config = [];
        }

        return array_merge([
            'use' => '1',
            'use_image' => '1',
            'modules' => '',
            'fields' => '',
            'scene' => '3',
            'xcx_appid' => '',
            'xcx_secret' => '',
            'fail_open' => '0',
            'skip_admin' => '0',
        ], $config);
    }

    /**
     * 模块内容发布/编辑之前检测
     * 钩子：module_content_before
     *
     * @param array $data 模块提交数据
     * @return array dr_return_data
     */
    public function check_before_publish($data): array
    {
        $config = $this->get_config();
        if ((string)($config['use'] ?? '1') !== '1') {
            return dr_return_data(1, 'ok', $data);
        }

        if ((string)($config['skip_admin'] ?? '0') === '1' && IS_ADMIN) {
            return dr_return_data(1, 'ok', $data);
        }

        $dirname = $this->current_module_dirname();
        $modules = $this->parse_list((string)($config['modules'] ?? ''));
        if ($modules && $dirname !== '' && !in_array($dirname, $modules, true)) {
            return dr_return_data(1, 'ok', $data);
        }

        if (!is_array($data)) {
            $data = [];
        }

        $text = $this->build_check_text($data, $config);
        if ($text === '') {
            return dr_return_data(1, 'ok', $data);
        }

        $openid = $this->resolve_openid($data);
        $chunks = $this->split_text($text, self::MAX_CONTENT_LEN);
        $title = trim(strip_tags((string)($data['title'] ?? '')));

        foreach ($chunks as $chunk) {
            $rt = $this->msg_sec_check($chunk, $openid, (int)$config['scene'], $title);
            if (!(int)$rt['code']) {
                // 违规：固定文案；其它错误：按 fail_open 决定是否放行
                if (($rt['data']['risky'] ?? false) === true) {
                    return dr_return_data(0, self::RISK_TIP);
                }
                if ((string)($config['fail_open'] ?? '0') === '1') {
                    $this->log('fail_open: ' . (string)$rt['msg']);
                    continue;
                }
                return dr_return_data(0, (string)$rt['msg']);
            }
        }

        return dr_return_data(1, 'ok', $data);
    }

    /**
     * 文件上传后检测图片
     * 钩子：upload_file（无返回值，违规时删除文件并中断响应）
     *
     * @param array $payload type/data/file_path/attachment
     */
    public function check_after_upload($payload): void
    {
        $config = $this->get_config();
        // 图片开关独立于文本开关 use
        if ((string)($config['use_image'] ?? '1') !== '1') {
            return;
        }
        if ((string)($config['skip_admin'] ?? '0') === '1' && IS_ADMIN) {
            return;
        }

        if (!is_array($payload)) {
            return;
        }

        $file = $this->resolve_upload_filepath($payload);
        if ($file === '' || !is_file($file)) {
            return;
        }
        if (!$this->is_image_file($file)) {
            return;
        }

        $rt = $this->img_sec_check($file);
        if ((int)$rt['code']) {
            return;
        }

        if (($rt['data']['risky'] ?? false) === true) {
            @unlink($file);
            $this->abort_upload(self::RISK_TIP);
        }

        if ((string)($config['fail_open'] ?? '0') === '1') {
            $this->log('img fail_open: ' . (string)$rt['msg']);
            return;
        }

        @unlink($file);
        $this->abort_upload((string)$rt['msg']);
    }

    /**
     * 调用微信文本内容安全识别
     *
     * @return array code=1 通过；code=0 拦截；data.risky=true 表示内容违规
     */
    public function msg_sec_check(string $content, string $openid = '', int $scene = 3, string $title = ''): array
    {
        $content = trim($content);
        if ($content === '') {
            return dr_return_data(1, 'ok');
        }

        $tokenRt = $this->get_access_token();
        if (!(int)$tokenRt['code']) {
            return dr_return_data(0, (string)$tokenRt['msg']);
        }
        $accessToken = (string)$tokenRt['data']['access_token'];

        $url = 'https://api.weixin.qq.com/wxa/msg_sec_check?access_token=' . urlencode($accessToken);

        // 有 openid 走 2.0；后台无 openid 时降级 1.0（仅 content）
        if ($openid !== '') {
            $scene = in_array($scene, [1, 2, 3, 4], true) ? $scene : 3;
            $body = [
                'version' => 2,
                'openid' => $openid,
                'scene' => $scene,
                'content' => $content,
            ];
            if ($title !== '') {
                $body['title'] = mb_substr($title, 0, 100, 'UTF-8');
            }
        } else {
            $body = [
                'content' => $content,
            ];
        }

        $raw = $this->http_post_json($url, $body);
        if ($raw === false || $raw === '') {
            $this->log('msg_sec_check network fail');
            return dr_return_data(0, dr_lang('内容安全检测失败，请稍后重试'));
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            $this->log('msg_sec_check bad response: ' . substr((string)$raw, 0, 200));
            return dr_return_data(0, dr_lang('内容安全检测失败，请稍后重试'));
        }

        // access_token 失效：清缓存后提示重试（避免递归死循环）
        $errcode = (int)($json['errcode'] ?? 0);
        if (in_array($errcode, [40001, 42001], true)) {
            $this->clear_token_cache();
            $this->log('access_token expired errcode=' . $errcode);
            return dr_return_data(0, dr_lang('内容安全检测凭证失效，请重试'));
        }

        // 1.0：87014 表示违规
        if ($errcode === 87014) {
            return dr_return_data(0, self::RISK_TIP, ['risky' => true]);
        }

        if ($errcode !== 0) {
            $msg = (string)($json['errmsg'] ?? ('errcode=' . $errcode));
            $this->log('msg_sec_check err: ' . $msg);
            return dr_return_data(0, dr_lang('内容安全检测失败：%s', $msg));
        }

        // 2.0：看 result.suggest
        $suggest = strtolower((string)($json['result']['suggest'] ?? 'pass'));
        if (in_array($suggest, ['risky', 'review'], true)) {
            return dr_return_data(0, self::RISK_TIP, ['risky' => true, 'suggest' => $suggest]);
        }

        return dr_return_data(1, 'ok', $json);
    }

    /**
     * 调用微信图片内容安全识别
     *
     * @return array code=1 通过；code=0 拦截；data.risky=true 表示内容违规
     */
    public function img_sec_check(string $filepath): array
    {
        $filepath = (string)$filepath;
        if ($filepath === '' || !is_file($filepath)) {
            return dr_return_data(0, dr_lang('图片文件不存在'));
        }
        if (!$this->is_image_file($filepath)) {
            return dr_return_data(1, 'ok');
        }

        $originSize = (int)@filesize($filepath);
        if ($originSize > self::IMG_ACCEPT_MAX_BYTES) {
            return dr_return_data(0, dr_lang('图片超过 5M，无法进行内容安全检测'));
        }

        $tokenRt = $this->get_access_token();
        if (!(int)$tokenRt['code']) {
            return dr_return_data(0, (string)$tokenRt['msg']);
        }
        $accessToken = (string)$tokenRt['data']['access_token'];

        if (!function_exists('curl_init') || !class_exists('\CURLFile')) {
            return dr_return_data(0, dr_lang('服务器未启用 curl/CURLFile，无法检测图片'));
        }

        // 站点可上传至 5M；送检副本压缩到微信 1M / 750×1334，原文件不动
        $checkFile = $this->prepare_image_for_check($filepath);
        $cleanup = ($checkFile !== '' && $checkFile !== $filepath);
        if ($checkFile === '' || !is_file($checkFile)) {
            return dr_return_data(0, dr_lang('图片预处理失败，请稍后重试'));
        }
        if ((int)@filesize($checkFile) > self::IMG_MAX_BYTES) {
            if ($cleanup && is_file($checkFile)) {
                @unlink($checkFile);
            }
            return dr_return_data(0, dr_lang('图片压缩后仍超过微信 1M 限制，请更换图片'));
        }

        try {
            $url = 'https://api.weixin.qq.com/wxa/img_sec_check?access_token=' . urlencode($accessToken);
            $real = realpath($checkFile);
            if ($real === false) {
                return dr_return_data(0, dr_lang('图片路径无效'));
            }
            $mime = $this->image_mime($checkFile);
            $cfile = new \CURLFile($real, $mime, basename($checkFile));
            $raw = $this->http_post_multipart($url, ['media' => $cfile]);
            if ($raw === false || $raw === '') {
                $this->log('img_sec_check network fail');
                return dr_return_data(0, dr_lang('图片安全检测失败，请稍后重试'));
            }

            $json = json_decode((string)$raw, true);
            if (!is_array($json)) {
                $this->log('img_sec_check bad response: ' . substr((string)$raw, 0, 200));
                return dr_return_data(0, dr_lang('图片安全检测失败，请稍后重试'));
            }

            $errcode = (int)($json['errcode'] ?? 0);
            if (in_array($errcode, [40001, 42001], true)) {
                $this->clear_token_cache();
                $this->log('img access_token expired errcode=' . $errcode);
                return dr_return_data(0, dr_lang('内容安全检测凭证失效，请重试'));
            }

            if ($errcode === 87014) {
                return dr_return_data(0, self::RISK_TIP, ['risky' => true]);
            }

            if ($errcode !== 0) {
                $msg = (string)($json['errmsg'] ?? ('errcode=' . $errcode));
                $this->log('img_sec_check err: ' . $msg);
                return dr_return_data(0, dr_lang('图片安全检测失败：%s', $msg));
            }

            return dr_return_data(1, 'ok', $json);
        } finally {
            if ($cleanup && is_file($checkFile)) {
                @unlink($checkFile);
            }
        }
    }

    /** 获取小程序 access_token（须在本插件配置 AppID / AppSecret） */
    public function get_access_token(bool $force = false): array
    {
        if (!$force) {
            $cache = $this->read_token_cache();
            if ($cache && !empty($cache['access_token']) && (int)($cache['expire_at'] ?? 0) > time() + 60) {
                return dr_return_data(1, 'ok', [
                    'access_token' => (string)$cache['access_token'],
                ]);
            }
        }

        $cred = $this->resolve_xcx_credential();
        $appid = (string)($cred['appid'] ?? '');
        $secret = (string)($cred['secret'] ?? '');
        if ($appid === '' || $secret === '') {
            return dr_return_data(0, dr_lang('请先在本插件中配置小程序 AppID / AppSecret'));
        }

        $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential'
            . '&appid=' . urlencode($appid)
            . '&secret=' . urlencode($secret);

        $raw = dr_catcher_data($url, 10, true);
        if ($raw === false || $raw === '') {
            return dr_return_data(0, dr_lang('获取 access_token 失败，请检查服务器网络'));
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json) || empty($json['access_token'])) {
            $errmsg = is_array($json) ? (string)($json['errmsg'] ?? 'unknown') : 'invalid json';
            $this->log('get_access_token fail: ' . $errmsg);
            return dr_return_data(0, dr_lang('获取 access_token 失败：%s', $errmsg));
        }

        $token = (string)$json['access_token'];
        $expires = (int)($json['expires_in'] ?? 7200);
        $this->write_token_cache([
            'access_token' => $token,
            'expire_at' => time() + max(300, $expires - 200),
            'appid' => $appid,
        ]);

        return dr_return_data(1, 'ok', ['access_token' => $token]);
    }

    /** 解析上传钩子中的本地文件路径 */
    protected function resolve_upload_filepath(array $payload): string
    {
        $file = trim((string)($payload['file_path'] ?? ''));
        if ($file !== '' && is_file($file)) {
            return $file;
        }

        // type=upload 时 data 可能是临时文件路径
        if (($payload['type'] ?? '') === 'upload' && is_string($payload['data'] ?? null)) {
            $tmp = trim((string)$payload['data']);
            if ($tmp !== '' && is_file($tmp)) {
                return $tmp;
            }
        }

        return '';
    }

    /** 是否为支持检测的图片 */
    protected function is_image_file(string $filepath): bool
    {
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return true;
        }

        if (function_exists('exif_imagetype')) {
            $type = @exif_imagetype($filepath);
            $ok = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF];
            if (defined('IMAGETYPE_WEBP')) {
                $ok[] = IMAGETYPE_WEBP;
            }
            if (defined('IMAGETYPE_BMP')) {
                $ok[] = IMAGETYPE_BMP;
            }
            return in_array($type, $ok, true);
        }

        $info = @getimagesize($filepath);
        return is_array($info) && !empty($info[0]) && !empty($info[1]);
    }

    protected function image_mime(string $filepath): string
    {
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $map = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($filepath);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return 'image/jpeg';
    }

    /**
     * 按微信尺寸/大小要求预处理图片；超限则缩放压缩为临时 jpg
     *
     * @return string 用于检测的文件路径（可能是原文件或临时文件）
     */
    protected function prepare_image_for_check(string $filepath): string
    {
        $info = @getimagesize($filepath);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return $filepath;
        }

        $w = (int)$info[0];
        $h = (int)$info[1];
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $filesize = (int)@filesize($filepath);
        $needResize = ($w > self::IMG_MAX_W || $h > self::IMG_MAX_H);
        // 微信仅支持 png/jpeg/jpg/gif；webp/bmp 等需转换
        $needConvert = !in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true);
        $needCompress = ($filesize > self::IMG_MAX_BYTES);

        if (!$needResize && !$needConvert && !$needCompress) {
            return $filepath;
        }

        if (!function_exists('imagecreatetruecolor')) {
            $this->log('gd missing, skip prepare for ' . basename($filepath));
            return $filepath;
        }

        $src = $this->image_create_from_file($filepath, (int)($info[2] ?? 0));
        if (!$src) {
            return $filepath;
        }

        $dstW = $w;
        $dstH = $h;
        // 超尺寸或体积偏大时，统一压到微信建议分辨率，便于 5M 原图压进 1M
        if ($needResize || $needCompress) {
            $ratio = min(self::IMG_MAX_W / max($w, 1), self::IMG_MAX_H / max($h, 1), 1);
            $dstW = max(1, (int)floor($w * $ratio));
            $dstH = max(1, (int)floor($h * $ratio));
        }

        $dst = imagecreatetruecolor($dstW, $dstH);
        if (!$dst) {
            imagedestroy($src);
            return $filepath;
        }

        // 透明底转白底，统一输出 jpeg 便于接口识别与压到 1M 内
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $w, $h);
        imagedestroy($src);

        $dir = WRITEPATH . 'temp/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $dir . 'wxseccheck_' . md5($filepath . microtime(true)) . '.jpg';

        $ok = false;
        foreach ([85, 75, 65, 55, 45, 35] as $quality) {
            $ok = imagejpeg($dst, $tmp, $quality);
            if ($ok && is_file($tmp) && (int)@filesize($tmp) <= self::IMG_MAX_BYTES) {
                break;
            }
        }
        imagedestroy($dst);

        if (!$ok || !is_file($tmp)) {
            return $filepath;
        }

        // 质量降到最低仍超限：再缩小尺寸重试一次
        if ((int)@filesize($tmp) > self::IMG_MAX_BYTES) {
            $scaled = $this->shrink_jpeg_under_limit($tmp, $dstW, $dstH);
            if ($scaled !== '' && is_file($scaled)) {
                if ($scaled !== $tmp && is_file($tmp)) {
                    @unlink($tmp);
                }
                return $scaled;
            }
        }

        return $tmp;
    }

    /**
     * 将已生成的 jpg 继续缩小至 1M 内
     *
     * @return string 新临时文件路径；失败返回空串
     */
    protected function shrink_jpeg_under_limit(string $jpgPath, int $w, int $h): string
    {
        $src = @imagecreatefromjpeg($jpgPath);
        if (!$src) {
            return '';
        }

        $dir = WRITEPATH . 'temp/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $curW = max(1, $w);
        $curH = max(1, $h);
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $curW = max(1, (int)floor($curW * 0.75));
            $curH = max(1, (int)floor($curH * 0.75));
            $dst = imagecreatetruecolor($curW, $curH);
            if (!$dst) {
                break;
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $curW, $curH, imagesx($src), imagesy($src));
            $try = $dir . 'wxseccheck_shrink_' . md5($jpgPath . $i . microtime(true)) . '.jpg';
            $ok = imagejpeg($dst, $try, 70);
            imagedestroy($dst);
            if (!$ok || !is_file($try)) {
                continue;
            }
            if ($out !== '' && is_file($out)) {
                @unlink($out);
            }
            $out = $try;
            if ((int)@filesize($out) <= self::IMG_MAX_BYTES) {
                break;
            }
        }
        imagedestroy($src);

        return $out;
    }

    /** @return resource|\GdImage|false */
    protected function image_create_from_file(string $filepath, int $type)
    {
        if ($type === IMAGETYPE_JPEG) {
            return @imagecreatefromjpeg($filepath);
        }
        if ($type === IMAGETYPE_PNG) {
            return @imagecreatefrompng($filepath);
        }
        if ($type === IMAGETYPE_GIF) {
            return @imagecreatefromgif($filepath);
        }
        if (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($filepath);
        }
        if (defined('IMAGETYPE_BMP') && $type === IMAGETYPE_BMP && function_exists('imagecreatefrombmp')) {
            return @imagecreatefrombmp($filepath);
        }

        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            return @imagecreatefromjpeg($filepath);
        }
        if ($ext === 'png') {
            return @imagecreatefrompng($filepath);
        }
        if ($ext === 'gif') {
            return @imagecreatefromgif($filepath);
        }
        if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($filepath);
        }

        return false;
    }

    /** 中断上传并返回 JSON 错误 */
    protected function abort_upload(string $msg): void
    {
        try {
            exit(Service::C()->_json(0, $msg));
        } catch (\Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['code' => 0, 'msg' => $msg, 'data' => []], JSON_UNESCAPED_UNICODE));
        }
    }

    /** 当前模块目录名 */
    protected function current_module_dirname(): string
    {
        try {
            $module = Service::C()->module ?? null;
            if (is_array($module) && !empty($module['dirname'])) {
                return strtolower((string)$module['dirname']);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 兼容部分环境常量/函数
        if (function_exists('XR_C')) {
            try {
                $c = XR_C();
                if (is_object($c) && isset($c->module['dirname'])) {
                    return strtolower((string)$c->module['dirname']);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return '';
    }

    /** 拼接待检测文本；fields 留空则检测提交数据中的全部字段 */
    protected function build_check_text(array $data, array $config): string
    {
        $fields = $this->parse_list((string)($config['fields'] ?? ''));
        if ($fields) {
            $keys = $fields;
        } else {
            $keys = array_keys($data);
        }

        $parts = [];
        foreach ($keys as $field) {
            $field = (string)$field;
            if ($field === '' || !array_key_exists($field, $data)) {
                // 配置字段小写后，兼容原始大小写键名
                if ($fields) {
                    $found = false;
                    foreach ($data as $k => $_) {
                        if (strtolower((string)$k) === $field) {
                            $field = (string)$k;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        continue;
                    }
                } else {
                    continue;
                }
            }
            $value = $data[$field];
            if (is_array($value)) {
                $value = $this->array_to_text($value);
            } elseif (!is_scalar($value) && $value !== null) {
                continue;
            }
            $value = trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES, 'UTF-8'));
            $value = preg_replace('/\s+/u', ' ', $value);
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return trim(implode("\n", $parts));
    }

    /** 数组成员转文本 */
    protected function array_to_text(array $value): string
    {
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                foreach (['title', 'description', 'name', 'url', 'text'] as $k) {
                    if (!empty($item[$k]) && is_scalar($item[$k])) {
                        $out[] = (string)$item[$k];
                    }
                }
            } elseif (is_scalar($item)) {
                $out[] = (string)$item;
            }
        }

        return implode(' ', $out);
    }

    /** 解析会员 openid（小程序） */
    protected function resolve_openid(array $data): string
    {
        $uid = (int)($data['uid'] ?? 0);
        if ($uid <= 0) {
            try {
                $uid = (int)(Service::C()->uid ?? 0);
            } catch (\Throwable $e) {
                $uid = 0;
            }
        }
        if ($uid <= 0) {
            return '';
        }

        // member_oauth：小程序常见 oauth 标识
        $oauthNames = ['wechat', 'wx', 'xcx', 'mini', 'wxxcx', 'weixin'];
        try {
            foreach ($oauthNames as $oauth) {
                $row = Service::M()->table('member_oauth')
                    ->where('uid', $uid)
                    ->where('oauth', $oauth)
                    ->getRow();
                if ($row) {
                    $oid = (string)($row['oid'] ?? $row['openid'] ?? '');
                    if ($oid !== '') {
                        return $oid;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // 微信插件粉丝表（若存在）
        try {
            $fan = Service::M()->table(SITE_ID . '_weixin_user')
                ->where('uid', $uid)
                ->getRow();
            if ($fan && !empty($fan['openid'])) {
                return (string)$fan['openid'];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return '';
    }

    /** 解析本插件配置的小程序凭证（不读取其它插件） */
    protected function resolve_xcx_credential(): array
    {
        $config = $this->get_config();

        return [
            'appid' => trim((string)($config['xcx_appid'] ?? '')),
            'secret' => trim((string)($config['xcx_secret'] ?? '')),
        ];
    }

    /** 按字数切分（UTF-8） */
    protected function split_text(string $text, int $size): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text, 'UTF-8') <= $size) {
            return [$text];
        }

        $chunks = [];
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i += $size) {
            $chunks[] = mb_substr($text, $i, $size, 'UTF-8');
        }

        return $chunks;
    }

    /** 逗号/换行分隔列表 */
    protected function parse_list(string $raw): array
    {
        $parts = preg_split('/[\s,，;；|]+/u', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $item) {
            $item = strtolower(trim((string)$item));
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    /** POST JSON */
    protected function http_post_json(string $url, array $body)
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return false;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json; charset=utf-8',
                'Content-Length: ' . strlen($payload),
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $result = curl_exec($ch);
            curl_close($ch);

            return $result;
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json; charset=utf-8\r\n"
                    . 'Content-Length: ' . strlen($payload) . "\r\n",
                'content' => $payload,
                'timeout' => 15,
            ],
        ]);

        return @file_get_contents($url, false, $ctx);
    }

    /** POST multipart（含文件） */
    protected function http_post_multipart(string $url, array $fields)
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }

    protected function token_cache_file(): string
    {
        return WRITEPATH . 'cache/' . self::TOKEN_CACHE . '.php';
    }

    protected function read_token_cache(): ?array
    {
        $file = $this->token_cache_file();
        if (!is_file($file)) {
            return null;
        }
        $data = include $file;
        return is_array($data) ? $data : null;
    }

    protected function write_token_cache(array $data): void
    {
        $dir = WRITEPATH . 'cache/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $export = var_export($data, true);
        @file_put_contents($this->token_cache_file(), "<?php\nreturn " . $export . ";\n");
    }

    protected function clear_token_cache(): void
    {
        $file = $this->token_cache_file();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** 对外：配置变更时清理 access_token 缓存 */
    public function clear_access_token_cache(): void
    {
        $this->clear_token_cache();
    }

    protected function log(string $message): void
    {
        @file_put_contents(
            WRITEPATH . 'wxseccheck_log.txt',
            date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
