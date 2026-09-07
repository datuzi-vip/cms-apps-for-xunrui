<?php

/**
 * 微信内容安全：文本入库前检测 + 图片上传后检测
 *
 * 钩子文档：
 * - module_content_before https://www.xunruicms.com/doc/370.html
 * - upload_file https://help.xunruicms.com/978.html
 * 微信接口：security.msgSecCheck / security.imgSecCheck
 */

use Phpcmf\Service;

\Phpcmf\Hooks::app_on('wxseccheck', 'module_content_before', function ($data) {
    return Service::M('seccheck', 'wxseccheck')->check_before_publish($data);
});

\Phpcmf\Hooks::app_on('wxseccheck', 'upload_file', function ($payload) {
    Service::M('seccheck', 'wxseccheck')->check_after_upload($payload);
});
