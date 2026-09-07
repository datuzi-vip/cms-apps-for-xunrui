<?php

/**
 * 短信宝发送钩子（$config 为系统自定义参数，本插件未使用）
 */

use Phpcmf\Service;

if (!function_exists('my_sendsms_code')) {
    /** 发送验证码 */
    function my_sendsms_code($mobile, $content, $config = ''): array
    {
        return Service::M('sms', 'smsbao')->send_code((string)$mobile, (string)$content);
    }
}

if (!function_exists('my_sendsms_text')) {
    /** 发送文本短信 */
    function my_sendsms_text($mobile, $content, $config = ''): array
    {
        return Service::M('sms', 'smsbao')->send_text((string)$mobile, (string)$content);
    }
}
