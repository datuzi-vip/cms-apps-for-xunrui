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

            Service::M('app')->save_config(APP_DIR, $post);

            // 确保系统短信配置文件存在，否则框架不会走第三方钩子
            $file = WRITEPATH . 'config/sms.php';
            if (!is_file($file)) {
                if (!Service::L('Config')->file($file, '短信配置文件')->to_require_one([])) {
                    $this->_json(0, dr_lang('配置文件写入失败'));
                }
            }

            $this->_json(1, dr_lang('操作成功'));
        }

        $page = (int)Service::L('input')->get('page');

        Service::V()->assign([
            'page' => $page,
            'data' => $data,
            'form' => dr_form_hidden(['page' => $page]),
            'menu' => Service::M('auth')->_admin_menu(
                [
                    '插件设置' => [APP_DIR . '/' . Service::L('Router')->class . '/index', 'fa fa-cog'],
                ]
            ),
        ]);
        Service::V()->display('config.html');
    }

    /** 查询余额 */
    public function balance_index(): void
    {
        $rt = Service::M('sms', APP_DIR)->query_balance();
        $this->_json((int)$rt['code'], (string)$rt['msg']);
    }
}
