<?php namespace Phpcmf\Model;

use Phpcmf\Model;
use Phpcmf\Service;

/**
 * 短信宝接口
 */
class Sms extends Model
{

    /** API 地址 */
    private const API_URL = 'https://api.smsbao.com';

    /** 默认验证码模板 */
    private const DEFAULT_CODE_TPL = '您的验证码是{code}，30分钟内有效。';

    /** 发送验证码 */
    public function send_code(string $mobile, string $code): array
    {
        $config = $this->get_config();
        $tpl = trim((string)($config['code_tpl'] ?? ''));
        if ($tpl === '') {
            $tpl = self::DEFAULT_CODE_TPL;
        }
        $content = str_replace('{code}', $code, $tpl);

        return $this->send($mobile, $content, $config);
    }

    /** 读取插件配置 */
    public function get_config(): array
    {
        $config = Service::M('app')->get_config('smsbao');

        return is_array($config) ? $config : [];
    }

    /** 请求发送接口 */
    protected function send(string $mobile, string $content, array $config): array
    {
        $mobile = trim($mobile);
        $content = trim($content);

        if ($mobile === '' || $content === '') {
            return dr_return_data(0, dr_lang('手机号码或内容不能为空'));
        }

        $user = trim((string)($config['user'] ?? ''));
        $pass = trim((string)($config['pass'] ?? ''));
        if ($user === '' || $pass === '') {
            $error = dr_lang('短信宝账号或密码未配置');
            $this->log($mobile, $error, $content);

            return dr_return_data(0, $error);
        }

        $content = $this->format_content($content, (string)($config['sign'] ?? ''));
        $url = self::API_URL . '/sms?u=' . urlencode($user)
            . '&p=' . $this->password($pass)
            . '&m=' . urlencode($mobile)
            . '&c=' . urlencode($content);

        $result = dr_catcher_data($url, 10, true);
        if ($result === '' || $result === false) {
            $error = dr_lang('短信接口请求失败，请检查服务器网络');
            $this->log($mobile, $error, $content);

            return dr_return_data(0, $error);
        }

        $result = trim((string)$result);
        if ($result === '0') {
            return dr_return_data(1, dr_lang('发送成功'));
        }

        $error = $this->error_msg($result);
        $this->log($mobile, $error, $content);

        return dr_return_data(0, $error);
    }

    /** 记录发送日志 */
    protected function log(string $mobile, string $error, string $content): void
    {
        $content = str_replace(["\r", "\n"], '', $content);
        @file_put_contents(
            WRITEPATH . 'sms_log.txt',
            date('Y-m-d H:i:s') . ' [' . $mobile . '] [' . $error . '] （' . $content . '）' . PHP_EOL,
            FILE_APPEND
        );
    }

    /** 拼接短信签名 */
    protected function format_content(string $content, string $sign): string
    {
        $sign = trim($sign);
        if ($sign === '' || str_starts_with($content, $sign)) {
            return $content;
        }

        return $sign . $content;
    }

    /** 密码处理（明文 MD5，或直接使用 32 位 ApiKey） */
    protected function password(string $pass): string
    {
        $pass = trim($pass);
        if ($pass === '') {
            return '';
        }

        if (preg_match('/^[a-fA-F0-9]{32}$/', $pass)) {
            return strtolower($pass);
        }

        return md5($pass);
    }

    /** 错误码说明 */
    public function error_msg(string $code): string
    {
        $map = [
            '-1' => '参数不全',
            '-2' => '服务器空间不支持curl或fsocket',
            '30' => '密码错误',
            '40' => '账号不存在',
            '41' => '余额不足',
            '42' => '账户已过期',
            '43' => 'IP地址限制',
            '50' => '内容含有敏感词',
            '51' => '手机号码不正确',
        ];

        $code = trim($code);

        return $map[$code] ?? ('发送失败，错误码：' . $code);
    }

    /** 发送文本短信 */
    public function send_text(string $mobile, string $content): array
    {
        return $this->send($mobile, $content, $this->get_config());
    }

    /** 查询剩余条数 */
    public function query_balance(string $user = '', string $pass = ''): array
    {
        $config = $this->get_config();
        $user = $user !== '' ? $user : (string)($config['user'] ?? '');
        $pass = $pass !== '' ? $pass : (string)($config['pass'] ?? '');

        if ($user === '' || $pass === '') {
            return dr_return_data(0, dr_lang('账号或密码不能为空'));
        }

        $url = self::API_URL . '/query?u=' . urlencode($user) . '&p=' . $this->password($pass);
        $result = dr_catcher_data($url, 10, true);

        if ($result === '' || $result === false) {
            return dr_return_data(0, dr_lang('查询失败，请检查网络'));
        }

        $lines = preg_split('/\r\n|\r|\n/', trim((string)$result)) ?: [];
        if (trim((string)($lines[0] ?? '')) !== '0') {
            return dr_return_data(0, $this->error_msg((string)($lines[0] ?? '')));
        }

        $balance = explode(',', trim((string)($lines[1] ?? '0,0')));
        $remain = (int)($balance[1] ?? 0);

        return dr_return_data(1, dr_lang('剩余短信：%s 条', $remain));
    }
}
