<?php namespace Phpcmf\Controllers\Admin;

use Phpcmf\App;
use Phpcmf\Service;

/**
 * 插件后台配置
 */
class Config extends App
{

    /** 设置页 */
    public function index(): void
    {
        $data = Service::M('app')->get_config(APP_DIR);
        if (!is_array($data)) {
            $data = [];
        }

        if (IS_AJAX_POST) {
            $post = Service::L('input')->post('data');
            if (!is_array($post)) {
                $this->_json(0, dr_lang('参数错误'));
            }

            $save = [
                'use' => isset($post['use']) && (string)$post['use'] === '1' ? '1' : '0',
                'use_image' => isset($post['use_image']) && (string)$post['use_image'] === '1' ? '1' : '0',
                'modules' => trim((string)($post['modules'] ?? '')),
                'fields' => trim((string)($post['fields'] ?? '')),
                'scene' => (string)(int)($post['scene'] ?? 3),
                'xcx_appid' => trim((string)($post['xcx_appid'] ?? '')),
                'xcx_secret' => trim((string)($post['xcx_secret'] ?? '')),
                'fail_open' => isset($post['fail_open']) && (string)$post['fail_open'] === '1' ? '1' : '0',
                'skip_admin' => isset($post['skip_admin']) && (string)$post['skip_admin'] === '1' ? '1' : '0',
            ];

            if ($save['xcx_appid'] === '' || $save['xcx_secret'] === '') {
                $this->_json(0, dr_lang('请填写小程序 AppID 和 AppSecret'));
            }

            if (!in_array((int)$save['scene'], [1, 2, 3, 4], true)) {
                $save['scene'] = '3';
            }

            $old = is_array($data) ? $data : [];
            Service::M('app')->save_config(APP_DIR, $save);

            // AppID/Secret 变更后清掉旧 token，避免沿用错误凭证
            if (
                (string)($old['xcx_appid'] ?? '') !== $save['xcx_appid']
                || (string)($old['xcx_secret'] ?? '') !== $save['xcx_secret']
            ) {
                Service::M('seccheck', APP_DIR)->clear_access_token_cache();
            }

            $this->_json(1, dr_lang('操作成功'));
        }

        $page = (int)Service::L('input')->get('page');

        Service::V()->assign([
            'page' => $page,
            'data' => array_merge([
                'use' => '1',
                'use_image' => '1',
                'modules' => '',
                'fields' => '',
                'scene' => '3',
                'xcx_appid' => '',
                'xcx_secret' => '',
                'fail_open' => '0',
                'skip_admin' => '0',
            ], $data),
            'form' => dr_form_hidden(['page' => $page]),
            'menu' => Service::M('auth')->_admin_menu(
                [
                    '插件设置' => [APP_DIR . '/' . Service::L('Router')->class . '/index', 'fa fa-cog'],
                ]
            ),
        ]);
        Service::V()->display('config.html');
    }

    /** 测试 access_token */
    public function token_index(): void
    {
        $rt = Service::M('seccheck', APP_DIR)->get_access_token(true);
        if ((int)$rt['code']) {
            $this->_json(1, dr_lang('access_token 获取成功'));
        }
        $this->_json(0, (string)$rt['msg']);
    }

    /** 测试一段文本 */
    public function test_index(): void
    {
        $content = trim((string)Service::L('input')->post('content'));
        if ($content === '') {
            $this->_json(0, dr_lang('请输入测试文本'));
        }

        $rt = Service::M('seccheck', APP_DIR)->msg_sec_check($content);
        if ((int)$rt['code']) {
            $this->_json(1, dr_lang('检测通过'));
        }

        $this->_json(0, (string)$rt['msg']);
    }

    /** 测试一张图片（兼容站点 5M 上传，送检前自动压到微信 1M） */
    public function test_image_index(): void
    {
        $file = $_FILES['image'] ?? null;
        if (!is_array($file)) {
            $this->_json(0, dr_lang('请选择要检测的图片'));
        }

        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $this->_json(0, dr_lang('图片过大，请不超过 5M'));
        }
        if ($err !== UPLOAD_ERR_OK) {
            $this->_json(0, dr_lang('请选择要检测的图片'));
        }

        $size = (int)($file['size'] ?? 0);
        $sec = Service::M('seccheck', APP_DIR);
        if ($size > $sec::IMG_ACCEPT_MAX_BYTES) {
            $this->_json(0, dr_lang('图片超过 5M，无法进行内容安全检测'));
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $this->_json(0, dr_lang('上传临时文件无效'));
        }

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            $this->_json(0, dr_lang('仅支持 jpg/png/gif/webp/bmp'));
        }

        $dir = WRITEPATH . 'temp/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $local = $dir . 'wxseccheck_test_' . md5(uniqid('', true)) . '.' . $ext;
        if (!@move_uploaded_file($tmp, $local)) {
            $this->_json(0, dr_lang('保存测试图片失败'));
        }

        try {
            $rt = $sec->img_sec_check($local);
            if ((int)$rt['code']) {
                $this->_json(1, dr_lang('检测通过'));
            }
            $this->_json(0, (string)$rt['msg']);
        } finally {
            if (is_file($local)) {
                @unlink($local);
            }
        }
    }
}
